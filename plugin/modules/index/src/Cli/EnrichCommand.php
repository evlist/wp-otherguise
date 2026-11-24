<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI: the Wikidata enrichment.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Enrich\EnrichService;
use Otherguise\Index\Job\PagesJob;
use Otherguise\Index\Kinds;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index enrich enable|disable|start|run|status|stop|resume|duplicates|ambiguous|classes|map|kinds` (slice 604). `start` hands the work
 * to WP-Cron; `run` does it here, which is what a site with `DISABLE_WP_CRON` needs. The services are never called by anything else.
 */
final class EnrichCommand {
	/**
	 * The service.
	 *
	 * @var EnrichService
	 */
	private $service;

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
	 * @param EnrichService $service The service.
	 * @param callable|null $now     Default `time`.
	 * @param callable|null $log     Default `WP_CLI::log`.
	 * @param callable|null $success Default `WP_CLI::success`.
	 * @param callable|null $fail    Default `WP_CLI::error`.
	 */
	public function __construct( EnrichService $service, $now = null, $log = null, $success = null, $fail = null ) {
		$this->service = $service;
		$this->now     = $now ?? 'time';
		$this->log     = $log ?? array( 'WP_CLI', 'log' );
		$this->success = $success ?? array( 'WP_CLI', 'success' );
		$this->fail    = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Turns the enrichment on: the topics will be looked up on Wikipedia and Wikidata, from the background job or this command only. The User-Agent
	 * names the plugin and the contact of the option `index_wikidata_contact` (the address of the site when there is none).
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function enable( $args, $assoc ) {
		unset( $args, $assoc );

		$this->service->set_on( true );
		( $this->success )( 'The enrichment is on. Check the contact of the User-Agent: wp option get index_wikidata_contact (empty: the address of the site is used).' );
	}

	/**
	 * Turns the enrichment off.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function disable( $args, $assoc ) {
		unset( $args, $assoc );

		$this->service->set_on( false );
		( $this->success )( 'The enrichment is off.' );
	}

	/**
	 * Starts the job and hands it to WP-Cron.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function start( $args, $assoc ) {
		unset( $args, $assoc );

		if ( ! $this->service->is_on() ) {
			( $this->fail )( 'The enrichment is off: wp otherguise index enrich enable.' );

			return;
		}

		$state = $this->service->job( false )->start( (int) ( $this->now )() );

		( $this->success )( 'Started: ' . $state['total'] . ' topic' . ( 1 === $state['total'] ? '' : 's' ) . ' to look up by WP-Cron.' );
	}

	/**
	 * Enriches here, now: one batch of 50 topics at a time, one request at a time, until there is nothing left, a service says to wait or three
	 * requests fail in a row.
	 *
	 * ## OPTIONS
	 *
	 * [--topic=<id>]
	 * : Only this topic (whatever its state).
	 *
	 * [--dry-run]
	 * : Make the requests, print what would be written (item, label, classes, kind), write nothing on the topics.
	 *
	 * [--resume]
	 * : Go on from where the job stopped.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function run( $args, $assoc ) {
		unset( $args );

		if ( ! $this->service->is_on() ) {
			( $this->fail )( 'The enrichment is off: wp otherguise index enrich enable.' );

			return;
		}

		$dry = ! empty( $assoc['dry-run'] );

		if ( $dry || isset( $assoc['topic'] ) ) {
			$this->single( $dry, isset( $assoc['topic'] ) ? (int) $assoc['topic'] : null );

			return;
		}

		$job   = $this->service->job( true );
		$state = $job->state();
		$state = ! empty( $assoc['resume'] ) && PagesJob::STOPPED === $state['status'] ? $job->resume( (int) ( $this->now )() ) : $job->start( (int) ( $this->now )() );
		$shown = 0;

		while ( PagesJob::RUNNING === $state['status'] ) {
			$state = $job->tick( (int) ( $this->now )() );

			( $this->log )( $state['offset'] . '/' . $state['total'] . ' looked at' );

			foreach ( array_slice( $state['log'], $shown ) as $line ) {
				( $this->log )( '  ' . $line );
			}

			$shown = count( $state['log'] );
		}

		( $this->success )( 'The job is ' . $state['status'] . ': ' . $state['ok'] . ' looked at.' );
	}

	/**
	 * Reads again the classes of the topics that have an identifier of Wikidata and no kind, and gives them the kind that the table of the classes says (slice 633):
	 * to run after `map` has added a line, since a topic is read once and the kind is not deduced again otherwise. **Only the kind changes** (never a kind that a
	 * person chose, never the label). A dry run by default.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write the kinds (without it, only say which topic would get which).
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function kinds( $args, $assoc ) {
		unset( $args );

		if ( ! $this->service->is_on() ) {
			( $this->fail )( 'The enrichment is off: wp otherguise index enrich enable.' );

			return;
		}

		$apply = ! empty( $assoc['apply'] );
		$done  = $this->service->enricher()->reread_kinds( $apply );

		foreach ( $done['lines'] as $line ) {
			( $this->log )( $line );
		}

		if ( ! $done['ok'] ) {
			( $this->fail )( $done['message'] . ' ' . $done['read'] . ' topic(s) were read before that; run the command again later.' );

			return;
		}

		if ( 0 === $done['read'] ) {
			( $this->success )( '' === $done['message'] ? 'Every topic that has an identifier of Wikidata has a kind.' : $done['message'] );

			return;
		}

		( $this->success )( $done['read'] . ' topic(s) read, ' . $done['changed'] . ( $apply ? ' got a kind.' : ' would get a kind: --apply writes them.' ) );
	}

	/**
	 * One batch, or one topic, with the report of each topic.
	 *
	 * @param bool     $dry   Dry run.
	 * @param int|null $topic The topic.
	 * @return void
	 */
	private function single( $dry, $topic ) {
		$done = $this->service->enricher()->run_batch( $dry, $topic );

		foreach ( $done['report'] as $id => $line ) {
			$classes = array();

			foreach ( $line['classes'] as $class => $label ) {
				$classes[] = $class . ( '' === $label ? '' : ' ' . $label );
			}

			( $this->log )( $id . "\t" . $line['state'] . "\t" . $line['qid'] . "\t" . $line['label'] . ( '' === $line['new'] ? '' : ' => ' . $line['new'] ) . "\tkind: " . ( '' === $line['kind'] ? '(unchanged)' : $line['kind'] ) . "\t" . implode( ', ', $classes ) . ( '' === $line['note'] ? '' : "\t" . $line['note'] ) );
		}

		if ( ! $done['ok'] ) {
			( $this->fail )( $done['message'] );

			return;
		}

		( $this->success )( $done['message'] . ( $dry ? ' Dry run: nothing was written on the topics.' : '' ) );
	}

	/**
	 * Shows where the job is.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function status( $args, $assoc ) {
		unset( $args, $assoc );

		$state = $this->service->job( true )->state();
		$until = $this->service->enricher()->paused_until();

		( $this->log )( 'Status: ' . $state['status'] . ( $this->service->is_on() ? '' : ' (the enrichment is off)' ) );
		( $this->log )( 'Position: ' . $state['offset'] . '/' . $state['total'] . ', ' . $state['ok'] . ' looked at' );

		if ( $until > 0 ) {
			( $this->log )( 'Paused until ' . gmdate( 'Y-m-d H:i:s', $until ) . ' UTC: the service asked to wait.' );
		}

		foreach ( $state['log'] as $line ) {
			( $this->log )( '  ' . $line );
		}
	}

	/**
	 * Stops the job after the batch being read.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function stop( $args, $assoc ) {
		unset( $args, $assoc );

		$state = $this->service->job( true )->stop( (int) ( $this->now )() );

		( $this->success )( 'The job is ' . ( PagesJob::RUNNING === $state['status'] ? 'asked to stop' : $state['status'] ) . '.' );
	}

	/**
	 * Goes on from where the job stopped, by WP-Cron (it waits for the end of a pause).
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function resume( $args, $assoc ) {
		unset( $args, $assoc );

		$state = $this->service->job( false )->resume( (int) ( $this->now )() );

		( $this->success )( PagesJob::STOPPED === $state['status'] ? 'Resumed at ' . $state['offset'] . '/' . $state['total'] . '.' : 'The job is not stopped (' . $state['status'] . ').' );
	}

	/**
	 * Lists the topics that hold the same Wikidata item as another topic: they were not merged, a person decides.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function duplicates( $args, $assoc ) {
		unset( $args, $assoc );

		$this->list_state( 'duplicate' );
	}

	/**
	 * Lists the topics whose Wikipedia page is a disambiguation page: no item was chosen for them.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function ambiguous( $args, $assoc ) {
		unset( $args, $assoc );

		$this->list_state( 'ambiguous' );
	}

	/**
	 * Prints the topics in a state.
	 *
	 * @param string $state State.
	 * @return void
	 */
	private function list_state( $state ) {
		foreach ( $this->service->enricher()->with_state( $state ) as $id => $line ) {
			( $this->log )( $id . "\t" . $line['label'] . ( '' === $line['note'] ? '' : "\t" . $line['note'] ) );
		}
	}

	/**
	 * Prints the table of classes with the label of each class read from Wikidata (one request), so that every line can be checked at a glance.
	 *
	 * ## OPTIONS
	 *
	 * [--check=<classes>]
	 * : Instead of the table, print the label that Wikidata gives to these identifiers (`Q484170,Q6465`), one request: to check a class **before** adding it with `map` (the table is not changed).
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function classes( $args, $assoc ) {
		unset( $args );

		if ( ! $this->service->is_on() ) {
			( $this->fail )( 'The enrichment is off: wp otherguise index enrich enable.' );

			return;
		}

		$table = $this->service->classes()->mapping();

		if ( isset( $assoc['check'] ) ) {
			$table = array();

			foreach ( array_filter( array_map( 'trim', explode( ',', (string) $assoc['check'] ) ), 'strlen' ) as $class ) {
				$table[ $class ] = '?';
			}

			if ( array() === $table || array() !== array_filter( array_keys( $table ), static fn( $class ) => ! \Otherguise\Index\Wikidata\ItemReader::valid_id( $class ) ) ) {
				( $this->fail )( '--check: identifiers such as Q484170, separated by commas.' );

				return;
			}
		}

		$labels = $this->service->items()->read( array_keys( $table ), substr( (string) get_locale(), 0, 2 ) );

		if ( ! $labels['response']['ok'] ) {
			( $this->fail )( $labels['response']['error'] );

			return;
		}

		foreach ( $table as $class => $kind ) {
			( $this->log )( $class . "\t" . ( '' === ( $labels['items'][ $class ]['label'] ?? '' ) ? '(no label: check the identifier)' : $labels['items'][ $class ]['label'] ) . ( isset( $assoc['check'] ) ? '' : "\t=> " . $kind ) );
		}
	}

	/**
	 * Changes the table of classes: `map <class> <kind>`, `map <class> --remove`, or `map --list` (without labels: see `classes`).
	 *
	 * ## OPTIONS
	 *
	 * [<class>]
	 * : The identifier of a Wikidata class (`Q4022`).
	 *
	 * [<kind>]
	 * : The kind that its items get.
	 *
	 * [--remove]
	 * : Take the line off.
	 *
	 * [--list]
	 * : Print the table.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function map( $args, $assoc ) {
		$table = $this->service->classes();

		if ( ! empty( $assoc['list'] ) ) {
			foreach ( $table->mapping() as $class => $kind ) {
				( $this->log )( $class . "\t" . $kind . ( Kinds::is_kind( $kind ) ? '' : "\t(unknown kind)" ) );
			}

			return;
		}

		$class = (string) ( $args[0] ?? '' );

		if ( ! empty( $assoc['remove'] ) ) {
			$removed = $table->remove( $class );

			( $removed ? $this->success : $this->fail )( $removed ? 'Removed.' : 'No such line.' );

			return;
		}

		$done = $table->set( $class, (string) ( $args[1] ?? '' ) );

		if ( true !== $done ) {
			( $this->fail )( $done );

			return;
		}

		( $this->success )( $class . ' now gives the kind ' . ( $args[1] ?? '' ) . '.' );
	}
}
