<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The synchronisation of the index: one job that does all the phases.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Job;

defined( 'ABSPATH' ) || exit;

/**
 * The synchronisation of the index (slice 621): **one job that makes the index up to date, in the right order**, in the background, so that nobody has to
 * know the phases and their order.
 *
 * 1. **collect**: for each post to read (the posts that changed since they were read, or all of them from scratch): the categories and the tags, then the
 *    page (the links, the marks, the photographs);
 * 2. **enrich**: the topics that Wikidata has not been asked about yet (when the enrichment is on);
 * 3. **cleanup**: the topics that nothing mentions any more are deleted (the ones that a person chose or that wait for a decision stay).
 *
 * `from scratch` drops first what the sources said (never what a person made by hand). The job is driven by one event of WP-Cron (`otherguise_index_sync`):
 * each tick works for a limited time on the current phase and plans the next one; it can be stopped and resumed. **An automatic run** (a daily event) starts a
 * synchronisation of what changed, unless one is running or the automatic run is off. The pieces (the sources, the job of the pages, the enrichment, the
 * pruner) are the ones that the commands use: nothing is done here that a person could not do by hand.
 */
final class SyncService {
	public const TICK_HOOK = 'otherguise_index_sync';
	public const AUTO_HOOK = 'otherguise_index_sync_auto';
	public const AUTO      = 'index_sync_auto';

	public const COLLECT = 'collect';
	public const ENRICH  = 'enrich';
	public const CLEANUP = 'cleanup';
	public const FINISH  = 'done';

	private const LOG_LINES = 60;

	/**
	 * The pieces, by name: `load` and `save` (the state), `load_job` and `save_job` (the state of the job of the collect), `load_ids` and `save_ids`
	 * (the posts of the run), `all_posts`, `stale_posts`, `collect` (one post), `enrich_job` (a job, or null when the enrichment is off), `cleanup`,
	 * `purge`, `schedule` (a tick in some seconds), `auto_on`, `schedule_auto`, `unschedule_auto`, `now`.
	 *
	 * @var array<string, callable>
	 */
	private $with;

	/**
	 * Builds the service.
	 *
	 * @param array<string, callable> $with The pieces.
	 */
	public function __construct( array $with ) {
		$this->with = $with;
	}

	/**
	 * The state.
	 *
	 * @return array{status:string,phase:string,scratch:bool,started:int,finished:int,updated:int,log:string[],collected:int,failed:int,deleted:int,dropped:int}
	 */
	public function state() {
		$state = (array) ( $this->with['load'] )();

		return array(
			'status'    => (string) ( $state['status'] ?? PagesJob::IDLE ),
			'phase'     => (string) ( $state['phase'] ?? '' ),
			'scratch'   => ! empty( $state['scratch'] ),
			'started'   => (int) ( $state['started'] ?? 0 ),
			'finished'  => (int) ( $state['finished'] ?? 0 ),
			'updated'   => (int) ( $state['updated'] ?? 0 ),
			'log'       => array_values( array_map( 'strval', (array) ( $state['log'] ?? array() ) ) ),
			'collected' => (int) ( $state['collected'] ?? 0 ),
			'failed'    => (int) ( $state['failed'] ?? 0 ),
			'deleted'   => (int) ( $state['deleted'] ?? 0 ),
			'dropped'   => (int) ( $state['dropped'] ?? 0 ),
		);
	}

	/**
	 * Starts a run, unless one is running.
	 *
	 * @param bool $scratch Drop what the sources said first and read every post.
	 * @return array{started:bool,state:array,posts:int}
	 */
	public function start( $scratch = false ) {
		$state = $this->state();

		if ( PagesJob::RUNNING === $state['status'] ) {
			return array(
				'started' => false,
				'state'   => $state,
				'posts'   => $this->job()->state()['total'],
			);
		}

		$now     = (int) ( $this->with['now'] )();
		$dropped = $scratch ? (int) ( $this->with['purge'] )() : 0;
		$ids     = array_values( array_map( 'intval', $scratch ? (array) ( $this->with['all_posts'] )() : (array) ( $this->with['stale_posts'] )() ) );

		( $this->with['save_ids'] )( $ids );
		$this->job()->start( $now );

		$state = array(
			'status'    => PagesJob::RUNNING,
			'phase'     => self::COLLECT,
			'scratch'   => (bool) $scratch,
			'started'   => $now,
			'finished'  => 0,
			'updated'   => $now,
			'log'       => array( gmdate( 'H:i:s', $now ) . ' started' . ( $scratch ? ' from scratch: ' . $dropped . ' mention(s) of the sources dropped' : '' ) . ', ' . count( $ids ) . ' post(s) to read' ),
			'collected' => 0,
			'failed'    => 0,
			'deleted'   => 0,
			'dropped'   => $dropped,
		);

		( $this->with['save'] )( $state );
		( $this->with['schedule'] )( 1 );

		return array(
			'started' => true,
			'state'   => $this->state(),
			'posts'   => count( $ids ),
		);
	}

