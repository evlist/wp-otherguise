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
	 * Builds the command.
	 *
	 * @param Sections      $sections The sections (with a store that trusts the command line).
	 * @param callable|null $log      Default `WP_CLI::log`.
	 * @param callable|null $success  Default `WP_CLI::success`.
	 * @param callable|null $fail     Default `WP_CLI::error`.
	 * @param callable|null $warning  Default `WP_CLI::warning`.
	 * @param callable|null $title    Receives a post id; default `get_the_title`.
	 */
	public function __construct( Sections $sections, $log = null, $success = null, $fail = null, $warning = null, $title = null ) {
		$this->sections = $sections;
		$this->log      = $log ?? array( 'WP_CLI', 'log' );
		$this->success  = $success ?? array( 'WP_CLI', 'success' );
		$this->fail     = $fail ?? array( 'WP_CLI', 'error' );
		$this->warning  = $warning ?? array( 'WP_CLI', 'warning' );
		$this->title    = $title ?? static fn( $id ) => (string) get_the_title( (int) $id );
	}

	/**
	 * Prints the page where each unit of a section starts in a PDF.
	 *
	 * The PDF is the one made from the merged page of the section (the block "Merge the units of the section"): each unit starts with a marker
	 * that Chromium writes in the file. A PDF that went through a tool that rewrites the objects may have lost them.
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
	 * : Also report the units of the section without a marker, the markers found twice, the markers of a post that is not in the section.
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

		$starts  = array();
		$repeats = array();

		foreach ( $read['markers'] as $name => $pages ) {
			if ( 1 !== preg_match( '#^unit/(\d+)$#', $name, $unit ) ) {
				continue;
			}

			$starts[ (int) $unit[1] ] = $pages[0];

			if ( count( $pages ) > 1 ) {
				$repeats[ (int) $unit[1] ] = $pages;
			}
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
			}
		}

		if ( array() === $starts ) {
			( $this->fail )( 'No marker in this file: it was not made from a merged page, or the objects of the PDF were rewritten.' );
			return;
		}

		if ( ! isset( $assoc_args['check'] ) ) {
			( $this->success )( count( $starts ) . ' units in ' . $read['pages'] . ' pages.' );
			return;
		}

		$problems = 0;

		foreach ( array_diff( $expected, array_keys( $starts ) ) as $id ) {
			++$problems;
			( $this->warning )( 'No marker for the unit ' . $id . ' (a draft, a post with a password, or a unit left out by a limit).' );
		}

		foreach ( $repeats as $id => $pages ) {
			++$problems;
			( $this->warning )( 'The unit ' . $id . ' has its marker on several pages: ' . implode( ', ', $pages ) . '.' );
		}

		foreach ( array_diff( array_keys( $starts ), $expected ) as $id ) {
			++$problems;
			( $this->warning )( 'The marker of the post ' . $id . ', which is not in the section.' );
		}

		if ( 0 === $problems ) {
			( $this->success )( 'All ' . count( $expected ) . ' units of the section have a marker, found once.' );
			return;
		}

		( $this->success )( count( $starts ) . ' units in ' . $read['pages'] . ' pages, ' . $problems . ' to look at.' );
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
