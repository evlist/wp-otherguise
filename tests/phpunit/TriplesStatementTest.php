<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of statements, literals and nodes.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Storage\Statement;
use PHPUnit\Framework\TestCase;

/**
 * Tests of statements, literals and nodes.
 *
 * @covers \Otherguise\Triples\Entity\Literal
 * @covers \Otherguise\Triples\Entity\EntityRef
 * @covers \Otherguise\Triples\Storage\Statement
 */
class TriplesStatementTest extends TestCase {

	/**
	 * An entity and a literal are nodes with a type and a key.
	 *
	 * @return void
	 */
	public function test_an_entity_and_a_literal_are_nodes_with_a_type_and_a_key(): void {
		$entity  = EntityRef::parse( 'post:12' );
		$literal = new Literal( 'string', 'Le col de Somport' );

		$this->assertSame( 'post', $entity->type() );
		$this->assertSame( '12', $entity->key() );
		$this->assertSame( 'string', $literal->type() );
		$this->assertSame( 'Le col de Somport', $literal->key() );
	}

	/**
	 * Nodes are equal when their kind type and key are the same.
	 *
	 * @return void
	 */
	public function test_nodes_are_equal_when_their_kind_type_and_key_are_the_same(): void {
		$this->assertTrue( ( new Literal( 'integer', '5' ) )->equals( new Literal( 'integer', '5' ) ) );
		$this->assertFalse( ( new Literal( 'integer', '5' ) )->equals( new Literal( 'string', '5' ) ) );
		$this->assertFalse( ( new Literal( 'integer', '5' ) )->equals( new Literal( 'integer', '6' ) ) );
		$this->assertFalse( ( new Literal( 'integer', '5' ) )->equals( new EntityRef( 'integer', '5' ) ), 'A literal is not an entity.' );
		$this->assertFalse( ( new EntityRef( 'post', '5' ) )->equals( new Literal( 'post', '5' ) ) );
	}

	/**
	 * A literal may hold spaces and is limited to 191 bytes.
	 *
	 * @return void
	 */
	public function test_a_literal_may_hold_spaces_and_is_limited_to_191_bytes(): void {
		$this->assertSame( 191, strlen( ( new Literal( 'string', str_repeat( 'a', 191 ) ) )->key() ) );
		$this->assertSame( '', ( new Literal( 'string', '' ) )->key() );

		$this->expectException( InvalidArgumentException::class );

		new Literal( 'string', str_repeat( 'a', 192 ) );
	}

	/**
	 * A literal needs a valid datatype name.
	 *
	 * @return void
	 */
	public function test_a_literal_needs_a_valid_datatype_name(): void {
		$this->expectException( InvalidArgumentException::class );

		new Literal( 'Bad Name', 'x' );
	}

	/**
	 * A statement holds its triple.
	 *
	 * @return void
	 */
	public function test_a_statement_holds_its_triple(): void {
		$statement = new Statement( EntityRef::parse( 'post:12' ), 'media/illustrated-by', EntityRef::parse( 'attachment:88' ) );

		$this->assertNull( $statement->id() );
		$this->assertSame( 'post:12', (string) $statement->subject() );
		$this->assertSame( 'media/illustrated-by', $statement->predicate() );
		$this->assertSame( 'attachment:88', (string) $statement->object() );
		$this->assertNull( $statement->created_gmt() );
	}

	/**
	 * A stored statement can be the subject of another.
	 *
	 * @return void
	 */
	public function test_a_stored_statement_can_be_the_subject_of_another(): void {
		$stored = new Statement( EntityRef::parse( 'post:12' ), 'media/illustrated-by', EntityRef::parse( 'attachment:88' ), 41, '2026-10-09 12:00:00', '2026-10-09 12:00:00' );

		$this->assertSame( 'statement:41', (string) $stored->as_entity() );
		$this->assertSame( '2026-10-09 12:00:00', $stored->created_gmt() );
	}

	/**
	 * A statement that is not stored has no identity.
	 *
	 * @return void
	 */
	public function test_a_statement_that_is_not_stored_has_no_identity(): void {
		$this->expectException( LogicException::class );

		( new Statement( EntityRef::parse( 'post:12' ), 'media/illustrated-by', EntityRef::parse( 'attachment:88' ) ) )->as_entity();
	}

	/**
	 * The predicate slug and the id are checked.
	 *
	 * @return void
	 */
	public function test_the_predicate_slug_and_the_id_are_checked(): void {
		foreach ( array( array( 'illustrated-by', null ), array( 'media/illustrated-by', 0 ), array( 'media/illustrated-by', '5' ) ) as $case ) {
			try {
				new Statement( EntityRef::parse( 'post:12' ), $case[0], EntityRef::parse( 'attachment:88' ), $case[1] );
				$this->fail( 'An invalid statement was accepted.' );
			} catch ( InvalidArgumentException $exception ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * Two statements hold the same triple when all three parts are equal.
	 *
	 * @return void
	 */
	public function test_two_statements_hold_the_same_triple_when_all_three_parts_are_equal(): void {
		$a = new Statement( EntityRef::parse( 'post:12' ), 'media/illustrated-by', EntityRef::parse( 'attachment:88' ) );
		$b = new Statement( EntityRef::parse( 'post:12' ), 'media/illustrated-by', EntityRef::parse( 'attachment:88' ), 41 );
		$c = new Statement( EntityRef::parse( 'post:12' ), 'media/illustrated-by', EntityRef::parse( 'attachment:89' ) );
		$d = new Statement( EntityRef::parse( 'post:12' ), 'media/mentions', EntityRef::parse( 'attachment:88' ) );

		$this->assertTrue( $a->same_triple( $b ) );
		$this->assertFalse( $a->same_triple( $c ) );
		$this->assertFalse( $a->same_triple( $d ) );
	}
}
