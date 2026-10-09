<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of replace, transactions and the reads of the statement service, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\StatementQuery;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';

/**
 * Replace, transactions, match, objects_of, subjects_of, qualifications_of and resolve.
 *
 * @covers \Otherguise\Triples\Statements
 * @covers \Otherguise\Triples\Service\EntityResolver
 * @covers \Otherguise\Triples\Service\StatementReader
 */
class TriplesStatementsReadDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Service.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Module.
	 *
	 * @var \Otherguise\Triples\Module
	 */
	private $module;

	/**
	 * Builds the service on the test database.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->module     = Otherguise_Test_Fixtures::module( $this->wpdb );
		$this->statements = $this->module->statements();
	}

	/**
	 * Returns the ids of some statements.
	 *
	 * @param Statement[] $statements Statements.
	 * @return int[]
	 */
	private function ids( array $statements ) {
		return array_map( static fn( Statement $statement ) => $statement->id(), $statements );
	}

	/**
	 * Replace keeps one value, and the statement that already holds the wanted value with its qualifications.
	 *
	 * @return void
	 */
	public function test_replace_keeps_a_single_value(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );

		$five = $this->statements->replace( $link, 'triples/position', 5 );
		$one  = $this->statements->replace( $link, 'triples/position', 1 );

		$this->assertNotSame( $five->id(), $one->id() );
		$this->assertSame( array( $one->id() ), $this->ids( $this->statements->objects_of( $link, 'triples/position' ) ) );

		$this->assertSame( $one->id(), $this->statements->replace( $link, 'triples/position', 1 )->id() );
	}

	/**
	 * Nothing is changed when the new value is refused.
	 *
	 * @return void
	 */
	public function test_replace_changes_nothing_when_the_new_value_is_refused(): void {
		$owner = $this->statements->triple( get_post( 12 ), 'test/owner', get_userdata( 3 ) );

		$this->assertSame( 'object_missing', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->replace( get_post( 12 ), 'test/owner', Ref::user( 99 ) ) ) );
		$this->assertSame( array( $owner->id() ), $this->ids( $this->statements->objects_of( get_post( 12 ), 'test/owner' ) ) );
	}

	/**
	 * Replace removes the qualifications of the replaced statement.
	 *
	 * @return void
	 */
	public function test_replace_removes_what_was_said_about_the_old_value(): void {
		$owner = $this->statements->triple( get_post( 12 ), 'test/owner', get_userdata( 3 ) );
		$this->statements->triple( $owner, 'triples/position', 4 );

		$this->statements->replace( get_post( 12 ), 'test/owner', get_userdata( 4 ) );

		$this->assertSame( 'user:4', (string) $this->statements->objects_of( get_post( 12 ), 'test/owner' )[0]->object() );
		$this->assertSame( 1, $this->module->store()->count( new StatementQuery() ) );
		$this->assertSame( 0, $this->module->store()->count( ( new StatementQuery() )->with_predicates( array( 'triples/position' ) ) ) );
	}

	/**
	 * A failure rolls everything back, also when the calls inside use their own transactions.
	 *
	 * @return void
	 */
	public function test_transaction_rolls_everything_back(): void {
		try {
			$this->statements->transaction(
				function () {
					$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
					$this->statements->replace( $link, 'triples/position', 3 );
					$this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'ghost' ) );
				}
			);
			$this->fail( 'The failure should have gone through.' );
		} catch ( \Otherguise\Triples\Service\InvalidStatementException $problem ) {
			$this->assertSame( 'object_missing', $problem->error_code() );
		}

		$this->assertSame( 0, $this->module->store()->count( new StatementQuery() ) );
	}

	/**
	 * A transaction stores everything when nothing fails and returns the result of its work.
	 *
	 * @return void
	 */
	public function test_transaction_stores_everything(): void {
		$link = $this->statements->transaction(
			function () {
				$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 91 ) );
				$this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'web' ) );
				$this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) );

				return $link;
			}
		);

		$this->assertSame( 3, $this->module->store()->count( new StatementQuery() ) );
		$this->assertSame( 2, count( $this->statements->objects_of( $link, 'modes/mode' ) ) );
	}

	/**
	 * Match with wildcards; subjects_of and objects_of are shortcuts.
	 *
	 * @return void
	 */
	public function test_match_with_wildcards(): void {
		$a = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$b = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 90 ) );
		$c = $this->statements->triple( get_post( 13 ), 'media/illustrated-by', get_post( 88 ) );
		$d = $this->statements->triple( get_post( 12 ), 'books/contains', get_post( 13 ) );

		$this->assertSame( array( $a->id(), $b->id(), $c->id(), $d->id() ), $this->ids( $this->statements->match() ) );
		$this->assertSame( array( $a->id(), $b->id(), $d->id() ), $this->ids( $this->statements->match( get_post( 12 ) ) ) );
		$this->assertSame( array( $a->id(), $b->id() ), $this->ids( $this->statements->objects_of( get_post( 12 ), 'media/illustrated-by' ) ) );
		$this->assertSame( array( $a->id(), $c->id() ), $this->ids( $this->statements->subjects_of( get_post( 88 ), 'media/illustrated-by' ) ) );
		$this->assertSame( array( $a->id(), $c->id() ), $this->ids( $this->statements->match( null, null, Ref::attachment( 88 ) ) ) );
		$this->assertSame( array( $c->id() ), $this->ids( $this->statements->match( get_post( 13 ), 'media/illustrated-by', get_post( 88 ) ) ) );
		$this->assertSame( array(), $this->ids( $this->statements->match( get_post( 13 ), 'books/contains' ) ) );
	}

	/**
	 * A scalar object in a pattern needs a predicate and is read as a literal.
	 *
	 * @return void
	 */
	public function test_match_with_a_literal_object(): void {
		$link     = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$position = $this->statements->triple( $link, 'triples/position', 5 );

		$this->assertSame( array( $position->id() ), $this->ids( $this->statements->match( null, 'triples/position', 5 ) ) );
		$this->assertSame( array(), $this->ids( $this->statements->match( null, 'triples/position', 6 ) ) );
		$this->assertSame( 'ambiguous_literal', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->match( null, null, 5 ) ) );
	}

	/**
	 * A symmetric statement is found from either end and oriented as asked.
	 *
	 * @return void
	 */
	public function test_symmetric_statements_are_found_from_both_ends(): void {
		$friend = $this->statements->triple( get_userdata( 4 ), 'test/friend', get_userdata( 3 ) );

		$from_three = $this->statements->objects_of( get_userdata( 3 ), 'test/friend' );
		$from_four  = $this->statements->objects_of( get_userdata( 4 ), 'test/friend' );
		$to_four    = $this->statements->subjects_of( get_userdata( 4 ), 'test/friend' );
		$both       = $this->statements->match( get_userdata( 4 ), 'test/friend', get_userdata( 3 ) );

		$this->assertSame( array( $friend->id() ), $this->ids( $from_three ) );
		$this->assertSame( 'user:4', (string) $from_three[0]->object() );
		$this->assertSame( 'user:3', (string) $from_four[0]->object() );
		$this->assertSame( 'user:3', (string) $to_four[0]->subject() );
		$this->assertSame( array( $friend->id() ), $this->ids( $both ) );
		$this->assertSame( 'user:4', (string) $both[0]->subject() );
		$this->assertSame( array( $friend->id() ), $this->ids( $this->statements->match( get_userdata( 3 ) ) ) );
		$this->assertSame( $friend->id(), $this->statements->find_by_triple( get_userdata( 3 ), 'test/friend', get_userdata( 4 ) )->id() );
	}

	/**
	 * The statements about several statements are read in one query.
	 *
	 * @return void
	 */
	public function test_qualifications_are_read_in_one_query(): void {
		$a = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$b = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 90 ) );
		$c = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 91 ) );
		$this->statements->triple( $a, 'modes/mode', new EntityRef( 'mode', 'web' ) );
		$this->statements->triple( $a, 'modes/mode', new EntityRef( 'mode', 'print' ) );
		$this->statements->triple( $b, 'modes/mode', new EntityRef( 'mode', 'web' ) );
		$this->statements->triple( $a, 'triples/position', 2 );

		$before = count( $this->wpdb->queries );
		$found  = $this->statements->qualifications_of( array( $a, $b, $c->id() ) );

		$this->assertSame( 1, count( $this->wpdb->queries ) - $before );
		$this->assertSame( array( $a->id(), $b->id() ), array_keys( $found ) );
		$this->assertSame( 2, count( $found[ $a->id() ]['modes/mode'] ) );
		$this->assertSame( 1, count( $found[ $a->id() ]['triples/position'] ) );
		$this->assertSame( 1, count( $found[ $b->id() ]['modes/mode'] ) );
	}

	/**
	 * The way back: the reference of a statement turned into a WordPress object.
	 *
	 * @return void
	 */
	public function test_resolve_returns_the_wordpress_object(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );

		$this->assertInstanceOf( WP_Post::class, $this->statements->resolve( $link->object() ) );
		$this->assertSame( 88, $this->statements->resolve( $link->object() )->ID );
		$this->assertInstanceOf( WP_Post::class, $this->statements->resolve( $link->subject() ) );
		$this->assertSame( $link->id(), $this->statements->resolve( $link->as_entity() )->id() );
		$this->assertNull( $this->statements->resolve( Ref::post( 99 ) ) );
		$this->assertNull( $this->statements->resolve( new EntityRef( 'mode', 'web' ) ) );
	}
}
