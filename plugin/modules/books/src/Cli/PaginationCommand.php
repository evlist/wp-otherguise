<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The WP-CLI command that prints on which page of a PDF each unit of a section starts.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Cli;

use Otherguise\Books\Pdf\PdfMarkers;
use Otherguise\Books\Section\Sections;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise books pagination <section> --file=<pdf>` (slice 227): reads the marker links of a PDF made from the merged page of a section and
 * prints the page where each unit starts. Reads the file and the order of the section, writes nothing: it can be run twice.
 */
final class PaginationCommand {
	/**
	 * Deepest level of sections followed (the same as the merge).
	 */
	private const DEPTH = 6;

	/**
	 * The sections.
	 *
	 * @var Sections
	 */
	private $sections;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Prints a success.
	 *
	 * @var callable
	 */
	private $success;

	/**
	 * Stops with an error.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Prints a warning.
	 *
	 * @var callable
	 */
	private $warning;

	/**
	 * Returns the title of a post.
	 *
	 * @var callable
	 */
	private $title;

	/**
	 * Reads a manifest: receives its key, returns the JSON or null.
	 *
	 * @var callable
	 */
	private $manifest;

	/**
	 * Builds the command.
	 *
	 * @param Sections      $sections The sections (with a store that trusts the command line).
	 * @param callable|null $log      Default `WP_CLI::log`.
	 * @param callable|null $success  Default `WP_CLI::success`.
	 * @param callable|null $fail     Default `WP_CLI::error`.
	 * @param callable|null $warning  Default `WP_CLI::warning`.
	 * @param callable|null $title    Receives a post id; default `get_the_title`.
	 * @param callable|null $manifest Receives the key of a manifest, returns its JSON or null; default: none are found.
	 */
	public function __construct( Sections $sections, $log = null, $success = null, $fail = null, $warning = null, $title = null, $manifest = null ) {
		$this->manifest = $manifest ?? static fn() => null;
		$this->sections = $sections;
		$this->log      = $log ?? array( 'WP_CLI', 'log' );
		$this->success  = $success ?? array( 'WP_CLI', 'success' );
		$this->fail     = $fail ?? array( 'WP_CLI', 'error' );
		$this->warning  = $warning ?? array( 'WP_CLI', 'warning' );
		$this->title    = $title ?? static fn( $id ) => self::plain( (string) get_the_title( (int) $id ) );
	}

	/**
	 * A title as plain text for a terminal: `get_the_title()` gives HTML (`&#8211;`, `&rsquo;`), which is not read on a command line.
	 *
	 * @param string $title Title as WordPress gives it.
	 * @return string
	 */
	public static function plain( $title ) {
		return html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Prints the page where each unit of a section starts in a PDF.
	 *
	 * The PDF is the one made from the merged page of the section (the block "Merge the units of the section"): each heading of a unit and of the
	 * page of the section has a marker that Chromium writes in the file. The page of a unit is the page of its first heading; a unit with no heading
	 * (or whose headings are not printed) has no page. A PDF that went through a tool that rewrites the objects may have lost the markers.
	 *
	 * ## OPTIONS
	 *
	 * <section>
	 * : Id of the section.
	 *
	 * --file=<path>
	 * : The PDF file.
	 *
	 * [--check]
	 * : Also report the units of the section without a page (merged without a heading, or not merged), the markers found on several pages, the headings of a post that is not in the section, and the levels of the headings (a level that is skipped, a heading above the first one of its post, a heading with no text).
	 *
	 * [--headings]
	 * : Also print the page of every heading of every unit and of the page of the section itself (listed with the id of the section): the id, `#` and the number of the heading, then the page.
	 *
	 * [--format=<format>]
	 * : `table` (default: one line per unit, tab separated: the id, the page, the title) or `json`.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$section = isset( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : 0;
		$format  = $assoc_args['format'] ?? 'table';
		$file    = isset( $assoc_args['file'] ) && is_string( $assoc_args['file'] ) ? $assoc_args['file'] : '';

		if ( $section <= 0 || ! $this->sections->is_section( $section ) ) {
			( $this->fail )( 'Not a section: give the id of a section.' );
			return;
		}

		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			( $this->fail )( '--format: table or json.' );
			return;
		}

		if ( '' === $file || ! is_file( $file ) ) {
			( $this->fail )( '--file: the path of a PDF file.' );
			return;
		}

		$read = PdfMarkers::read( $file );

		if ( null === $read ) {
			( $this->fail )( 'No page found in this file: is it a PDF made by Chromium?' );
			return;
		}

		$headings = array();
		$repeats  = array();
		$manifest = null;

		foreach ( $read['markers'] as $name => $pages ) {
			if ( 1 === preg_match( '#^heading/(\d+)/(\d+)$#', $name, $found ) ) {
				$headings[ (int) $found[1] ][ (int) $found[2] ] = $pages[0];

				if ( count( $pages ) > 1 ) {
					$repeats[ $found[1] . '#' . $found[2] ] = $pages;
				}
			} elseif ( 1 === preg_match( '#^manifest/([0-9a-f]{32})$#', $name, $found ) ) {
				$manifest = $this->manifest( $found[1] );
			}
		}

		// The page of a unit is the page of its first heading.
		$starts = array();

		foreach ( $headings as $id => $list ) {
			ksort( $list );
			$headings[ $id ] = $list;
			$starts[ $id ]   = reset( $list );
		}

		$expected = $this->expected( $section );
		$rows     = array();

		foreach ( $starts as $id => $page ) {
			$rows[] = array(
				'id'    => $id,
				'page'  => $page,
				'title' => ( $this->title )( $id ),
			);
		}

		usort( $rows, static fn( $a, $b ) => array( $a['page'], $a['id'] ) <=> array( $b['page'], $b['id'] ) );

		if ( isset( $assoc_args['headings'] ) ) {
			foreach ( $rows as $index => $row ) {
				$rows[ $index ]['headings'] = $this->described( $headings[ $row['id'] ] ?? array(), $row['id'], $manifest['headings'] ?? null );
			}
		}

		if ( 'json' === $format ) {
			( $this->log )(
				(string) wp_json_encode(
					array(
						'pages' => $read['pages'],
						'units' => $rows,
					)
				)
			);
		} else {
			foreach ( $rows as $row ) {
				( $this->log )( $row['id'] . "\t" . $row['page'] . "\t" . $row['title'] );

				foreach ( $row['headings'] ?? array() as $number => $heading ) {
					$line = $row['id'] . '#' . $number . "\t" . ( is_array( $heading ) ? $heading['page'] . "\th" . $heading['level'] . "\t" . $heading['text'] . ( $heading['toc'] ? '' : "\t(no-toc)" ) : $heading );
					( $this->log )( $line );
				}
			}
		}

		if ( isset( $assoc_args['headings'] ) && null === $manifest && array() !== $headings ) {
			( $this->warning )( 'No manifest for this PDF (made before the manifests, or removed): the level and the text of the headings are not shown.' );
		}

		// The headings of the page of the section itself are listed with its id; it is not one of its units.
		$units = count( array_diff_key( $starts, array( $section => true ) ) );

		if ( array() === $starts ) {
			( $this->fail )( 'No marker in this file: it was not made from a merged page, or the objects of the PDF were rewritten.' );
			return;
		}

		if ( ! isset( $assoc_args['check'] ) ) {
			( $this->success )( $units . ' units in ' . $read['pages'] . ' pages.' );
			return;
		}

		$problems = 0;
		$merged   = null === $manifest ? null : $manifest['units'];

		foreach ( array_diff( $expected, array_keys( $starts ) ) as $id ) {
			++$problems;

			if ( null === $merged ) {
				( $this->warning )( 'No heading marker for the unit ' . $id . ' (a draft, a post with a password, a unit left out by a limit, or a unit with no heading).' );
			} elseif ( in_array( $id, $merged, true ) ) {
				( $this->warning )( 'The unit ' . $id . ' is in the PDF but none of its headings was found: it has no page (no heading, or the headings are not printed).' );
			} else {
				( $this->warning )( 'The unit ' . $id . ' is not in the PDF (a draft, a post with a password, or a unit left out by a limit).' );
			}
		}

		// The first heading of a unit is not printed (hidden by the print stylesheet, say): the page given is that of a later heading.
		foreach ( $manifest['headings'] ?? array() as $id => $list ) {
			$first = min( array_keys( $list ) );

			if ( isset( $headings[ $id ] ) && ! isset( $headings[ $id ][ $first ] ) ) {
				++$problems;
				( $this->warning )( 'The first heading of the post ' . $id . ' (n° ' . $first . ') is not in the PDF: the page given for it is the page of its heading n° ' . min( array_keys( $headings[ $id ] ) ) . '.' );
			}
		}

		foreach ( $repeats as $name => $pages ) {
			++$problems;
			( $this->warning )( 'The marker of the heading ' . $name . ' is on several pages: ' . implode( ', ', $pages ) . '.' );
		}

		foreach ( array_diff( array_keys( $starts ), $expected, array( $section ) ) as $id ) {
			++$problems;
			( $this->warning )( 'The headings of the post ' . $id . ', which is not in the section.' );
		}

		foreach ( $this->level_problems( $manifest['headings'] ?? null ) as $problem ) {
			++$problems;
			( $this->warning )( $problem );
		}

		if ( 0 === $problems ) {
			( $this->success )( 'All ' . count( $expected ) . ' units of the section have a page, from their headings.' );
			return;
		}

		( $this->success )( $units . ' units in ' . $read['pages'] . ' pages, ' . $problems . ' to look at.' );
	}

	/**
	 * Reads the manifest of a PDF: the headings by unit and by number, with their level, their text and whether a table of contents lists them.
	 *
	 * @param string $key Key of the manifest.
	 * @return array{headings:array<int, array<int, array>>, units:int[]}|null `headings` by unit and by number, `units`: the ids of the units that were merged; null when there is none or it is not readable.
	 */
	private function manifest( $key ) {
		$json = ( $this->manifest )( $key );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;

		if ( ! is_array( $data ) || ! isset( $data['headings'] ) || ! is_array( $data['headings'] ) ) {
			return null;
		}

		$by_unit = array();

		foreach ( $data['headings'] as $row ) {
			if ( is_array( $row ) && isset( $row['unit'], $row['n'], $row['level'] ) ) {
				$by_unit[ (int) $row['unit'] ][ (int) $row['n'] ] = array(
					'level' => (int) $row['level'],
					'text'  => (string) ( $row['text'] ?? '' ),
					'toc'   => ! isset( $row['toc'] ) || (bool) $row['toc'],
					'depth' => (int) ( $row['depth'] ?? 0 ),
				);
			}
		}

		$units = array();

		foreach ( (array) ( $data['units'] ?? array() ) as $unit ) {
			if ( is_array( $unit ) && isset( $unit['id'] ) ) {
				$units[] = (int) $unit['id'];
			}
		}

		return array(
			'headings' => $by_unit,
			'units'    => $units,
		);
	}

	/**
	 * The pages of the headings of a unit, with their level and their text when the manifest has them.
	 *
	 * @param array<int, int>                    $pages    The page of each heading, by number.
	 * @param int                                $unit     Id of the unit.
	 * @param array<int, array<int, array>>|null $manifest The manifest.
	 * @return array<int, int|array>
	 */
	private function described( array $pages, $unit, $manifest ) {
		if ( null === $manifest ) {
			return $pages;
		}

		$out = array();

		foreach ( $pages as $number => $page ) {
			$known = $manifest[ $unit ][ $number ] ?? null;

			$out[ $number ] = null === $known ? $page : array(
				'page'  => $page,
				'level' => $known['level'],
				'text'  => $known['text'],
				'toc'   => $known['toc'],
			);
		}

		return $out;
	}

	/**
	 * What is wrong with the levels of the headings, unit by unit, in the order of the document: a level that is skipped (an `h4` right after an
	 * `h2`), a heading above the first one of its unit (its title), a heading with no text. The headings that a table of contents leaves out (`no-toc`)
	 * are not looked at.
	 *
	 * @param array<int, array<int, array>>|null $manifest The manifest.
	 * @return string[]
	 */
	private function level_problems( $manifest ) {
		if ( null === $manifest ) {
			return array();
		}

		$problems = array();

		foreach ( $manifest as $unit => $list ) {
			ksort( $list );

			$first = null;
			$last  = null;

			foreach ( $list as $number => $heading ) {
				if ( ! $heading['toc'] ) {
					continue;
				}

				$name = 'The heading ' . $number . ' (h' . $heading['level'] . ') of the post ' . $unit;

				if ( '' === $heading['text'] ) {
					$problems[] = $name . ' has no text.';
				}

				if ( null !== $last && $heading['level'] > $last + 1 ) {
					$problems[] = $name . ' follows an h' . $last . ': a level is skipped.';
				}

				if ( null !== $first && $heading['level'] < $first ) {
					$problems[] = $name . ' is above the first heading of the post (h' . $first . ').';
				}

				$first = $first ?? $heading['level'];
				$last  = $heading['level'];
			}
		}

		return $problems;
	}

	/**
	 * The units that a merge of the section would follow, in order: the units, each followed by its own units, six levels at most.
	 *
	 * @param int $section Id of the section.
	 * @return int[]
	 */
	private function expected( $section ) {
		$ids = array();
		$this->walk( $section, array( $section => true ), 1, $ids );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Walks the section.
	 *
	 * @param int              $section Id of the section.
	 * @param array<int, bool> $seen    Sections already followed.
	 * @param int              $depth   Level.
	 * @param int[]            $ids     The ids found (modified).
	 * @return void
	 */
	private function walk( $section, array $seen, $depth, array &$ids ) {
		foreach ( $this->sections->order( $section ) as $id ) {
			$ids[] = (int) $id;

			if ( $depth < self::DEPTH && ! isset( $seen[ $id ] ) && array() !== $this->sections->order( (int) $id ) ) {
				$this->walk( (int) $id, $seen + array( $id => true ), $depth + 1, $ids );
			}
		}
	}
}
