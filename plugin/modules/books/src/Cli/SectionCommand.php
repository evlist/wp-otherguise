<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI: the sections.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Cli;

use Otherguise\Books\Section\SectionException;
use Otherguise\Books\Section\Sections;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise books section list|show|add|remove` (slice 223): the same rules as the REST routes; a command line has no logged-in user,
 * so the posts are those of a store that does not ask the capabilities. Each command can be run twice.
 */
final class SectionCommand {
	private const PAGE = 50;
	private const CAP  = 1000;

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
	 * Builds the command.
	 *
	 * @param Sections      $sections The sections (with a store that trusts the command line).
	 * @param callable|null $log      Default `WP_CLI::log`.
	 * @param callable|null $success  Default `WP_CLI::success`.
	 * @param callable|null $fail     Default `WP_CLI::error`.
	 */
	public function __construct( Sections $sections, $log = null, $success = null, $fail = null ) {
		$this->sections = $sections;
		$this->log      = $log ?? array( 'WP_CLI', 'log' );
		$this->success  = $success ?? array( 'WP_CLI', 'success' );
		$this->fail     = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Lists the sections.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Only the sections that match.
	 *
	 * [--format=<format>]
	 * : `table` (default) or `json`.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		unset( $args );

		$format = $assoc_args['format'] ?? 'table';

		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			( $this->fail )( '--format: table or json.' );
			return;
		}

		$rows  = array();
		$page  = 1;
		$total = 0;

		do {
			$found = $this->sections->search( (string) ( $assoc_args['search'] ?? '' ), $page, self::PAGE );

			foreach ( $found['items'] as $info ) {
				$rows[] = array(
					'id'       => $info['id'],
					'title'    => $info['title'],
					'status'   => $info['status'],
					'contains' => count( $this->sections->order( $info['id'] ) ),
				);
			}

			$total += count( $found['items'] );
			++$page;
		} while ( $total < $found['total'] && array() !== $found['items'] && $page <= self::CAP / self::PAGE );

		if ( 'json' === $format ) {
			( $this->log )( (string) wp_json_encode( $rows ) );
			return;
		}

		foreach ( $rows as $row ) {
			( $this->log )( implode( "\t", $row ) );
		}
	}

	/**
	 * Shows the contents of a section, in order.
	 *
	 * ## OPTIONS
	 *
	 * <section>
	 * : Id of the section.
	 *
	 * [--format=<format>]
	 * : `table` (default) or `json`.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function show( $args, $assoc_args ) {
		$id     = $this->id( $args[0] ?? null );
		$format = $assoc_args['format'] ?? 'table';

		if ( null === $this->sections->describe( $id ) ) {
			( $this->fail )( 'This post does not exist.' );
			return;
		}

		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			( $this->fail )( '--format: table or json.' );
			return;
		}

		$state = $this->sections->state( $id );

		if ( 'json' === $format ) {
			( $this->log )( (string) wp_json_encode( $state ) );
			return;
		}

		foreach ( $state['contents'] as $rank => $info ) {
			( $this->log )( implode( "\t", array( $rank + 1, $info['id'], $info['date'], $info['status'], null === $info['pinned'] ? '' : 'pinned', $info['title'] ) ) );
		}
	}

	/**
	 * Adds posts to a section, by id or by selection.
	 *
	 * ## OPTIONS
	 *
	 * <section>
	 * : Id of the section.
	 *
	 * [<ids>...]
	 * : Ids of the posts (separated by spaces or commas).
	 *
	 * [--search=<text>]
	 * : Select the posts that match.
	 *
	 * [--from=<date>]
	 * : Select the posts from this date (`Y-m-d`).
	 *
	 * [--to=<date>]
	 * : Select the posts up to this date (`Y-m-d`).
	 *
	 * [--status=<statuses>]
	 * : Statuses of the selected posts, separated by commas (default `publish`).
	 *
	 * [--dry-run]
	 * : Say what would be added.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function add( $args, $assoc_args ) {
		$section = $this->id( $args[0] ?? null );
		$ids     = $this->ids( array_slice( $args, 1 ) );
		$select  = isset( $assoc_args['search'] ) || isset( $assoc_args['from'] ) || isset( $assoc_args['to'] );

		if ( array() === $ids && ! $select ) {
			( $this->fail )( 'Give ids, or --search, --from and --to.' );
			return;
		}

		try {
			if ( $select ) {
				$ids = array_merge( $ids, $this->selected( $section, $assoc_args ) );
			}

			$ids = array_values( array_unique( $ids ) );

			if ( ! empty( $assoc_args['dry-run'] ) ) {
				( $this->log )( sprintf( 'Would add %d post(s) to section %d.', count( $ids ), $section ) );
				return;
			}

			$added   = 0;
			$already = 0;
			$skipped = array();

			foreach ( array_chunk( $ids, Sections::MAX_BATCH ) as $batch ) {
				$report   = $this->sections->add( $section, $batch );
				$added   += count( $report['added'] );
				$already += count( $report['already'] );
				$skipped += $report['skipped'];
			}
		} catch ( SectionException $refusal ) {
			( $this->fail )( $refusal->getMessage() );
			return;
		}

		foreach ( $skipped as $id => $reason ) {
			( $this->log )( sprintf( 'Skipped %d: %s.', $id, $reason ) );
		}

		( $this->success )( sprintf( '%d added, %d already there, %d skipped.', $added, $already, count( $skipped ) ) );
	}

	/**
	 * Takes posts out of a section.
	 *
	 * ## OPTIONS
	 *
	 * <section>
	 * : Id of the section.
	 *
	 * <ids>...
	 * : Ids of the posts.
	 *
	 * [--dry-run]
	 * : Say what would be removed.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function remove( $args, $assoc_args ) {
		$section = $this->id( $args[0] ?? null );
		$ids     = $this->ids( array_slice( $args, 1 ) );

		if ( array() === $ids ) {
			( $this->fail )( 'Give the ids of the posts to remove.' );
			return;
		}

		$inside  = $this->sections->order( $section );
		$present = array_values( array_intersect( $ids, $inside ) );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			( $this->log )( sprintf( 'Would remove %d post(s) from section %d.', count( $present ), $section ) );
			return;
		}

		try {
			foreach ( $present as $id ) {
				$this->sections->remove( $section, $id );
			}
		} catch ( SectionException $refusal ) {
			( $this->fail )( $refusal->getMessage() );
			return;
		}

		( $this->success )( sprintf( '%d removed, %d were not in the section.', count( $present ), count( $ids ) - count( $present ) ) );
	}

	/**
	 * The ids of the posts selected by search and dates, not yet in the section (at most `CAP`).
	 *
	 * @param int   $section    Id of the section.
	 * @param array $assoc_args Named arguments.
	 * @return int[]
	 * @throws SectionException When the section is refused.
	 */
	private function selected( $section, array $assoc_args ) {
		$ids  = array();
		$page = 1;

		do {
			$found = $this->sections->candidates(
				$section,
				array(
					'search'   => (string) ( $assoc_args['search'] ?? '' ),
					'after'    => (string) ( $assoc_args['from'] ?? '' ),
					'before'   => (string) ( $assoc_args['to'] ?? '' ),
					'statuses' => array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['status'] ?? 'publish' ) ) ) ),
					'page'     => $page,
					'per_page' => self::PAGE,
				)
			);

			foreach ( $found['items'] as $info ) {
				if ( ! $info['in_section'] ) {
					$ids[] = $info['id'];
				}
			}

			++$page;
		} while ( array() !== $found['items'] && $page <= self::CAP / self::PAGE && ( $page - 1 ) * self::PAGE < $found['total'] );

		return $ids;
	}

	/**
	 * Reads an id.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function id( $value ) {
		if ( ! is_string( $value ) || ! ctype_digit( $value ) || (int) $value <= 0 ) {
			( $this->fail )( 'The section is the id of a post.' );

			return 0;
		}

		return (int) $value;
	}

	/**
	 * Reads ids given separated by spaces or commas.
	 *
	 * @param array $values Arguments.
	 * @return int[]
	 */
	private function ids( array $values ) {
		$ids = array();

		foreach ( $values as $value ) {
			foreach ( explode( ',', (string) $value ) as $part ) {
				$part = trim( $part );

				if ( '' !== $part && ctype_digit( $part ) && (int) $part > 0 ) {
					$ids[ (int) $part ] = (int) $part;
				}
			}
		}

		return array_values( $ids );
	}
}
