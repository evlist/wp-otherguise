<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The passes of the conversion of a section that has a table of contents with page numbers.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the PDF of a section in as many passes as its tables of contents need (slice 228): the page of an entry is only known once the PDF is made.
 *
 * The first pass prints placeholders where the numbers go. When the manifest of that PDF says that it has a table of contents with numbers, the job
 * reads where each heading is (`PdfMarkers`), keeps the map in the store and converts again with its key in the address (`books_pages`); the pass
 * is good when the map of the PDF it made is the one it was given: the numbers that it printed are the pages. Otherwise it goes again, three passes
 * at most, and the PDF is kept with a note that the pagination did not settle.
 */
final class Passes {
	/**
	 * The most passes.
	 */
	public const MAX = 3;

	/**
	 * The store.
	 *
	 * @var PdfStore
	 */
	private $store;

	/**
	 * Builds the passes.
	 *
	 * @param PdfStore $store Where the manifests and the maps of the pages are kept.
	 */
	public function __construct( PdfStore $store ) {
		$this->store = $store;
	}

	/**
	 * Makes the PDF.
	 *
	 * @param callable                $convert  Receives the key of the map of the pages ('' for none) and makes the PDF in `$path`; returns true or an error (a `WP_Error`, a string).
	 * @param string                  $path     Path where the PDF is made (each pass writes over the one before).
	 * @param bool                    $numbered Whether the tables of contents are numbered (else one pass).
	 * @param int                     $max      The most passes.
	 * @param array<string, int>|null $seed The map of the pages of an earlier PDF of the same book: the first pass prints it instead of placeholders, and if the pagination did not move that pass is the last (a small change costs one pass, not two; a large one costs the pass that would have been needed anyway).
	 * @return array{ok:bool, error:string, passes:int, stable:bool, map:array<string, int>|null} `ok`: the PDF is made; `stable`: its numbers are the pages (true when there are none to settle); `map`: the pages of its headings when it has a table with numbers, to keep as the seed of the next.
	 */
	public function run( $convert, $path, $numbered, $max = self::MAX, $seed = null ) {
		$key      = '';
		$previous = null;

		if ( $numbered && is_array( $seed ) && array() !== $seed ) {
			$json = wp_json_encode( $seed );

			if ( is_string( $json ) && $this->store->write_manifest( md5( $json ), $json ) ) {
				$key      = md5( $json );
				$previous = $seed;
			}
		}

		for ( $pass = 1; $pass <= max( 1, (int) $max ); ++$pass ) {
			$result = $convert( $key );

			if ( true !== $result ) {
				return array(
					'ok'     => false,
					'error'  => $result instanceof \WP_Error ? $result->get_error_message() : (string) $result,
					'passes' => $pass,
					'stable' => false,
					'map'    => null,
				);
			}

			if ( ! $numbered ) {
				return $this->done( $pass, true, null );
			}

			$map = $this->map( $path );

			if ( null === $map ) {
				// No table of contents with numbers in this PDF: nothing to settle.
				return $this->done( $pass, true, null );
			}

			if ( $map === $previous ) {
				return $this->done( $pass, true, $map );
			}

			$json = wp_json_encode( $map );

			if ( $pass >= $max || ! is_string( $json ) || ! $this->store->write_manifest( md5( $json ), $json ) ) {
				return $this->done( $pass, false, $map );
			}

			$key      = md5( $json );
			$previous = $map;
		}

		return $this->done( (int) $max, false, $previous );
	}

	/**
	 * The page of each heading of a PDF (`<post>#<n>` => page), or null when the PDF has no table of contents with numbers to settle: no manifest, a
	 * manifest without one.
	 *
	 * @param string $path Path of the PDF.
	 * @return array<string, int>|null
	 */
	private function map( $path ) {
		$read = PdfMarkers::read( $path );

		if ( null === $read ) {
			return null;
		}

		$tocs = 0;
		$map  = array();

		foreach ( $read['markers'] as $name => $pages ) {
			if ( 1 === preg_match( '#^manifest/([0-9a-f]{32})$#', $name, $found ) ) {
				$manifest = json_decode( (string) $this->store->read_manifest( $found[1] ), true );
				$tocs     = is_array( $manifest ) ? (int) ( $manifest['tocs'] ?? 0 ) : 0;
			} elseif ( 1 === preg_match( '#^heading/(\d+)/(\d+)$#', $name, $found ) ) {
				$map[ $found[1] . '#' . $found[2] ] = $pages[0];
			}
		}

		if ( $tocs < 1 ) {
			return null;
		}

		ksort( $map );

		return $map;
	}

	/**
	 * The answer of a PDF that is made.
	 *
	 * @param int                     $passes The passes that were made.
	 * @param bool                    $stable Whether the numbers are the pages.
	 * @param array<string, int>|null $map    The pages of the headings, null when there is no table with numbers.
	 * @return array{ok:bool, error:string, passes:int, stable:bool, map:array<string, int>|null}
	 */
	private function done( $passes, $stable, $map ) {
		return array(
			'ok'     => true,
			'error'  => '',
			'passes' => $passes,
			'stable' => $stable,
			'map'    => $map,
		);
	}
}
