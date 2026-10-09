<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the cleanup, the events and the cache, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Module;
use Otherguise\Triples\Service\WordPressCleanup;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\StatementQuery;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';

/**
 * Cleanup when things disappear, the events, and the object cache.
 *
 * @covers \Otherguise\Triples\Statements
 * @covers \Otherguise\Triples\Service\EventQueue
 * @covers \Otherguise\Triples\Service\StatementEraser
 * @covers \Otherguise\Triples\Service\WordPressCleanup
 * @covers \Otherguise\Triples\Storage\Cache
 * @covers \Otherguise\Triples\Storage\StatementStore
 */
class TriplesLifecycleDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Events fired, as "kind:id".
	 *
	 * @var ArrayObject
	 */
	private $events;

	/**
	 * Module.
	 *
	 * @var Module
	 */
	private $module;

	/**
	 * Service.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Builds the service on the test database.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->events     = new ArrayObject();
		$this->module     = Otherguise_Test_Fixtures::module( $this->wpdb, $this->events );
		$this->statements = $this->module->statements();
	}

	/**
	 * Counts all the statements.
	 *
	 * @return int
	 */
	private function total() {
		return $this->module->store()->count( new StatementQuery() );
	}

	/**
	 * Returns the events and forgets them.
	 *
	 * @return string[]
	 */
	private function events() {
		$events = $this->events->getArrayCopy();

		$this->events->exchangeArray( array() );

		return $events;
	}

	/**
	 * Deleting a post removes the statements where it is subject or object, and what is said about them, and nothing else.
	 *
	 * @return void
	 */
	public function test_deleting_a_post_removes_what_involves_it(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) );
		$this->statements->triple( get_post( 13 ), 'books/contains', get_post( 12 ) );
		$kept = $this->statements->triple( get_post( 13 ), 'media/illustrated-by', get_post( 88 ) );

		$this->assertSame( 3, $this->statements->forget( Ref::post( 12 ) ) );
		$this->assertSame( 1, $this->total() );
		$this->assertSame( $kept->id(), $this->statements->match()[0]->id() );
	}

	/**
	 * Predicates that keep their statements leave them in place; resolve then finds nothing.
	 *
	 * @return void
	 */
	public function test_a_keep_predicate_leaves_the_statement(): void {
		$log = $this->statements->triple( get_post( 12 ), 'test/log', get_post( 13 ) );
		$this->statements->triple( get_post( 12 ), 'books/contains', get_post( 13 ) );

		$this->assertSame( 1, $this->statements->forget( Ref::post( 13 ) ) );
		$this->assertSame( $log->id(), $this->statements->match()[0]->id() );

		otherguise_test_wp_objects( array( new WP_Post( 12 ) ) );

		$this->assertNull( $this->statements->resolve( $this->statements->find( $log->id() )->object() ) );
	}

	/**
	 * Forgetting something that has no statement deletes nothing.
	 *
	 * @return void
	 */
	public function test_forgetting_nothing(): void {
		$this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );

		$this->assertSame( 0, $this->statements->forget( Ref::post( 99 ) ) );
		$this->assertSame( 0, $this->statements->forget( new EntityRef( 'mode', 'print' ) ) );
		$this->assertSame( 1, $this->total() );
		$this->assertSame( 'unknown_entity', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->forget( 12 ) ) );
	}

	/**
	 * The callbacks registered for WordPress forget the post and the media item of an id, the term and the user.
	 *
	 * @return void
	 */
	public function test_the_wordpress_callbacks(): void {
		$this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$this->statements->triple( get_post( 13 ), 'books/contains', get_post( 12 ) );
		$this->statements->triple( get_post( 13 ), 'test/owner', get_userdata( 3 ) );
		$this->statements->triple( get_post( 13 ), 'test/anything', get_term( 7 ) );

		$cleanup = new WordPressCleanup( $this->statements );

		$cleanup->post( 88 );
		$this->assertSame( 3, $this->total() );

		$cleanup->post( 12 );
		$this->assertSame( 2, $this->total() );

		$cleanup->user( 3 );
		$this->assertSame( 1, $this->total() );

		$cleanup->term( 7 );
		$this->assertSame( 0, $this->total() );
	}

	/**
	 * One event per statement created, none when the triple exists; one per statement deleted, the dependents included.
	 *
	 * @return void
	 */
	public function test_events(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$mode = $this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) );
		$this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) );

		$this->assertSame( array( 'created:' . $link->id(), 'created:' . $mode->id() ), $this->events() );

		$this->statements->delete( $link );

		$this->assertSame( array( 'deleted:' . $link->id(), 'deleted:' . $mode->id() ), $this->events() );

		$this->assertSame( 0, $this->statements->delete( $link ) );
		$this->assertSame( array(), $this->events() );
	}

	/**
	 * The strict form fires an event only when it creates, and a refusal fires nothing.
	 *
	 * @return void
	 */
	public function test_refusals_fire_nothing(): void {
		$this->statements->create( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$this->events();

		Otherguise_Test_Fixtures::refusal( fn() => $this->statements->create( get_post( 12 ), 'media/illustrated-by', get_post( 99 ) ) );
		try {
			$this->statements->create( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		} catch ( \Otherguise\Triples\Storage\DuplicateStatementException $duplicate ) {
			unset( $duplicate );
		}

		$this->assertSame( array(), $this->events() );
	}

	/**
	 * The events of a transaction come after its commit, in order; a rollback fires none.
	 *
	 * @return void
	 */
	public function test_events_follow_the_transaction(): void {
		$this->statements->transaction(
			function () {
				$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
				$this->assertSame( array(), $this->events() );
				$this->statements->replace( $link, 'triples/position', 2 );
			}
		);

		$this->assertCount( 2, $this->events() );

		try {
			$this->statements->transaction(
				function () {
					$this->statements->triple( get_post( 13 ), 'media/illustrated-by', get_post( 90 ) );
					$this->statements->triple( get_post( 13 ), 'media/illustrated-by', get_post( 99 ) );
				}
			);
		} catch ( \Otherguise\Triples\Service\InvalidStatementException $problem ) {
			unset( $problem );
		}

		$this->assertSame( array(), $this->events() );
		$this->assertSame( 2, $this->total() );
	}

	/**
	 * A forget fires the deletion of each statement it removes.
	 *
	 * @return void
	 */
	public function test_forget_fires_the_deletions(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$mode = $this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'web' ) );
		$this->events();

		$this->statements->forget( Ref::attachment( 88 ) );

		$this->assertSame( array( 'deleted:' . $link->id(), 'deleted:' . $mode->id() ), $this->events() );
	}

	/**
	 * Counts the queries sent to the database while a call runs.
	 *
	 * @param callable $call Call.
	 * @return int
	 */
	private function queries_during( $call ) {
		$before = count( $this->wpdb->queries );

		$call();

		return count( $this->wpdb->queries ) - $before;
	}

	/**
	 * The second identical read costs no query; a write brings the queries back.
	 *
	 * @return void
	 */
	public function test_the_cache_absorbs_repeated_reads_until_a_write(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$read = function () use ( $link ) {
			$this->statements->match( get_post( 12 ), 'media/illustrated-by' );
			$this->statements->qualifications_of( array( $link ) );
			$this->statements->find( $link->id() );
			$this->statements->find_by_triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
			$this->module->store()->count( new StatementQuery() );
		};

		$this->assertGreaterThan( 0, $this->queries_during( $read ) );
		$this->assertSame( 0, $this->queries_during( $read ) );

		$this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 90 ) );

		$this->assertGreaterThan( 0, $this->queries_during( $read ) );
		$this->assertCount( 2, $this->statements->match( get_post( 12 ), 'media/illustrated-by' ) );
		$this->assertSame( 0, $this->queries_during( $read ) );

		$this->statements->delete( $link );

		$this->assertGreaterThan( 0, $this->queries_during( $read ) );
		$this->assertCount( 1, $this->statements->match( get_post( 12 ), 'media/illustrated-by' ) );
	}

	/**
	 * An empty result is cached too.
	 *
	 * @return void
	 */
	public function test_empty_results_are_cached(): void {
		$read = fn() => $this->statements->match( get_post( 13 ), 'books/contains' );

		$this->assertSame( array(), $read() );
		$this->assertSame( 0, $this->queries_during( $read ) );
	}

	/**
	 * A rollback brings the database back to what it was and the cache with it.
	 *
	 * @return void
	 */
	public function test_a_rollback_invalidates_the_cache(): void {
		try {
			$this->statements->transaction(
				function () {
					$this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
					$this->assertCount( 1, $this->statements->match( get_post( 12 ), 'media/illustrated-by' ) );

					throw new RuntimeException( 'x' );
				}
			);
		} catch ( RuntimeException $problem ) {
			unset( $problem );
		}

		$this->assertSame( array(), $this->statements->match( get_post( 12 ), 'media/illustrated-by' ) );
		$this->assertSame( 0, $this->total() );
	}

	/**
	 * The commit of a transaction invalidates what was read inside it.
	 *
	 * @return void
	 */
	public function test_a_commit_invalidates_the_cache(): void {
		$this->statements->transaction(
			function () {
				$this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
				$this->statements->match( get_post( 12 ), 'media/illustrated-by' );
			}
		);

		$this->assertGreaterThan( 0, $this->queries_during( fn() => $this->statements->match( get_post( 12 ), 'media/illustrated-by' ) ) );
	}
}
