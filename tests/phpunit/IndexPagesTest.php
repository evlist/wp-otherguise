<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the fetching of the pages (slice 602), without a database.
 *
 * @package Otherguise
 */

use Otherguise\Index\Cli\PagesCommand;
use Otherguise\Index\Harvest\Address;
use Otherguise\Index\Harvest\PageFetcher;
use Otherguise\Index\Harvest\Signal;
use Otherguise\Index\Harvest\SignalStore;
use Otherguise\Index\Job\PagesJob;
use Otherguise\Index\Source\Page;
use PHPUnit\Framework\TestCase;

/**
 * Stubs and doubles only.
 *
 * @covers \Otherguise\Index\Harvest\Address
 * @covers \Otherguise\Index\Harvest\PageFetcher
 * @covers \Otherguise\Index\Harvest\SignalStore
 * @covers \Otherguise\Index\Harvest\Signal::from_array
 * @covers \Otherguise\Index\Source\Page
 * @covers \Otherguise\Index\Job\PagesJob
 * @covers \Otherguise\Index\Cli\PagesCommand
 */
class IndexPagesTest extends TestCase {

	/**
	 * Starts clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * Only the beginning of an address of the site is replaced; another site's address and an invalid internal address are left alone.
	 *
	 * @return void
	 */
	public function test_the_internal_address(): void {
		$public = 'https://e.vli.st/';

		$this->assertSame( 'http://wordpress/2026/10/01/a/?x=1', Address::rewrite( 'https://e.vli.st/2026/10/01/a/?x=1', $public, 'http://wordpress' ) );
		$this->assertSame( 'http://wordpress:8080/a/', Address::rewrite( 'https://e.vli.st/a/', $public, 'http://wordpress:8080/' ) );
		$this->assertSame( 'https://other.test/a/', Address::rewrite( 'https://other.test/a/', $public, 'http://wordpress' ) );
		$this->assertSame( 'https://e.vli.st/a/', Address::rewrite( 'https://e.vli.st/a/', $public, '' ) );
		$this->assertSame( 'https://e.vli.st/a/', Address::rewrite( 'https://e.vli.st/a/', $public, 'ftp://x' ) );
		$this->assertSame( 'https://e.vli.st/a/', Address::rewrite( 'https://e.vli.st/a/', $public, 'http://wordpress/path' ) );
		$this->assertSame( '', Address::normalize( 'http://user@wordpress' ) );
		$this->assertSame( 'http://wordpress', Address::normalize( 'HTTP://WordPress' ) );
	}

	/**
	 * Builds a fetcher on a double of the request.
	 *
	 * @param mixed  $answer What the request returns.
	 * @param array  $asked  Receives the address and the arguments asked.
	 * @param string $internal The internal address.
	 * @return PageFetcher
	 */
	private function fetcher( $answer, array &$asked, $internal = '' ): PageFetcher {
		return new PageFetcher(
			static fn( $id ) => 12 === $id ? 'https://e.vli.st/2026/a/' : '',
			static fn() => 'https://e.vli.st/',
			static fn() => $internal,
			static function ( $url, $args ) use ( $answer, &$asked ) {
				$asked = array( $url, $args );

				return $answer;
			}
		);
	}

	/**
	 * The fetcher asks the web page (no print), by the internal address when there is one, and answers the HTML or a reason.
	 *
	 * @return void
	 */
	public function test_the_fetcher(): void {
		$asked = array();
		$page  = $this->fetcher(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
				'body'     => '<main></main>',
			),
			$asked,
			'http://wordpress'
		)->fetch( 12 );

		$this->assertSame( '<main></main>', $page['html'] );
		$this->assertSame( '', $page['error'] );
		$this->assertSame( 'http://wordpress/2026/a/', $asked[0] );
		$this->assertSame( 30, $asked[1]['timeout'] );
		$this->assertStringNotContainsString( 'print', $asked[0] );

		$not_found = $this->fetcher( array( 'response' => array( 'code' => 404 ) ), $asked )->fetch( 12 );
		$this->assertStringContainsString( 'status 404', $not_found['error'] );

