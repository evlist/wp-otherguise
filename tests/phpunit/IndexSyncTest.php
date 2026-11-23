<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the synchronisation of the index (slice 621).
 *
 * @package Otherguise
 */

use Otherguise\Index\Cli\SyncCommand;
use Otherguise\Index\Job\PagesJob;
use Otherguise\Index\Job\SyncService;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress, no database: the options, the cron and the pieces are in memory.
 *
 * @covers \Otherguise\Index\Job\SyncService
 * @covers \Otherguise\Index\Cli\SyncCommand
 */
class IndexSyncTest extends TestCase {

	/**
	 * What is stored, by name.
	 *
	 * @var array<string, array>
	 */
	private $store = array();

	/**
	 * The posts read, in order.
	 *
	 * @var int[]
	 */
	private $read = array();

	/**
	 * The topics that nothing mentions.
	 *
	 * @var int
	 */
	private $orphans = 2;

	/**
	 * What the cron was asked: the ticks and the events of the automatic run.
	 *
	 * @var array<string, mixed>
	 */
	private $cron = array(
		'ticks' => 0,
		'auto'  => 0,
		'off'   => 0,
	);

	/**
	 * The posts that changed, and all of them.
	 *
	 * @var array{stale:int[],all:int[]}
	 */
	private $posts = array(
		'stale' => array( 11, 12, 13 ),
		'all'   => array( 11, 12, 13, 14, 15 ),
	);

	/**
	 * The enrichment: null when it is off, else the queue of topics (a post of the job is a batch).
	 *
	 * @var int[]|null
	 */
	private $queue = array( 1, 2 );

	/**
	 * Whether the automatic run is on.
	 *
	 * @var bool
	 */
	private $auto_on = true;

	/**
	 * The posts for which the reading fails.
	 *
	 * @var int[]
	 */
	private $failing = array();

	/**
	 * Whether the enrichment says "wait" at its first batch.
	 *
	 * @var bool
	 */
	private $wait = false;

	/**
	 * The mentions dropped by "from scratch".
	 *
	 * @var int
	 */
	private $dropped = 0;

	/**
	 * Starts clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();

		$this->store   = array();
		$this->read    = array();
		$this->orphans = 2;
		$this->queue   = array( 1, 2 );
		$this->failing = array();
		$this->wait    = false;
		$this->dropped = 0;
		$this->auto_on = true;
		$this->cron    = array(
			'ticks' => 0,
			'auto'  => 0,
			'off'   => 0,
		);
	}

	/**
	 * The service, on the pieces in memory.
	 *
	 * @return SyncService
	 */
	private function service(): SyncService {
		$option = fn( $name ) => fn() => $this->store[ $name ] ?? array();
		$save   = fn( $name ) => function ( array $value ) use ( $name ) {
			$this->store[ $name ] = $value;
		};

		return new SyncService(
			array(
				'load'            => $option( 'state' ),
				'save'            => $save( 'state' ),
				'load_job'        => $option( 'job' ),
				'save_job'        => $save( 'job' ),
				'load_ids'        => $option( 'ids' ),
				'save_ids'        => $save( 'ids' ),
				'all_posts'       => fn() => $this->posts['all'],
				'stale_posts'     => fn() => $this->posts['stale'],
				'collect'         => function ( $id ) {
					$this->read[] = $id;

					return in_array( $id, $this->failing, true ) ? array(
						'ok'      => false,
						'message' => 'no page',
					) : array( 'ok' => true );
				},
				'enrich_job'      => fn() => null === $this->queue ? null : new PagesJob(
					fn() => $this->store['enrich'] ?? array(),
					function ( array $state ) {
						$this->store['enrich'] = $state;
					},
					fn( $offset, $limit ) => array() === $this->queue ? array() : array( 1 ),
					fn() => count( $this->queue ),
					function () {
						if ( $this->wait ) {
							return array(
								'stop'    => true,
								'message' => 'the service says wait',
							);
						}

						array_shift( $this->queue );

						return array( 'ok' => true );
					},
					static function () {},
					20.0
				),
				'cleanup'         => function () {
					$done          = $this->orphans;
					$this->orphans = 0;

					return $done;
				},
				'purge'           => fn() => ++$this->dropped * 10,
				'schedule'        => function () {
					++$this->cron['ticks'];
				},
				'auto_on'         => fn() => $this->auto_on,
				'schedule_auto'   => function () {
					++$this->cron['auto'];
				},
				'unschedule_auto' => function () {
					++$this->cron['off'];
				},
				'now'             => static fn() => 1700000000,
			)
		);
	}

