<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The markers of a PDF file and the page each one is on.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the **marker links** of a PDF (slice 227): in the merged HTML of a section each unit starts with an invisible link to
 * `https://marker.invalid/unit/<post id>`; Chromium writes it as a link annotation (`/Type /Annot /Subtype /Link`, action `/URI`) listed in the
 * `/Annots` of the page where the link is. The file is read **in pieces** (like `PdfPages`, slice 226), no library and no `shell_exec`: only the
 * page objects, the page tree and the small link annotations are kept, never the streams. It is right for the files that Chromium writes (objects
 * that are not compressed, keys in its order); it gives `null` for a file without a page tree, and an empty list of markers when it finds none
 * (another producer, an object stream, a PDF that went through a tool that rewrites the objects), and the caller then says so.
 */
final class PdfMarkers {
	/**
	 * The host of the address of a marker: a name reserved by RFC 2606, that never leads anywhere.
	 */
	public const HOST = 'marker.invalid';

	/**
	 * Bytes read at a time.
	 */
	public const CHUNK = 4194304;

	/**
	 * Bytes of the next piece that are read too, so that an object cut by the end of a piece is whole in one of them (the largest object kept is a
	 * node of the page tree or a page dictionary).
	 */
	public const OVERLAP = 16384;

	/**
	 * The address of a marker.
	 *
	 * @param string $name Name of the marker (`unit/12`).
	 * @return string
	 */
	public static function address( $name ) {
		return 'https://' . self::HOST . '/' . $name;
	}

	/**
	 * Reads a file.
	 *
	 * @param string $path  Path of the file.
	 * @param int    $chunk Bytes read at a time (the tests make it small).
	 * @return array{pages:int, markers:array<string, int[]>}|null `markers`: the name of a marker (`unit/12`) => the pages (from 1) where it is found, in order;
	 *                                                              null when the file cannot be read or has no page.
	 */
	public static function read( $path, $chunk = self::CHUNK ) {
		if ( ! is_string( $path ) || ! is_file( $path ) || $chunk < 1 ) {
			return null;
		}

		$size   = (int) filesize( $path );
		$pages  = array();
		$nodes  = array();
		$links  = array();
		$orders = 0;

		for ( $offset = 0; $offset < $size; $offset += $chunk ) {
			$piece = file_get_contents( $path, false, null, $offset, $chunk + self::OVERLAP );

			if ( false === $piece ) {
				return null;
			}

			self::collect( $piece, $chunk, $pages, $nodes, $links, $orders );
		}

		if ( array() === $pages ) {
			return null;
		}

		$order   = self::order( $pages, $nodes );
		$numbers = array_flip( $order );
		$markers = array();

		foreach ( $order as $object ) {
			foreach ( $pages[ $object ]['annots'] as $annot ) {
				if ( isset( $links[ $annot ] ) ) {
					$markers[ $links[ $annot ] ][] = $numbers[ $object ] + 1;
				}
			}
		}

		return array(
			'pages'   => count( $order ),
			'markers' => $markers,
		);
	}