		$pdf = $this->fetcher(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/pdf' ),
				'body'     => '%PDF',
			),
			$asked
		)->fetch( 12 );
		$this->assertStringContainsString( 'not HTML', $pdf['error'] );

		$failed = $this->fetcher( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), $asked )->fetch( 12 );
		$this->assertStringContainsString( 'timed out', $failed['error'] );
		$this->assertSame( '', $failed['html'] );

		$this->assertStringContainsString( 'no address', $this->fetcher( array(), $asked )->fetch( 99 )['error'] );
	}

	/**
	 * An in-memory post meta for the store.
	 *
	 * @param array $meta Receives the meta by post and key.
	 * @return SignalStore
	 */
	private function store( array &$meta ): SignalStore {
		return new SignalStore(
			static function ( $id, $key ) use ( &$meta ) {
				return $meta[ $id ][ $key ] ?? '';
			},
			static function ( $id, $key, $value ) use ( &$meta ) {
				$meta[ $id ][ $key ] = is_string( $value ) ? stripslashes( $value ) : $value;
			},
			static fn() => 1700000000
		);
	}

	/**
	 * The store keeps the signals as JSON, counts the images instead of keeping them, tells when nothing changed, and reads them back.
	 *
	 * @return void
	 */
	public function test_the_store(): void {
		$meta  = array();
		$store = $this->store( $meta );
		$list  = array(
			new Signal( Signal::IMAGE, 'a.jpg', 'page', 'src' ),
			new Signal( Signal::IMAGE, 'b.jpg', 'page', 'src' ),
			new Signal( Signal::WIKIPEDIA_LINK, 'Beaufort (fromage)', 'content', 'href', array( 'language' => 'fr' ) ),
		);

		$this->assertTrue( $store->save( 5, $list ) );
		$this->assertSame( 2, $meta[5]['_index_images'] );
		$this->assertStringNotContainsString( 'a.jpg', $meta[5]['_index_signals'] );
		$this->assertStringStartsWith( '1700000000 ', $meta[5]['_index_harvest'] );

		$this->assertFalse( $store->save( 5, $list ), 'The same harvest changes nothing.' );
		$this->assertTrue( $store->save( 5, array_merge( $list, array( new Signal( Signal::IMAGE, 'c.jpg', 'page', 'src' ) ) ) ), 'A new image is a change.' );

		$back = $store->load( 5 );

		$this->assertCount( 1, $back );
		$this->assertSame( 'Beaufort (fromage)', $back[0]->value );
		$this->assertSame( 'fr', $back[0]->data['language'] );
		$this->assertSame( array(), $store->load( 6 ) );
		$this->assertNull( Signal::from_array( 'x' ) );
		$this->assertNull( Signal::from_array( array( 'type' => 3 ) ) );
	}

	/**
	 * The source turns marks and Wikipedia links into candidates and ignores the other signals.
	 *
	 * @return void
	 */
	public function test_the_source_of_pages(): void {
		$meta  = array();
		$store = $this->store( $meta );
		$store->save(
			5,
			array(
				new Signal(
					Signal::ENTRY,
					'wikidata:Q1234',
					'page',
					'data-index',
					array(
						'path'       => array( 'WIKIDATA:Q1234' ),
						'identifier' => true,
						'kind'       => 'species',
						'label'      => 'Mésange bleue',
						'main'       => true,
					)
				),
				new Signal(
					Signal::ENTRY,
					'Beaufort > Les Arberons',
					'page',
					'data-index',
					array(
						'path'       => array( 'Beaufort', 'Les Arberons' ),
						'identifier' => false,
						'kind'       => 'bogus',
					)
				),
				new Signal( Signal::WIKIPEDIA_LINK, 'Beaufort (fromage)', 'content', 'href', array( 'language' => 'fr' ) ),
				new Signal( Signal::WIKIDATA_LINK, 'Q42', 'content', 'href' ),
				new Signal( Signal::GPX_FILE, 'a.gpx', 'page', 'href' ),
			)
		);

		$source     = new Page( $store );
		$candidates = $source->candidates( 5 );

		$this->assertSame( 'page', $source->id() );
		$this->assertTrue( $source->is_sure() );
		$this->assertCount( 3, $candidates );

		$this->assertSame( 'Mésange bleue', $candidates[0]->label );
		$this->assertSame( array( 'wikidata:Q1234' ), $candidates[0]->keys );
		$this->assertSame( 'species', $candidates[0]->kind );
		$this->assertSame( 'main', $candidates[0]->strength );

		$this->assertSame( 'Les Arberons', $candidates[1]->label );
		$this->assertSame( 'Beaufort', $candidates[1]->parent->label );
		$this->assertSame( 'other', $candidates[1]->kind, 'A kind that is not one falls back to other.' );
		$this->assertSame( 'passing', $candidates[1]->strength );

		$this->assertSame( array( 'wikipedia:fr:Beaufort (fromage)' ), $candidates[2]->keys );
		$this->assertSame( array(), $source->candidates( 6 ) );
	}

	/**
	 * A job on in-memory pieces.
	 *
	 * @param array    $state   The state, kept here.
	 * @param int[]    $ids     The posts.
	 * @param array    $done    Receives the posts read.
	 * @param array    $ticks   Receives the delays of the ticks scheduled.
	 * @param callable $process Reads one post (default: ok, except the post 3).
	 * @param float[]  $clock   The successive times of the clock.
	 * @return PagesJob
	 */
	private function job( array &$state, array $ids, array &$done, array &$ticks, $process = null, array $clock = array() ): PagesJob {
		$times = $clock;

		return new PagesJob(
			static function () use ( &$state ) {
				return $state;
			},
			static function ( array $new ) use ( &$state ) {
				$state = $new;
			},
			static fn( $offset, $limit ) => array_slice( $ids, $offset, $limit ),
			static fn() => count( $ids ),
			$process ?? static function ( $id ) use ( &$done ) {
				$done[] = $id;

				return 3 === $id ? array(
					'ok'      => false,
					'message' => 'The page answered with the status 404.',
				) : array(
					'ok'      => true,
					'message' => '',
				);
			},
			static function ( $delay ) use ( &$ticks ) {
				$ticks[] = $delay;
			},
			10.0,
			static function () use ( &$times ) {
				return array() === $times ? 0.0 : array_shift( $times );
			}
		);
	}

	/**
	 * A job reads every post, records the failure with its reason and goes on, then ends as `done`; a second start begins again.
	 *
	 * @return void
	 */
	public function test_the_job_runs_to_the_end_and_records_a_failure(): void {
		$state = array();
		$done  = array();
		$ticks = array();
		$job   = $this->job( $state, array( 1, 2, 3, 4 ), $done, $ticks );

		$this->assertSame( 'idle', $job->state()['status'] );

		$started = $job->start( 1700000000 );

		$this->assertSame( 4, $started['total'] );
		$this->assertSame( array( 1 ), $ticks );

		$after = $job->tick( 1700000010 );

		$this->assertSame( 'done', $after['status'] );
		$this->assertSame( array( 1, 2, 3, 4 ), $done );
		$this->assertSame( 3, $after['ok'] );
		$this->assertSame( array( 3 => 'The page answered with the status 404.' ), $after['failed'] );
		$this->assertSame( 4, $after['offset'] );
		$this->assertStringContainsString( 'post 3: The page answered', $after['log'][0] );
		$this->assertSame( array( 1 ), $ticks, 'A finished job schedules nothing more.' );
		$this->assertSame( 'done', $job->tick( 1700000020 )['status'], 'A tick on a finished job does nothing.' );
		$this->assertSame( 4, count( $done ) );

		$job->start( 1700000030 );
		$this->assertSame( 0, $job->state()['offset'] );
		$this->assertSame( array(), $job->state()['failed'] );
	}

	/**
	 * The time budget ends a tick and schedules the next one; stop and resume work between two posts.
	 *
	 * @return void
	 */
	public function test_the_budget_stop_and_resume(): void {
		$state = array();
		$done  = array();
		$ticks = array();
		// The clock: begin 0, then 4 after the first post, 11 after the second (over the budget of 10).
		$job = $this->job( $state, array( 1, 2, 3, 4 ), $done, $ticks, null, array( 0.0, 4.0, 11.0 ) );

		$job->start( 1700000000 );
		$tick = $job->tick( 1700000011 );

		$this->assertSame( 'running', $tick['status'] );
		$this->assertSame( array( 1, 2 ), $done );
		$this->assertSame( 2, $tick['offset'] );
		$this->assertSame( array( 1, 5 ), $ticks );

		$stopped = $job->stop( 1700000012 );
		$this->assertSame( 'stopped', $stopped['status'] );
		$this->assertSame( 'stopped', $job->tick( 1700000013 )['status'] );
		$this->assertSame( array( 1, 2 ), $done, 'A stopped job reads nothing.' );

		$resumed = $job->resume( 1700000014 );
		$this->assertSame( 'running', $job->state()['status'] );
		$this->assertSame( 2, $resumed['offset'] );
		$this->assertSame( array( 1, 5, 1 ), $ticks );

		$job->tick( 1700000015 );
		$this->assertSame( array( 1, 2, 3, 4 ), $done );
	}

	/**
	 * A stop asked while a tick is working (another request writes the state) is seen between two posts.
	 *
	 * @return void
	 */
	public function test_a_stop_from_another_request_is_seen(): void {
		$state = array();
		$done  = array();
		$ticks = array();
		$job   = $this->job(
			$state,
			array( 1, 2, 3 ),
			$done,
			$ticks,
			static function ( $id ) use ( &$done, &$state ) {
				$done[] = $id;

				if ( 1 === $id ) {
					$state['status'] = 'stopped';
				}

				return array(
					'ok'      => true,
					'message' => '',
				);
			}
		);

		$job->start( 1700000000 );
		$after = $job->tick( 1700000001 );

		$this->assertSame( array( 1 ), $done );
		$this->assertSame( 'stopped', $after['status'] );
		$this->assertSame( 'stopped', $job->state()['status'] );
	}

	/**
	 * Builds the command around a job.
	 *
	 * @param array $log   Receives the lines.
	 * @param array $fail  Receives the errors.
	 * @param array $calls Receives the calls of the doubles.
	 * @param array $state The state of the job.
	 * @return PagesCommand
	 */
	private function command( array &$log, array &$fail, array &$calls, array &$state ): PagesCommand {
		$done  = array();
		$ticks = array();

		return new PagesCommand(
			function ( $foreground ) use ( &$state, &$done, &$ticks, &$calls ) {
				$calls[] = 'job:' . ( $foreground ? 'fg' : 'cron' );

				return $this->job( $state, array( 1, 2, 3 ), $done, $ticks );
			},
			static function ( $id ) use ( &$calls ) {
				$calls[] = 'one:' . $id;

				return 3 === $id ? array(
					'ok'      => false,
					'message' => 'The post is not published.',
				) : array(
					'ok'      => true,
					'message' => '',
				);
			},
			static function ( $on ) use ( &$calls ) {
				$calls[] = 'source:' . ( $on ? 'on' : 'off' );

				return true;
			},
			static fn() => 1700000000,
			static function ( $line ) use ( &$log ) {
				$log[] = $line;
			},
			static function ( $line ) use ( &$log ) {
				$log[] = 'OK ' . $line;
			},
			static function ( $line ) use ( &$fail ) {
				$fail[] = $line;
			}
		);
	}

	/**
	 * The command: the source, one post, the whole job in the foreground, the status with its failures, stop and resume by WP-Cron.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$log   = array();
		$fail  = array();
		$calls = array();
		$state = array();
		$cmd   = $this->command( $log, $fail, $calls, $state );

		$cmd->enable( array(), array() );
		$cmd->disable( array(), array() );
		$this->assertSame( array( 'source:on', 'source:off' ), $calls );
		$this->assertContains( 'OK The source page is on.', $log );

		$log = array();
		$cmd->run( array(), array( 'post' => 2 ) );
		$cmd->run( array(), array( 'post' => 3 ) );
		$this->assertSame( array( 'OK Post 2: read.' ), $log );
		$this->assertSame( array( 'Post 3: The post is not published.' ), $fail );

		$log = array();
		$cmd->run( array(), array() );
		$this->assertSame( 'done', $state['status'] );
		$this->assertContains( '3/3 read, 1 failed', $log );
		$this->assertContains( 'OK The job is done: 2 read, 1 failed.', $log );

		$log = array();
		$cmd->status( array(), array() );
		$this->assertSame( 'Status: done', $log[0] );
		$this->assertContains( '  failed 3: The page answered with the status 404.', $log );

		$cmd->start( array(), array() );
		$this->assertSame( 'running', $state['status'] );
		$cmd->stop( array(), array() );
		$this->assertSame( 'stopped', $state['status'] );
		$cmd->resume( array(), array() );
		$this->assertSame( 'running', $state['status'] );
		$this->assertContains( 'job:cron', $calls );
	}
}