	/**
	 * A run of what changed: it reads the posts that changed, enriches what waits, deletes the topics that nothing mentions and says so; a tick is planned
	 * while it goes on and none when it is over.
	 *
	 * @return void
	 */
	public function test_a_run_of_what_changed(): void {
		$sync = $this->service();
		$run  = $sync->start();

		$this->assertTrue( $run['started'] );
		$this->assertSame( 3, $run['posts'] );
		$this->assertSame( 0, $this->dropped, 'Nothing is dropped.' );
		$this->assertSame( SyncService::COLLECT, $sync->state()['phase'] );

		$phases = array();

		for ( $i = 0; $i < 10 && PagesJob::RUNNING === $sync->state()['status']; $i++ ) {
			$phases[] = $sync->tick()['phase'];
		}

		$state = $sync->state();

		$this->assertSame( array( 11, 12, 13 ), $this->read, 'Only the posts that changed, in order.' );
		$this->assertSame( PagesJob::DONE, $state['status'] );
		$this->assertSame( 3, $state['collected'] );
		$this->assertSame( 2, $state['deleted'] );
		$this->assertSame( array(), $this->queue, 'The enrichment went through its queue.' );
		$this->assertGreaterThan( 0, $state['finished'] );
		$this->assertSame( array( SyncService::ENRICH, SyncService::CLEANUP, SyncService::FINISH ), $phases, 'A tick works on a phase and leaves the next one ready: collect, enrich, cleanup.' );
		$this->assertCount( 3, array_filter( $state['log'], static fn( $line ) => false !== strpos( $line, 'collected' ) || false !== strpos( $line, 'enriched' ) || false !== strpos( $line, 'cleanup' ) ) );

		$ticks = $this->cron['ticks'];
		$sync->tick();

		$this->assertSame( $ticks, $this->cron['ticks'], 'A run that is over plans nothing.' );
	}

	/**
	 * From scratch: what the sources said is dropped first, every post is read; a post that cannot be read is counted and the run goes on.
	 *
	 * @return void
	 */
	public function test_from_scratch_drops_then_reads_everything(): void {
		$this->failing = array( 13 );
		$sync          = $this->service();
		$run           = $sync->start( true );

		$this->assertSame( 5, $run['posts'] );
		$this->assertSame( 1, $this->dropped );
		$this->assertTrue( $run['state']['scratch'] );
		$this->assertSame( 10, $run['state']['dropped'] );

		$sync->run_here( static function () {} );

		$this->assertSame( array( 11, 12, 13, 14, 15 ), $this->read );
		$this->assertSame( PagesJob::DONE, $sync->state()['status'] );
		$this->assertSame( 4, $sync->state()['collected'], 'Four read, one failed.' );
		$this->assertCount( 1, $sync->status()['read']['failed'] );
	}

	/**
	 * A post that could not be read after a run from scratch, or nothing read at all, skips the cleanup: what it said is missing, and the topics that only it
	 * mentioned would be deleted for nothing; an incremental run with one failure still cleans (the old mentions were not dropped).
	 *
	 * @return void
	 */
	public function test_no_cleanup_when_what_was_dropped_could_not_be_read(): void {
		$this->failing = array( 13 );
		$sync          = $this->service();

		$sync->start( true );
		$sync->run_here( static function () {} );

		$this->assertSame( 0, $sync->state()['deleted'] );
		$this->assertSame( 2, $this->orphans, 'Nothing was deleted.' );
		$this->assertCount( 1, array_filter( $sync->state()['log'], static fn( $line ) => false !== strpos( $line, 'cleanup skipped: 1 post(s)' ) ) );

		$sync->start( false );
		$sync->run_here( static function () {} );

		$this->assertSame( 2, $sync->state()['deleted'], 'An incremental run with one failure cleans: the old mentions are still there.' );

		$this->orphans = 3;
		$this->failing = array( 11, 12, 13 );

		$sync->start( false );
		$sync->run_here( static function () {} );

		$this->assertSame( 0, $sync->state()['deleted'], 'Nothing could be read at all: nothing is cleaned.' );
	}