	/**
	 * Collects the page objects, the nodes of the page tree and the marker links of a piece. An object that starts in the overlap is read with the
	 * next piece.
	 *
	 * @param string                                      $piece  The piece.
	 * @param int                                         $chunk  Bytes of the piece that are its own.
	 * @param array<int, array{annots:int[], parent:int}> $pages  The page objects by number (modified).
	 * @param array<int, array{kids:int[], parent:int}>   $nodes  The nodes of the page tree by number (modified).
	 * @param array<int, string>                          $links  The names of the markers by number of the annotation (modified).
	 * @param int                                         $orders The page objects seen, to keep the order of the file (modified).
	 * @return void
	 */
	private static function collect( $piece, $chunk, array &$pages, array &$nodes, array &$links, &$orders ) {
		$head = '(\d+)\s+\d+\s+obj\s*<<\s*';

		if ( false !== preg_match_all( '/' . $head . '\/Type\s*\/Page(?![A-Za-z])/', $piece, $found, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $found[0] as $index => $match ) {
				if ( $match[1] >= $chunk ) {
					continue;
				}

				$body = self::body( $piece, $match[1] );

				preg_match( '/\/Annots\s*\[([^\]]*)\]/', $body, $annots );
				preg_match( '/\/Parent\s+(\d+)/', $body, $parent );

				$pages[ (int) $found[1][ $index ][0] ] = array(
					'annots' => isset( $annots[1] ) ? self::references( $annots[1] ) : array(),
					'parent' => (int) ( $parent[1] ?? 0 ),
					'seen'   => $orders++,
				);
			}
		}

		if ( false !== preg_match_all( '/' . $head . '\/Type\s*\/Pages(?![A-Za-z])/', $piece, $found, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $found[0] as $index => $match ) {
				if ( $match[1] >= $chunk ) {
					continue;
				}

				$body = self::body( $piece, $match[1] );

				preg_match( '/\/Kids\s*\[([^\]]*)\]/', $body, $kids );
				preg_match( '/\/Parent\s+(\d+)/', $body, $parent );

				$nodes[ (int) $found[1][ $index ][0] ] = array(
					'kids'   => isset( $kids[1] ) ? self::references( $kids[1] ) : array(),
					'parent' => (int) ( $parent[1] ?? 0 ),
				);
			}
		}

		if ( false !== preg_match_all( '/' . $head . '\/Type\s*\/Annot\s*\/Subtype\s*\/Link/', $piece, $found, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $found[0] as $index => $match ) {
				if ( $match[1] >= $chunk ) {
					continue;
				}

				$body = self::body( $piece, $match[1] );
				$mark = '/\/URI\s*\(https:\/\/' . preg_quote( self::HOST, '/' ) . '\/([^)]*)\)/';

				if ( 1 === preg_match( $mark, $body, $uri ) ) {
					$links[ (int) $found[1][ $index ][0] ] = $uri[1];
				}
			}
		}
	}

	/**
	 * The text of an object, from its start to `endobj` (or the end of the piece).
	 *
	 * @param string $piece  The piece.
	 * @param int    $offset Where the object starts.
	 * @return string
	 */
	private static function body( $piece, $offset ) {
		$end = strpos( $piece, 'endobj', $offset );

		return false === $end ? substr( $piece, $offset ) : substr( $piece, $offset, $end - $offset );
	}

	/**
	 * The object numbers of a list of references (`6 0 R 7 0 R`).
	 *
	 * @param string $list The text of the list.
	 * @return int[]
	 */
	private static function references( $list ) {
		return false === preg_match_all( '/(\d+)\s+\d+\s+R/', $list, $found ) ? array() : array_map( 'intval', $found[1] );
	}

	/**
	 * The page objects in the order of the page tree; the order of the file when the tree is not whole.
	 *
	 * @param array<int, array{annots:int[], parent:int, seen:int}> $pages The page objects.
	 * @param array<int, array{kids:int[], parent:int}>             $nodes The nodes of the tree.
	 * @return int[]
	 */
	private static function order( array $pages, array $nodes ) {
		$by_file = $pages;

		uasort( $by_file, static fn( $a, $b ) => $a['seen'] <=> $b['seen'] );

		$file_order = array_keys( $by_file );
		$roots      = array();

		foreach ( $nodes as $number => $node ) {
			if ( 0 === $node['parent'] || ! isset( $nodes[ $node['parent'] ] ) ) {
				$roots[] = $number;
			}
		}

		if ( 1 !== count( $roots ) ) {
			return $file_order;
		}

		$order   = array();
		$visited = array();
		$stack   = array( $roots[0] );

		// Depth first, in the order of the kids; the stack holds the next ones to visit.
		while ( array() !== $stack ) {
			$number = array_shift( $stack );

			if ( isset( $visited[ $number ] ) ) {
				continue;
			}

			$visited[ $number ] = true;

			if ( isset( $pages[ $number ] ) ) {
				$order[] = $number;
			} elseif ( isset( $nodes[ $number ] ) ) {
				$stack = array_merge( $nodes[ $number ]['kids'], $stack );
			}
		}

		return count( $order ) === count( $pages ) ? $order : $file_order;
	}
}
