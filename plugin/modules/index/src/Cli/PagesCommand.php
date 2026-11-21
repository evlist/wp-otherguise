<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI: the job that reads the pages.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Job\PagesJob;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index pages enable|disable|start|run|status|stop|resume` (slice 602). `start` hands the work to WP-Cron; `run` does it here,
 * in the foreground, which is what a site that runs with `DISABLE_WP_CRON` needs.
 */
final class PagesCommand {
	/**
	 * Builds a job: `fn( bool $foreground ): PagesJob`; a foreground job schedules nothing.
	 *
	 * @var callable
	 */
	private $jobs;

	/**
	 * Reads one post now: `fn( int $post_id ): array{ok:bool,message:string}`.
	 *
	 * @var callable
	 */
	private $process_one;

	/**
	 * Turns the source `page` on or off: `fn( bool $on ): true|string`.
	 *
	 * @var callable
	 */
	private $set_source;

	/**
	 * Returns the time.
	 *
	 * @var callable
	 */
	private $now;

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
	 * @param callable      $jobs        Builds a job.
	 * @param callable      $process_one Reads one post.
	 * @param callable      $set_source  Turns the source on or off.
	 * @param callable|null $now         Default `time`.
	 * @param callable|null $log         Default `WP_CLI::log`.
	 * @param callable|null $success     Default `WP_CLI::success`.
	 * @param callable|null $fail        Default `WP_CLI::error`.
	 */
	public function __construct( $jobs, $process_one, $set_source, $now = null, $log = null, $success = null, $fail = null ) {
		$this->jobs        = $jobs;
		$this->process_one = $process_one;
		$this->set_source  = $set_source;
		$this->now         = $now ?? 'time';
		$this->log         = $log ?? array( 'WP_CLI', 'log' );
		$this->success     = $success ?? array( 'WP_CLI', 'success' );
		$this->fail        = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Turns the source `page` on: the posts will be read through their web page.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function enable( $args, $assoc ) {
		unset( $args, $assoc );

		$this->switch_source( true );
	}

	/**
	 * Turns the source `page` off. The mentions it made stay until the next `rebuild`.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function disable( $args, $assoc ) {
		unset( $args, $assoc );

		$this->switch_source( false );
	}

	/**
	 * Starts the job from the first post and hands it to WP-Cron (it needs a cron that runs: a site with `DISABLE_WP_CRON` needs a real one,
	 * or `run`).
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function start( $args, $assoc ) {
		unset( $args, $assoc );

		$state = ( $this->jobs )( false )->start( (int) ( $this->now )() );

		( $this->success )( 'Started: ' . $state['total'] . ' post(s) to read by WP-Cron. Follow it with `wp otherguise index pages status`.' );
	}

	/**
	 * Reads the pages here, now, until the end (or until `stop` is asked from another terminal), without WP-Cron.
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : Only this post.
	 *
	 * [--resume]
	 * : Go on from where the job stopped instead of starting again.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function run( $args, $assoc ) {
		unset( $args );

		if ( isset( $assoc['post'] ) ) {
			$result = (array) ( $this->process_one )( (int) $assoc['post'] );

			( $result['ok'] ? $this->success : $this->fail )( 'Post ' . (int) $assoc['post'] . ': ' . ( $result['ok'] ? 'read.' : (string) $result['message'] ) );

			return;
		}

		$job   = ( $this->jobs )( true );
		$state = $job->state();

		if ( empty( $assoc['resume'] ) || PagesJob::DONE === $state['status'] || PagesJob::IDLE === $state['status'] ) {
			$state = $job->start( (int) ( $this->now )() );
		} else {
			$state = $job->resume( (int) ( $this->now )() );
		}

		$shown = 0;

		while ( PagesJob::RUNNING === $state['status'] ) {
			$state = $job->tick( (int) ( $this->now )() );

			( $this->log )( $state['offset'] . '/' . $state['total'] . ' read, ' . count( $state['failed'] ) . ' failed' );

			foreach ( array_slice( $state['log'], $shown ) as $line ) {
				( $this->log )( '  ' . $line );
			}

			$shown = count( $state['log'] );
		}

		( $this->success )( 'The job is ' . $state['status'] . ': ' . $state['ok'] . ' read, ' . count( $state['failed'] ) . ' failed.' );
	}

	/**
	 * Shows where the job is, and the posts that failed with the reason.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function status( $args, $assoc ) {
		unset( $args, $assoc );

		$state = ( $this->jobs )( true )->state();

		( $this->log )( 'Status: ' . $state['status'] );
		( $this->log )( 'Position: ' . $state['offset'] . '/' . $state['total'] . ', ' . $state['ok'] . ' read, ' . count( $state['failed'] ) . ' failed' );

		foreach ( array_slice( $state['failed'], -20, null, true ) as $id => $reason ) {
			( $this->log )( '  failed ' . $id . ': ' . $reason );
		}

		foreach ( $state['log'] as $line ) {
			( $this->log )( '  ' . $line );
		}
	}

	/**
	 * Stops the job after the post being read.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function stop( $args, $assoc ) {
		unset( $args, $assoc );

		$state = ( $this->jobs )( true )->stop( (int) ( $this->now )() );

		( $this->success )( 'The job is ' . ( PagesJob::RUNNING === $state['status'] ? 'asked to stop' : $state['status'] ) . '.' );
	}

	/**
	 * Goes on from where the job stopped, by WP-Cron.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function resume( $args, $assoc ) {
		unset( $args, $assoc );

		$state = ( $this->jobs )( false )->resume( (int) ( $this->now )() );

		( $this->success )( PagesJob::STOPPED === $state['status'] ? 'Resumed at ' . $state['offset'] . '/' . $state['total'] . '.' : 'The job is not stopped (' . $state['status'] . ').' );
	}

	/**
	 * Turns the source on or off.
	 *
	 * @param bool $on On or off.
	 * @return void
	 */
	private function switch_source( $on ) {
		$done = ( $this->set_source )( $on );

		if ( true !== $done ) {
			( $this->fail )( (string) $done );

			return;
		}

		( $this->success )( 'The source page is ' . ( $on ? 'on' : 'off' ) . '.' );
	}
}
