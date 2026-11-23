<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The commands that merge topics.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Merge\DuplicateFinder;
use Otherguise\Index\Merge\TopicMerger;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index merge <keep> <go>...` and `wp otherguise index duplicates` (slice 616).
 */
final class MergeCommand {
	/**
	 * The merger.
	 *
	 * @var TopicMerger
	 */
	private $merger;

	/**
	 * The finder of duplicates.
	 *
	 * @var DuplicateFinder
	 */
	private $finder;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Reports a success.
	 *
	 * @var callable
	 */
	private $success;

	/**
	 * Reports a failure and stops.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Builds the commands.
	 *
	 * @param TopicMerger     $merger  The merger.
	 * @param DuplicateFinder $finder  The finder.
	 * @param callable|null   $log     Default `WP_CLI::log`.
	 * @param callable|null   $success Default `WP_CLI::success`.
	 * @param callable|null   $fail    Default `WP_CLI::error`.
	 */
	public function __construct( TopicMerger $merger, DuplicateFinder $finder, $log = null, $success = null, $fail = null ) {
		$this->merger  = $merger;
		$this->finder  = $finder;
		$this->log     = $log ?? array( 'WP_CLI', 'log' );
		$this->success = $success ?? array( 'WP_CLI', 'success' );
		$this->fail    = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Merges topics into one: the first stays and takes the mentions, the keys (so that the sources that named the others find it), the children and, when
	 * it is not classified or less precise, the kind of the others, which then go away. Two children with the same label are merged in turn.
	 *
	 * ## OPTIONS
	 *
	 * <keep>
	 * : The id of the topic that stays.
	 *
	 * <go>...
	 * : The ids of the topics that go away.
	 *
	 * [--dry-run]
	 * : Say what would be done and change nothing.
	 *
	 * [--force]
	 * : Merge two topics whose kinds are not one a sort of the other.
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise index merge 612 745 --dry-run
	 *     wp otherguise index merge 612 745 746
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) $args ), static fn( $id ) => $id > 0 ) );

		if ( count( $ids ) < 2 ) {
			( $this->fail )( 'Give the topic that stays and at least one that goes: wp otherguise index merge <keep> <go>...' );

			return;
		}

		$keep  = array_shift( $ids );
		$dry   = isset( $assoc['dry-run'] );
		$total = array(
			'mentions_moved'  => 0,
			'mentions_joined' => 0,
			'children_moved'  => 0,
			'children_merged' => 0,
			'keys'            => 0,
		);

		foreach ( $ids as $go ) {
			$report = $this->merger->merge( $keep, $go, $dry, isset( $assoc['force'] ) );

			if ( ! $report['ok'] ) {
				( $this->fail )( 'Topic ' . $go . ': ' . $report['error'] );

				return;
			}

			foreach ( $report['lines'] as $line ) {
				( $this->log )( $line );
			}

			foreach ( $total as $name => $count ) {
				$total[ $name ] = $count + $report[ $name ];
			}
		}

		( $this->success )(
			( $dry ? 'Dry run, nothing changed: ' : 'Merged: ' ) . $total['mentions_moved'] . ' mention(s) moved, ' . $total['mentions_joined'] . ' joined to one that the topic already had, '
			. $total['children_moved'] . ' child(ren) moved, ' . $total['children_merged'] . ' merged, ' . $total['keys'] . ' key(s) added.'
		);
	}

	/**
	 * Lists the pairs of topics that may be the same, for a person to look at (nothing is merged): the same label once what is in parentheses is left out,
	 * or labels that differ by a letter or two under the same parent. Homonyms under two parents are not offered.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : At most this many pairs (default 100).
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function duplicates( $args, $assoc ) {
		unset( $args );

		$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 100;
		$pairs = $this->finder->pairs();

		foreach ( array_slice( $pairs, 0, $limit ) as $pair ) {
			( $this->log )( $pair['a']['id'] . "\t" . $pair['a']['label'] . ' [' . $pair['a']['kind'] . ', under ' . $pair['a']['parent'] . "]\t" . $pair['b']['id'] . "\t" . $pair['b']['label'] . ' [' . $pair['b']['kind'] . ', under ' . $pair['b']['parent'] . "]\t" . $pair['why'] );
		}

		( $this->success )( count( $pairs ) . ' pair(s)' . ( count( $pairs ) > $limit ? ', ' . $limit . ' shown' : '' ) . '. Merge with: wp otherguise index merge <keep> <go> --dry-run' );
	}
}