	/**
	 * One tick: the current phase works for a limited time, then the next tick is planned. Action `otherguise_index_sync`.
	 *
	 * @return array The state.
	 */
	public function tick() {
		$state = $this->state();

		if ( PagesJob::RUNNING !== $state['status'] ) {
			return $state;
		}

		$now = (int) ( $this->with['now'] )();

		if ( self::COLLECT === $state['phase'] ) {
			$job     = $this->job();
			$progress = $job->tick( $now );

			if ( PagesJob::DONE === $progress['status'] ) {
				$state['collected'] = $progress['ok'];
				$state['failed']    = count( $progress['failed'] );
				$state['log'][]     = gmdate( 'H:i:s', $now ) . ' collected: ' . $progress['ok'] . ' post(s) read, ' . count( $progress['failed'] ) . ' failed';
				$state              = $this->enter( self::ENRICH, $state, $now );
			} elseif ( PagesJob::STOPPED === $progress['status'] ) {
				$state['status'] = PagesJob::STOPPED;
				$state['log'][]  = gmdate( 'H:i:s', $now ) . ' stopped while reading the posts';
			}
		} elseif ( self::ENRICH === $state['phase'] ) {
			$job      = ( $this->with['enrich_job'] )();
			$progress = null === $job ? null : $job->tick( $now );

			if ( null === $progress || PagesJob::RUNNING !== $progress['status'] ) {
				$state['log'][] = gmdate( 'H:i:s', $now ) . ( null === $progress ? ' enrichment is off' : ' enriched: ' . $progress['ok'] . ' topic(s)' . ( PagesJob::STOPPED === $progress['status'] ? ' (paused: the service asked to wait, the next run goes on)' : '' ) );
				$state          = $this->enter( self::CLEANUP, $state, $now );
			}
		} elseif ( self::CLEANUP === $state['phase'] ) {
			// A post that could not be read leaves what it said missing after a run from scratch, and the topics that only it mentioned would be deleted: no
			// cleanup then (the next run, which reads it, does it). When nothing could be read at all, nothing is cleaned either.
			$unread = (int) ( $state['failed'] ?? 0 );
			$skip   = $unread > 0 && ( ! empty( $state['scratch'] ) || 0 === (int) $state['collected'] );

			$state['deleted'] = $skip ? 0 : (int) ( $this->with['cleanup'] )();
			$state['log'][]   = gmdate( 'H:i:s', $now ) . ( $skip ? ' cleanup skipped: ' . $unread . ' post(s) could not be read, run the synchronisation again' : ' cleanup: ' . $state['deleted'] . ' topic(s) that nothing mentions deleted' );
			$state['phase']   = self::FINISH;
			$state['status']  = PagesJob::DONE;
			$state['finished'] = $now;
		}

		$state['updated'] = $now;
		$state['log']     = array_slice( $state['log'], -self::LOG_LINES );

		( $this->with['save'] )( $state );

		if ( PagesJob::RUNNING === $state['status'] ) {
			( $this->with['schedule'] )( 5 );
		}

		return $this->state();
	}

	/**
	 * Goes to a phase, starting what it needs.
	 *
	 * @param string $phase The phase.
	 * @param array  $state The state.
	 * @param int    $now   The time.
	 * @return array The state.
	 */
	private function enter( $phase, array $state, $now ) {
		$state['phase'] = $phase;

		if ( self::ENRICH === $phase ) {
			$job = ( $this->with['enrich_job'] )();

			if ( null !== $job ) {
				$job->start( $now );
			}
		}

		return $state;
	}

	/**
	 * Stops the run (it can be started again: the posts already read are not read again by a run of what changed).
	 *
	 * @return array The state.
	 */
	public function stop() {
		$state = $this->state();

		if ( PagesJob::RUNNING === $state['status'] ) {
			$now             = (int) ( $this->with['now'] )();
			$state['status']  = PagesJob::STOPPED;
			$state['updated'] = $now;
			$state['log'][]   = gmdate( 'H:i:s', $now ) . ' stopped in the phase ' . $state['phase'];
			( $this->with['save'] )( $state );
			$this->job()->stop( $now );
		}

		return $this->state();
	}

	/**
	 * The state of the run and of the phase that is going on.
	 *
	 * @return array{state:array,read:array,enrich:?array}
	 */
	public function status() {
		$enrich = ( $this->with['enrich_job'] )();

		return array(
			'state'  => $this->state(),
			'read'   => $this->job()->state(),
			'enrich' => null === $enrich ? null : $enrich->state(),
		);
	}

	/**
	 * Runs until the run is over, here and now (the command with `--foreground`). A tick is as long as its budget, so `$log` hears of the progress.
	 *
	 * @param callable $log Receives a line.
	 * @return array The state.
	 */
	public function run_here( $log ) {
		$seen = 0;

		while ( PagesJob::RUNNING === $this->state()['status'] ) {
			$state = $this->tick();

			foreach ( array_slice( $state['log'], $seen ) as $line ) {
				$log( $line );
			}

			$seen = count( $state['log'] );
		}

		return $this->state();
	}

	/**
	 * The daily event: starts a run of what changed, unless one is running or the automatic run is off. Action `otherguise_index_sync_auto`.
	 *
	 * @return bool Whether a run was started.
	 */
	public function auto() {
		return (bool) ( $this->with['auto_on'] )() && $this->start( false )['started'];
	}

	/**
	 * Plans or cancels the daily event according to the setting. Action `init`.
	 *
	 * @return void
	 */
	public function ensure_scheduled() {
		if ( (bool) ( $this->with['auto_on'] )() ) {
			( $this->with['schedule_auto'] )();
		} else {
			( $this->with['unschedule_auto'] )();
		}
	}

	/**
	 * The job of the collect: its units are the posts of the run.
	 *
	 * @return PagesJob
	 */
	private function job() {
		return new PagesJob(
			$this->with['load_job'],
			$this->with['save_job'],
			fn( $offset, $limit ) => array_slice( (array) ( $this->with['load_ids'] )(), (int) $offset, (int) $limit ),
			fn() => count( (array) ( $this->with['load_ids'] )() ),
			$this->with['collect'],
			static function () {},
			20.0
		);
	}
}