	/**
	 * A run that is going on is not started again; it can be stopped; the enrichment that is off or that says wait does not stop the run from ending.
	 *
	 * @return void
	 */
	public function test_running_stopping_and_the_enrichment_that_waits(): void {
		$sync = $this->service();
		$sync->start();

		$again = $sync->start();

		$this->assertFalse( $again['started'], 'Already running.' );
		$this->assertSame( array(), $this->read );

		$sync->stop();

		$this->assertSame( PagesJob::STOPPED, $sync->state()['status'] );
		$this->assertSame( PagesJob::STOPPED, $sync->tick()['status'], 'A stopped run does nothing.' );

		$this->assertTrue( $sync->start()['started'], 'It can be started again.' );

		$this->wait = true;
		$sync->run_here( static function () {} );

		$this->assertSame( PagesJob::DONE, $sync->state()['status'] );
		$this->assertSame( array( 1, 2 ), $this->queue, 'The enrichment waited: nothing was done, the next run goes on.' );
		$this->assertCount( 1, array_filter( $sync->state()['log'], static fn( $line ) => false !== strpos( $line, 'paused' ) ) );

		$this->queue = null;
		$sync->start();
		$sync->run_here( static function () {} );

		$this->assertCount( 1, array_filter( $sync->state()['log'], static fn( $line ) => false !== strpos( $line, 'enrichment is off' ) ) );
	}

	/**
	 * The automatic run: it starts a run of what changed when it is on and none is going on; the daily event is planned or cancelled by the setting.
	 *
	 * @return void
	 */
	public function test_the_automatic_run(): void {
		$sync = $this->service();

		$this->assertTrue( $sync->auto() );
		$this->assertFalse( $sync->auto(), 'One is already running.' );

		$sync->stop();
		$this->auto_on = false;

		$this->assertFalse( $sync->auto(), 'The nightly run is off.' );

		$sync->ensure_scheduled();
		$this->assertSame( 1, $this->cron['off'] );

		$this->auto_on = true;
		$sync->ensure_scheduled();
		$this->assertSame( 1, $this->cron['auto'] );
	}

	/**
	 * The command: start hands the work to the cron, status says where it is, stop stops, auto changes the setting, and a wrong word is refused.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$lines = array();
		$done  = array();
		$fail  = array();
		$cmd   = new SyncCommand(
			$this->service(),
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $line ) use ( &$done ) {
				$done[] = $line;
			},
			static function ( $line ) use ( &$fail ) {
				$fail[] = $line;
			}
		);

		$cmd( array(), array() );

		$this->assertSame( '3 post(s) to read.', $lines[0] );
		$this->assertStringContainsString( 'WP-Cron does the rest', $done[0] );

		$cmd( array(), array() );
		$this->assertStringContainsString( 'already running', $done[1] );

		$cmd( array( 'status' ), array() );
		$this->assertContains( 'Status: running (phase collect)', $lines );
		$this->assertContains( 'Reading: 0/3, 0 read, 0 failed', $lines );

		$cmd( array( 'stop' ), array() );
		$this->assertStringContainsString( 'Stopped in the phase collect', end( $done ) );

		$cmd( array( 'start' ), array( 'foreground' => true ) );
		$this->assertStringContainsString( 'The index is up to date: 3 post(s) read, 2 topic(s) deleted.', end( $done ) );

		$cmd( array( 'auto', 'maybe' ), array() );
		$cmd( array( 'wrong' ), array() );

		$this->assertStringContainsString( 'auto on|off', $fail[0] );
		$this->assertStringContainsString( 'Unknown action', $fail[1] );
	}
}
