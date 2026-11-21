<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The job that reads the pages.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the published posts one by one (slice 602), for a time budget at each tick, with a state that survives between two ticks: status
 * (`running`, `stopped`, `done`), position, counts, the failures with their reason and a short log. `stop` and `resume` act between two
 * posts; a failure is recorded and the job goes on. Nothing here knows WordPress: the pieces are callables.
 */
final class PagesJob {
	public const RUNNING = 'running';
	public const STOPPED = 'stopped';
	public const DONE    = 'done';
	public const IDLE    = 'idle';

	private const LOG_LINES = 50;

	/**
	 * Returns the state: `fn(): array`.
	 *
	 * @var callable
	 */
	private $load;

	/**
	 * Keeps the state: `fn( array $state ): void`.
	 *
	 * @var callable
	 */
	private $save;

	/**
	 * Returns the ids of the published posts: `fn( int $offset, int $limit ): int[]`.
	 *
	 * @var callable
	 */
	private $posts;

	/**
	 * Counts the published posts: `fn(): int`.
	 *
	 * @var callable
	 */
	private $count;

	/**
	 * Reads one post: `fn( int $post_id ): array{ok:bool,message:string}`.
	 *
	 * @var callable
	 */
	private $process;

	/**
	 * Schedules the next tick: `fn( int $delay_seconds ): void`.
	 *
	 * @var callable
	 */
	private $schedule;

	/**
	 * Returns the time in seconds, with decimals.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * The budget of a tick, in seconds.
	 *
	 * @var float
	 */
	private $budget;

	/**
	 * Builds the job.
	 *
	 * @param callable      $load     Reads the state.
	 * @param callable      $save     Keeps the state.
	 * @param callable      $posts    Ids of the posts, by page.
	 * @param callable      $count    Number of posts.
	 * @param callable      $process  Reads one post.
	 * @param callable      $schedule Schedules the next tick.
	 * @param float         $budget   Seconds of work per tick.
	 * @param callable|null $clock Default `microtime( true )`.
	 */
	public function __construct( $load, $save, $posts, $count, $process, $schedule, $budget = 20.0, $clock = null ) {
		$this->load     = $load;
		$this->save     = $save;
		$this->posts    = $posts;
		$this->count    = $count;
		$this->process  = $process;
		$this->schedule = $schedule;
		$this->budget   = (float) $budget;
		$this->clock    = $clock ?? static function () {
			return microtime( true );
		};
	}

	/**
	 * The state, with its defaults.
	 *
	 * @return array{status:string,offset:int,total:int,ok:int,failed:array<int,string>,log:string[],started:int,updated:int}
	 */
	public function state() {
		$state = (array) ( $this->load )();

		return array(
			'status'  => (string) ( $state['status'] ?? self::IDLE ),
			'offset'  => (int) ( $state['offset'] ?? 0 ),
			'total'   => (int) ( $state['total'] ?? 0 ),
			'ok'      => (int) ( $state['ok'] ?? 0 ),
			'failed'  => (array) ( $state['failed'] ?? array() ),
			'log'     => array_values( array_map( 'strval', (array) ( $state['log'] ?? array() ) ) ),
			'started' => (int) ( $state['started'] ?? 0 ),
			'updated' => (int) ( $state['updated'] ?? 0 ),
		);
	}

	/**
	 * Starts again from the first post.
	 *
	 * @param int $now Time.
	 * @return array The new state.
	 */
	public function start( $now ) {
		$state = array(
			'status'  => self::RUNNING,
			'offset'  => 0,
			'total'   => (int) ( $this->count )(),
			'ok'      => 0,
			'failed'  => array(),
			'log'     => array(),
			'started' => (int) $now,
			'updated' => (int) $now,
		);

		( $this->save )( $state );
		( $this->schedule )( 1 );

		return $state;
	}

	/**
	 * Stops after the post being read.
	 *
	 * @param int $now Time.
	 * @return array The state.
	 */
	public function stop( $now ) {
		$state = $this->state();

		if ( self::RUNNING === $state['status'] ) {
			$state['status']  = self::STOPPED;
			$state['updated'] = (int) $now;
			$state['log'][]   = gmdate( 'H:i:s', (int) $now ) . ' stopped at ' . $state['offset'] . '/' . $state['total'];
			( $this->save )( $this->trim( $state ) );
		}

		return $state;
	}

	/**
	 * Goes on from where it stopped.
	 *
	 * @param int $now Time.
	 * @return array The state.
	 */
	public function resume( $now ) {
		$state = $this->state();

		if ( self::STOPPED === $state['status'] ) {
			$state['status']  = self::RUNNING;
			$state['updated'] = (int) $now;
			( $this->save )( $state );
			( $this->schedule )( 1 );
		}

		return $state;
	}

	/**
	 * Works for the time budget, then schedules the next tick when there is more to do.
	 *
	 * @param int $now Time (seconds), for the log.
	 * @return array The state.
	 */
	public function tick( $now ) {
		$state = $this->state();

		if ( self::RUNNING !== $state['status'] ) {
			return $state;
		}

		$begin = (float) ( $this->clock )();

		while ( true ) {
			$ids = (array) ( $this->posts )( $state['offset'], 1 );

			if ( array() === $ids ) {
				$state['status'] = self::DONE;
				$state['log'][]  = gmdate( 'H:i:s', (int) $now ) . ' done: ' . $state['ok'] . ' read, ' . count( $state['failed'] ) . ' failed';
				break;
			}

			$id     = (int) $ids[0];
			$result = (array) ( $this->process )( $id );

			// A unit that asks to stop (a service that said "wait") stops the job without counting as a failure.
			if ( ! empty( $result['stop'] ) ) {
				$state['status']  = self::STOPPED;
				$state['updated'] = (int) $now;
				$state['log'][]   = gmdate( 'H:i:s', (int) $now ) . ' stopped: ' . (string) ( $result['message'] ?? '' );
				break;
			}

			// A unit may be several items (a batch of entries): `processed` says how many.
			$units = max( 1, (int) ( $result['processed'] ?? 1 ) );

			if ( ! empty( $result['ok'] ) ) {
				$state['ok'] += $units;
			} else {
				$state['failed'][ $id ] = (string) ( $result['message'] ?? 'failed' );
				$state['log'][]         = gmdate( 'H:i:s', (int) $now ) . ' post ' . $id . ': ' . $state['failed'][ $id ];
			}

			$state['offset'] += $units;
			$state['updated'] = (int) $now;

			// A stop asked from another request is seen between two posts.
			$current = (array) ( $this->load )();

			if ( self::STOPPED === (string) ( $current['status'] ?? '' ) ) {
				$state['status'] = self::STOPPED;
				break;
			}

			if ( (float) ( $this->clock )() - $begin >= $this->budget ) {
				break;
			}
		}

		( $this->save )( $this->trim( $state ) );

		if ( self::RUNNING === $state['status'] ) {
			( $this->schedule )( 5 );
		}

		return $state;
	}

	/**
	 * Keeps the log short.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function trim( array $state ) {
		$state['log'] = array_slice( $state['log'], -self::LOG_LINES );

		return $state;
	}
}
