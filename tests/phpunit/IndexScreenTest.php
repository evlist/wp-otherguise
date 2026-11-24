<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the screen of the index (slice 622).
 *
 * @package Otherguise
 */

use Otherguise\Index\Admin\IndexScreen;
use Otherguise\Index\Job\PagesJob;
use Otherguise\Index\Job\SyncService;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress, no database: the synchronisation, the features, the request, the nonce and the redirection are doubles.
 *
 * @covers \Otherguise\Index\Admin\IndexScreen
 */
class IndexScreenTest extends TestCase {

	/**
	 * What is stored by the synchronisation, by name.
	 *
	 * @var array<string, array>
	 */
	private $store = array();

	/**
	 * The features that are saved.
	 *
	 * @var array<string, mixed>
	 */
	private $features = array();

	/**
	 * How many times the night event was asked to be planned.
	 *
	 * @var int
	 */
	private $planned = 0;

	/**
	 * The request, by type and name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $request = array();

	/**
	 * The writes of the features.
	 *
	 * @var int
	 */
	private $writes = 0;

	/**
	 * Starts clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();

		$this->store    = array();
		$this->planned  = 0;
		$this->writes   = 0;
		$this->request  = array();
		$this->features = array(
			'sources' => array( 'categories', 'tags', 'by-a-plugin' ),
			'enrich'  => false,
			'contact' => '',
			'auto'    => true,
		);
	}

	/**
	 * The synchronisation, on the pieces in memory.
	 *
	 * @return SyncService
	 */
	private function sync(): SyncService {
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
				'all_posts'       => static fn() => array( 1, 2, 3, 4 ),
				'stale_posts'     => static fn() => array( 1, 2 ),
				'collect'         => static fn( $id ) => array( 'ok' => true ),
				'enrich_job'      => static fn() => null,
				'cleanup'         => static fn() => 0,
				'purge'           => static fn() => 7,
				'schedule'        => static function () {},
				'auto_on'         => fn() => (bool) $this->features['auto'],
				'schedule_auto'   => function () {
					++$this->planned;
				},
				'unschedule_auto' => function () {
					++$this->planned;
				},
				'now'             => static fn() => 1700000000,
			)
		);
	}

	/**
	 * The screen.
	 *
	 * @param SyncService        $sync    The synchronisation.
	 * @param array<int, string> $urls    Receives the redirections.
	 * @param bool               $allowed Whether the user may.
	 * @param bool               $nonce   Whether the nonce is good.
	 * @return IndexScreen
	 */
	private function screen( SyncService $sync, array &$urls, $allowed = true, $nonce = true ): IndexScreen {
		return new IndexScreen(
			$sync,
			fn() => $this->features,
			function ( array $features ) {
				++$this->writes;
				$this->features = $features;
			},
			static fn() => $allowed,
			fn( $type, $name ) => $this->request[ $type ][ $name ] ?? null,
			static fn() => $nonce,
			static function ( $url ) use ( &$urls ) {
				$urls[] = $url;
			}
		);
	}

	/**
	 * The page.
	 *
	 * @param IndexScreen $screen The screen.
	 * @return string
	 */
	private function page( IndexScreen $screen ): string {
		ob_start();
		$screen->render();

		return (string) ob_get_clean();
	}

	/**
	 * Posts a form.
	 *
	 * @param array<string, string> $fields The fields.
	 * @return void
	 */
	private function post( array $fields ): void {
		$this->request[ INPUT_POST ] = array_merge( array( '_wpnonce' => 'good' ), $fields );
	}

	/**
	 * The page says what is on and where the synchronisation is; a stranger sees nothing.
	 *
	 * @return void
	 */
	public function test_the_page(): void {
		$urls = array();
		$sync = $this->sync();
		$page = $this->page( $this->screen( $sync, $urls ) );

		$this->assertStringContainsString( 'Nothing was run yet.', $page );
		$this->assertMatchesRegularExpression( '/name="src_categories" value="1" checked/', $page );
		$this->assertDoesNotMatchRegularExpression( '/name="src_page" value="1" checked/', $page );
		$this->assertDoesNotMatchRegularExpression( '/name="enrich" value="1" checked/', $page );
		$this->assertMatchesRegularExpression( '/name="auto" value="1" checked/', $page );
		$this->assertStringNotContainsString( 'minutes_min', $page, 'The stops are gone: no thresholds.' );

		$sync->start();
		$page = $this->page( $this->screen( $sync, $urls ) );

		$this->assertStringContainsString( 'Running, phase: collect', $page );
		$this->assertStringContainsString( 'Reading: 0 of 2 posts', $page );
		$this->assertStringContainsString( '2 post(s) to read', $page );

		$this->assertSame( '', $this->page( $this->screen( $sync, $urls, false ) ) );
	}

	/**
	 * Saving: the sources, the enrichment, the contact, the night run are written; a source of a plugin is kept; the photographs turn
	 * the pages on; the night event is planned at once.
	 *
	 * @return void
	 */
	public function test_saving_what_is_on(): void {
		$urls = array();

		$this->post(
			array(
				'src_categories' => '1',
				'src_photos'     => '1',
				'enrich'         => '1',
				'contact'        => '  eric@example.org  ',
			)
		);
		$this->screen( $this->sync(), $urls )->save();

		$this->assertSame( 1, $this->writes );
		$this->assertEqualsCanonicalizing( array( 'by-a-plugin', 'categories', 'photos', 'page' ), $this->features['sources'] );
		$this->assertTrue( $this->features['enrich'] );
		$this->assertSame( 'eric@example.org', $this->features['contact'] );
		$this->assertFalse( $this->features['auto'], 'An unchecked box is off.' );
		$this->assertSame( 1, $this->planned, 'The night event was planned or cancelled at once.' );
		$this->assertStringContainsString( 'done=saved', end( $urls ) );
	}

	/**
	 * The capability and the nonce are checked on both forms, and nothing is done without them.
	 *
	 * @return void
	 */
	public function test_the_capability_and_the_nonce(): void {
		$urls = array();

		foreach ( array( 'save', 'run' ) as $handler ) {
			$this->post( array( 'op' => 'start' ) );

			foreach ( array( array( false, true ), array( true, false ) ) as $case ) {
				try {
					$this->screen( $this->sync(), $urls, $case[0], $case[1] )->$handler();
					$this->fail( 'The form was accepted.' );
				} catch ( Throwable $stopped ) {
					$this->assertNotInstanceOf( \PHPUnit\Framework\AssertionFailedError::class, $stopped );
				}
			}
		}

		$this->assertSame( 0, $this->writes );
		$this->assertSame( array(), $urls );
		$this->assertSame( array(), $this->store );
	}

	/**
	 * The run form: update, from scratch (what the sources said is dropped), stop; another operation is refused; a run that is going is not started twice.
	 *
	 * @return void
	 */
	public function test_the_buttons(): void {
		$urls = array();
		$sync = $this->sync();

		$this->post( array( 'op' => 'start' ) );
		$this->screen( $sync, $urls )->run();

		$this->assertSame( PagesJob::RUNNING, $sync->state()['status'] );
		$this->assertSame( 2, $sync->status()['read']['total'], 'An update reads the posts that changed.' );
		$this->assertStringContainsString( 'done=started', end( $urls ) );

		$this->screen( $sync, $urls )->run();

		$this->assertStringContainsString( 'failed=', end( $urls ) );
		$this->assertStringContainsString( 'already', rawurldecode( rawurldecode( end( $urls ) ) ) );

		$this->post( array( 'op' => 'stop' ) );
		$this->screen( $sync, $urls )->run();

		$this->assertSame( PagesJob::STOPPED, $sync->state()['status'] );
		$this->assertStringContainsString( 'done=stopped', end( $urls ) );

		$this->post( array( 'op' => 'scratch' ) );
		$this->screen( $sync, $urls )->run();

		$this->assertTrue( $sync->state()['scratch'] );
		$this->assertSame( 7, $sync->state()['dropped'] );
		$this->assertSame( 4, $sync->status()['read']['total'], 'From scratch reads every post.' );

		$sync->stop();
		$this->post( array( 'op' => 'explode' ) );
		$this->screen( $sync, $urls )->run();

		$this->assertStringContainsString( 'not known', rawurldecode( rawurldecode( end( $urls ) ) ) );
		$this->assertSame( PagesJob::STOPPED, $sync->state()['status'] );
	}
}
