<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The commands about the designations.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Designation\Assigner;
use Otherguise\Index\Designation\Cleaner;
use Otherguise\Index\Designation\Migration;
use Otherguise\Index\Designations;
use Otherguise\Index\Observations;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index designations list|assign|clean|migrate` (slice 626): see what the sources designated, assign it to a topic, clean up, and convert
 * the mentions made by hand.
 */
final class DesignationsCommand {
	/**
	 * The designations.
	 *
	 * @var Designations
	 */
	private $designations;

	/**
	 * The observations.
	 *
	 * @var Observations
	 */
	private $observations;

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * The assigner.
	 *
	 * @var Assigner
	 */
	private $assigner;

	/**
	 * The cleaner.
	 *
	 * @var Cleaner
	 */
	private $cleaner;

	/**
	 * The conversion of the mentions made by hand.
	 *
	 * @var Migration
	 */
	private $migration;

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
	 * Builds the command.
	 *
	 * @param Designations  $designations The designations.
	 * @param Observations  $observations The observations.
	 * @param Topics        $topics       The topics.
	 * @param Assigner      $assigner     The assigner.
	 * @param Cleaner       $cleaner      The cleaner.
	 * @param Migration     $migration    The conversion.
	 * @param callable|null $log         Default `WP_CLI::log`.
	 * @param callable|null $success     Default `WP_CLI::success`.
	 * @param callable|null $fail        Default `WP_CLI::error`.
	 */
	public function __construct( Designations $designations, Observations $observations, Topics $topics, Assigner $assigner, Cleaner $cleaner, Migration $migration, $log = null, $success = null, $fail = null ) {
		$this->designations = $designations;
		$this->observations = $observations;
		$this->topics       = $topics;
		$this->assigner     = $assigner;
		$this->cleaner      = $cleaner;
		$this->migration    = $migration;
		$this->log          = $log ?? array( 'WP_CLI', 'log' );
		$this->success      = $success ?? array( 'WP_CLI', 'success' );
		$this->fail         = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Lists the designations: what the sources designated, with the topic each is assigned to and by whom.
	 *
	 * ## OPTIONS
	 *
	 * [--topic=<id>]
	 * : Only the designations assigned to this topic.
	 *
	 * [--source=<path>]
	 * : Only this source (with its sub-sources): `post/link`, `image`...
	 *
	 * [--state=<state>]
	 * : `pending`, `ambiguous` or `assigned`.
	 *
	 * [--unobserved]
	 * : Only the designations that no post observes.
	 *
	 * [--limit=<n>]
	 * : At most this many lines (default 100).
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function list( $args, $assoc ) {
		unset( $args );

		$filters = array();

		foreach ( array( 'source', 'state' ) as $name ) {
			if ( isset( $assoc[ $name ] ) ) {
				$filters[ $name ] = (string) $assoc[ $name ];
			}
		}

		$all   = $this->designations->all( $filters );
		$topic = isset( $assoc['topic'] ) ? $this->observations->designations_of_topic( (int) $assoc['topic'] ) : null;
		$free  = isset( $assoc['unobserved'] ) ? $this->observations->unobserved( array_keys( $all ) ) : null;
		$limit = max( 1, (int) ( $assoc['limit'] ?? 100 ) );
		$shown = 0;
		$total = 0;

		foreach ( $all as $id => $one ) {
			if ( ( null !== $topic && ! isset( $topic[ $id ] ) ) || ( null !== $free && ! in_array( $id, $free, true ) ) ) {
				continue;
			}

			++$total;

			if ( $shown >= $limit ) {
				continue;
			}

			++$shown;

			$have  = $this->observations->assignment( (int) $id );
			$goes  = null === $have ? null : $this->topics->get( $have['topic'] );
			$posts = count( $this->observations->posts_of( (int) $id ) );

			( $this->log )( $id . "\t" . $one['key'] . "\t" . $one['state'] . "\t" . ( null === $goes ? '-' : $goes['label'] . ' (' . $goes['id'] . ', ' . $have['by'] . ')' ) . "\t" . $posts . ' post(s)' );
		}

		( $this->success )( $total . ' designation(s)' . ( $total > $shown ? ', ' . $shown . ' shown (--limit)' : '' ) . '.' );
	}

	/**
	 * Assigns designations to a topic, by a person: a new reading never changes it, and the mentions of the posts that observe them follow at once. Nothing
	 * is changed without --apply.
	 *
	 * ## OPTIONS
	 *
	 * --topic=<id>
	 * : The topic that receives them.
	 *
	 * [--designation=<ids>]
	 * : The designations, separated by commas.
	 *
	 * [--from-topic=<id>]
	 * : Every designation now assigned to this topic (its keys follow them).
	 *
	 * [--apply]
	 * : Do it. Without it the command only says what it would do.
	 *
	 * [--force]
	 * : Assign across kinds of which neither is a sort of the other.
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function assign( $args, $assoc ) {
		unset( $args );

		$from = (int) ( $assoc['from-topic'] ?? 0 );
		$ids  = isset( $assoc['designation'] ) ? array_map( 'intval', explode( ',', (string) $assoc['designation'] ) ) : array();

		if ( $from > 0 ) {
			$ids = array_merge( $ids, array_keys( $this->observations->designations_of_topic( $from ) ) );
		}

		if ( ! isset( $assoc['topic'] ) || array() === $ids ) {
			( $this->fail )( 'Say --topic=<id> and --designation=<ids> or --from-topic=<id>.' );

			return;
		}

		$dry    = ! isset( $assoc['apply'] );
		$report = $this->assigner->assign( $ids, (int) $assoc['topic'], $dry, isset( $assoc['force'] ), $from );

		if ( ! $report['ok'] ) {
			( $this->fail )( $report['error'] );

			return;
		}

		foreach ( $report['lines'] as $line ) {
			( $this->log )( $line );
		}

		( $this->success )( $report['designations'] . ' designation(s), ' . $report['observations'] . ' observation(s) in ' . $report['posts'] . ' post(s) ' . ( $dry ? 'would be assigned: nothing was changed, add --apply to do it.' : 'assigned.' ) );
	}

	/**
	 * Deletes the designations that the system proposed and that no post observes any more. A designation that a person assigned stays unless --everything.
	 * Nothing is changed without --apply.
	 *
	 * ## OPTIONS
	 *
	 * [--everything]
	 * : Also the designations that a person assigned.
	 *
	 * [--apply]
	 * : Do it.
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function clean( $args, $assoc ) {
		unset( $args );

		$ids = $this->cleaner->candidates( isset( $assoc['everything'] ) );

		if ( isset( $assoc['apply'] ) ) {
			$this->cleaner->delete( $ids );
		}

		( $this->success )( count( $ids ) . ' designation(s) ' . ( isset( $assoc['apply'] ) ? 'deleted.' : 'would be deleted: nothing was changed, add --apply to do it.' ) );
	}

	/**
	 * Measures what decides whether Triples is enough for the designations and the observations (slice 625): the numbers, and the time of the operations
	 * that a person waits for. Changes nothing. The index of a section is timed by `time wp otherguise index build --section=<id>`.
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Unused.
	 * @return void
	 */
	public function measure( $args, $assoc ) {
		unset( $args, $assoc );

		$all    = $this->designations->all();
		$counts = $this->observations->counts();

		( $this->log )( 'topics: ' . count( $this->topics->all() ) );
		( $this->log )( 'designations: ' . count( $all ) );
		( $this->log )( 'observations: ' . $counts['observations'] . ', assignments: ' . $counts['assignments'] . ' (by hand: ' . $counts['by_hand'] . ')' );

		$per = array();

		foreach ( array_keys( $all ) as $id ) {
			$have = $this->observations->assignment( (int) $id );

			if ( null !== $have ) {
				$per[ $have['topic'] ] = ( $per[ $have['topic'] ] ?? 0 ) + 1;
			}
		}

		if ( array() === $per ) {
			( $this->success )( 'No designation is assigned: read the pages first.' );

			return;
		}

		arsort( $per );

		$topic = (int) array_key_first( $per );
		$timed = array();

		$start = microtime( true );
		$ids   = array_keys( $this->observations->designations_of_topic( $topic ) );
		$timed[ 'the designations of the topic that has most (' . count( $ids ) . ')' ] = microtime( true ) - $start;

		$start = microtime( true );
		$this->assigner->assign( $ids, $topic, true );
		$timed['an assignment of all of them to the same topic, dry run'] = microtime( true ) - $start;

		$start = microtime( true );
		$posts = 0;

		foreach ( array_slice( array_keys( $all ), 0, 200 ) as $id ) {
			$posts += count( $this->observations->posts_of( (int) $id ) );
		}

		$timed[ 'the posts of 200 designations (' . $posts . ' observations)' ] = microtime( true ) - $start;

		foreach ( $timed as $what => $seconds ) {
			( $this->log )( sprintf( '%6.0f ms  %s', $seconds * 1000, $what ) );
		}

		( $this->success )( 'Thresholds: a page of the screen 1 s, an assignment of all the designations of a topic 2 s, the index of the largest section twice today and 10 s.' );
	}

	/**
	 * Converts the mentions made by hand into designations assigned by hand, with one observation each. The mentions of the other sources are made again by
	 * `wp otherguise index sync start --from-scratch`. It can be run twice. Nothing is changed without --apply.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Do it.
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function migrate( $args, $assoc ) {
		unset( $args );

		$done = $this->migration->run( ! isset( $assoc['apply'] ) );

		( $this->success )( $done['observations'] . ' mention(s) made by hand in ' . $done['posts'] . ' post(s), ' . $done['created'] . ' designation(s) ' . ( isset( $assoc['apply'] ) ? 'made.' : 'would be made: nothing was changed, add --apply to do it.' ) );
	}
}
