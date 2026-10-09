<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the writing calls of the statement service, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\DuplicateStatementException;
use Otherguise\Triples\Storage\StatementQuery;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';

/**
 * Writing: triple, create, remove, delete, replace, transaction, check, limits, symmetry and qualification rules.
 *
 * @covers \Otherguise\Triples\Statements
 * @covers \Otherguise\Triples\Service\StatementValidator
 * @covers \Otherguise\Triples\Service\StatementReader
 */
class TriplesStatementsWriteDbTest extends Otherguise_Test_Database_Case {

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
	 * Counts all the statements.
	 *
	 * @return int
	 */
	private function total() {
		return $this->module->store()->count( new StatementQuery() );
	}

	/**
	 * Triple stores a statement and returns the same one the second time.
	 *
	 * @return void
	 */
	public function test_triple_is_idempotent(): void {
		$first  = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$second = $this->statements->triple( Ref::post( 12 ), 'media/illustrated-by', Ref::attachment( 88 ) );

		$this->assertSame( $first->id(), $second->id() );
		$this->assertSame( 'post:12', (string) $first->subject() );
		$this->assertSame( 'attachment:88', (string) $first->object() );
		$this->assertNotNull( $first->created_gmt() );
		$this->assertSame( 1, $this->total() );
	}

	/**
	 * Create is strict: the duplicate carries the existing statement.
	 *
	 * @return void
	 */
	public function test_create_is_strict(): void {
		$first = $this->statements->create( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );

		try {
			$this->statements->create( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
			$this->fail( 'The duplicate should have been refused.' );
		} catch ( DuplicateStatementException $duplicate ) {
			$this->assertSame( $first->id(), $duplicate->existing()->id() );
		}

		$this->assertSame( 1, $this->total() );
	}

	/**
	 * Check writes nothing and reports a duplicate.
	 *
	 * @return void
	 */
	public function test_check_writes_nothing(): void {
		$this->assertTrue( $this->statements->check( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) ) );
		$this->assertSame( 0, $this->total() );

		$this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$this->expectException( DuplicateStatementException::class );
		$this->statements->check( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
	}

	/**
	 * A statement about a statement: the link is the subject, the mode the object.
	 *
	 * @return void
	 */
	public function test_a_statement_about_a_statement(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$mode = $this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) );

		$this->assertSame( 'statement:' . $link->id(), (string) $mode->subject() );
		$this->assertSame( 'mode:print', (string) $mode->object() );
		$this->assertSame( $mode->id(), $this->statements->find_by_triple( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) )->id() );
	}

	/**
	 * Only the qualifiers declared for a predicate are allowed, and the statement must exist.
	 *
	 * @return void
	 */
	public function test_the_qualification_rule(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );

		$this->assertSame( 'qualification_not_allowed', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->triple( $link, 'books/pages', 2 ) ) );
		$this->assertSame( 'subject_missing', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->triple( new EntityRef( 'statement', '999' ), 'modes/mode', new EntityRef( 'mode', 'web' ) ) ) );
		$this->assertSame( 'object_missing', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'ghost' ) ) ) );
		$this->assertSame( 1, $this->total() );

		$position = $this->statements->triple( $link, 'triples/position', 5 );
		$this->assertSame( 'integer:5', $position->object()->type() . ':' . $position->object()->key() );
	}

	/**
	 * Literals: scalars are read as the only datatype, the value is normalized, a Literal names the datatype.
	 *
	 * @return void
	 */
	public function test_literals_are_normalized(): void {
		$tag  = $this->statements->triple( get_post( 12 ), 'test/tag', 'Pyrénées' );
		$note = $this->statements->triple( get_post( 12 ), 'test/note', new Literal( 'integer', '007' ) );

		$this->assertSame( 'string', $tag->object()->type() );
		$this->assertSame( 'Pyrénées', $tag->object()->key() );
		$this->assertSame( 'integer', $note->object()->type() );
		$this->assertSame( '7', $note->object()->key() );
		$this->assertSame( $note->id(), $this->statements->triple( get_post( 12 ), 'test/note', new Literal( 'integer', '7' ) )->id() );
	}

	/**
	 * Limits per subject and per object.
	 *
	 * @return void
	 */
	public function test_the_limits(): void {
		$this->statements->triple( get_post( 12 ), 'test/owner', get_userdata( 3 ) );

		$this->assertSame( 'too_many_objects', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->triple( get_post( 12 ), 'test/owner', get_userdata( 4 ) ) ) );
		$this->assertSame( 'too_many_objects', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->check( get_post( 12 ), 'test/owner', get_userdata( 4 ) ) ) );

		$this->statements->triple( get_post( 13 ), 'test/owner', get_userdata( 3 ) );
		$this->statements->triple( new WP_Post( 12 ), 'test/owner', get_userdata( 3 ) );
		$this->assertSame( 2, $this->total() );

		otherguise_test_wp_objects( array( new WP_Post( 12 ), new WP_Post( 13 ), new WP_Post( 14 ), new WP_User( 3 ), new WP_User( 4 ) ) );

		$this->assertSame( 'too_many_subjects', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->triple( get_post( 14 ), 'test/owner', get_userdata( 3 ) ) ) );
		$this->assertSame( 2, $this->total() );
	}

	/**
	 * A symmetric predicate is stored once whichever way it is given, and the limit counts both ends.
	 *
	 * @return void
	 */
	public function test_symmetric_predicates(): void {
		$one = $this->statements->triple( get_userdata( 4 ), 'test/friend', get_userdata( 3 ) );
		$two = $this->statements->triple( get_userdata( 3 ), 'test/friend', get_userdata( 4 ) );

		$this->assertSame( $one->id(), $two->id() );
		$this->assertSame( 'user:3', (string) $one->subject() );
		$this->assertSame( 1, $this->total() );

		$this->statements->triple( get_userdata( 5 ), 'test/friend', get_userdata( 3 ) );

		$this->assertSame( 'too_many_objects', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->triple( get_userdata( 3 ), 'test/friend', get_userdata( 3 ) ) ) );
	}

	/**
	 * Remove deletes by triple, with what is said about the statement.
	 *
	 * @return void
	 */
	public function test_remove_deletes_the_dependents(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$mode = $this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) );
		$this->statements->triple( $mode, 'triples/position', 1 );

		$this->assertSame( 0, $this->statements->remove( get_post( 12 ), 'media/illustrated-by', get_post( 90 ) ) );
		$this->assertSame( 2, $this->statements->remove( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) ) );
		$this->assertSame( 1, $this->total() );
		$this->assertSame( 1, $this->statements->remove( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) ) );
		$this->assertSame( 0, $this->total() );
	}

	/**
	 * Delete takes a statement or an id.
	 *
	 * @return void
	 */
	public function test_delete_takes_a_statement_or_an_id(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'web' ) );
		$other = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 90 ) );

		$this->assertSame( 2, $this->statements->delete( $link ) );
		$this->assertSame( 1, $this->statements->delete( $other->id() ) );
		$this->assertSame( 0, $this->total() );
	}
}
