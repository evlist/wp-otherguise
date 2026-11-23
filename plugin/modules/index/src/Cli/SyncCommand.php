<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The command that synchronises the index.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Job\PagesJob;
use Otherguise\Index\Job\SyncService;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index sync [start|status|stop|auto]` (slice 621).
 */
final class SyncCommand {
	/**
	 * The service.
	 *
	 * @var SyncService
	 */
	private $sync;

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
	 * @param SyncService   $sync    The service.
	 * @param callable|null $log     Default `WP_CLI::log`.
	 * @param callable|null $success Default `WP_CLI::success`.
	 * @param callable|null $fail    Default `WP_CLI::error`.
	 */
	public function __construct( SyncService $sync, $log = null, $success = null, $fail = null ) {
		$this->sync    = $sync;
		$this->log     = $log ?? array( 'WP_CLI', 'log' );
		$this->success = $success ?? array( 'WP_CLI', 'success' );
		$this->fail    = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Makes the index up to date, in the right order, in the background: the categories and the tags, the pages (links, marks, photographs) of the posts that
	 * changed, the Wikidata enrichment (when it is on), then the topics that nothing mentions any more are deleted. **It also runs by itself every night**
	 * (WP-Cron; `wp otherguise index sync auto off` stops that). Use `status` to follow it.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : `start` (the default), `status`, `stop` or `auto <on|off>`.
	 *
	 * [<setting>]
	 * : With `auto`: `on` or `off`.
	 *
	 * [--from-scratch]
	 * : With `start`: first drop what the sources said (the mentions of the pages, the tags, the categories, the photographs and the tracks; never what a person made by hand), then read every post. The topics that a person chose stay; the others that nothing mentions are deleted at the end.
	 *
	 * [--foreground]
	 * : With `start`: do it here and now, printing the progress, instead of handing it to WP-Cron.
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise index sync
	 *     wp otherguise index sync status
	 *     wp otherguise index sync start --from-scratch
	 *     wp otherguise index sync auto off
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc ) {
		$action = (string) ( $args[0] ?? 'start' );

		if ( 'status' === $action ) {
			$this->status();
		} elseif ( 'stop' === $action ) {
			$state = $this->sync->stop();
			( $this->success )( PagesJob::STOPPED === $state['status'] ? 'Stopped in the phase ' . $state['phase'] . '.' : 'Nothing was running.' );
		} elseif ( 'auto' === $action ) {
			$on = (string) ( $args[1] ?? '' );

			if ( ! in_array( $on, array( 'on', 'off' ), true ) ) {
				( $this->fail )( 'Say: wp otherguise index sync auto on|off' );

				return;
			}

			update_option( SyncService::AUTO, 'on' === $on ? '1' : '0' );
			$this->sync->ensure_scheduled();
			( $this->success )( 'The nightly synchronisation is ' . $on . '.' );
		} elseif ( 'start' === $action ) {
			$this->start( isset( $assoc['from-scratch'] ), isset( $assoc['foreground'] ) );
		} else {
			( $this->fail )( 'Unknown action "' . $action . '": start, status, stop or auto.' );
		}
	}

	/**
	 * Starts a run.
	 *
	 * @param bool $scratch    From scratch.
	 * @param bool $foreground Here and now.
	 * @return void
	 */
	private function start( $scratch, $foreground ) {
		$run = $this->sync->start( $scratch );

		if ( ! $run['started'] ) {
			( $this->success )( 'A synchronisation is already running (phase ' . $run['state']['phase'] . '): wp otherguise index sync status' );

			return;
		}

		( $this->log )( $run['posts'] . ' post(s) to read.' );

		if ( $foreground ) {
			$state = $this->sync->run_here( $this->log );
			( $this->success )( 'The index is up to date: ' . $state['collected'] . ' post(s) read, ' . $state['deleted'] . ' topic(s) deleted.' );

			return;
		}

		( $this->success )( 'Started: WP-Cron does the rest. Follow it with: wp otherguise index sync status' );
	}

	/**
	 * Says where the run is.
	 *
	 * @return void
	 */
	private function status() {
		$status = $this->sync->status();
		$state  = $status['state'];

		( $this->log )( 'Status: ' . $state['status'] . ( '' === $state['phase'] ? '' : ' (phase ' . $state['phase'] . ')' ) . ( $state['scratch'] ? ', from scratch' : '' ) );

		if ( PagesJob::RUNNING === $state['status'] ) {
			( $this->log )( 'Reading: ' . $status['read']['offset'] . '/' . $status['read']['total'] . ', ' . $status['read']['ok'] . ' read, ' . count( $status['read']['failed'] ) . ' failed' );

			if ( null !== $status['enrich'] ) {
				( $this->log )( 'Enrichment: ' . $status['enrich']['offset'] . '/' . $status['enrich']['total'] );
			}
		}

		if ( $state['finished'] > 0 ) {
			( $this->log )( 'Last run finished at ' . gmdate( 'Y-m-d H:i:s', $state['finished'] ) . ' UTC: ' . $state['collected'] . ' read, ' . $state['deleted'] . ' topic(s) deleted.' );
		}

		foreach ( $state['log'] as $line ) {
			( $this->log )( '  ' . $line );
		}

		( $this->success )( 'The nightly synchronisation is ' . ( '0' === (string) get_option( SyncService::AUTO, '1' ) ? 'off' : 'on' ) . '.' );
	}
}
