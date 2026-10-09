<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the SQL built by the statement queries.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Storage\StatementQuery;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the SQL built by the statement queries.
 *
 * @covers \Otherguise\Triples\Storage\StatementQuery
 */
class TriplesStatementQueryTest extends TestCase {

	/**
	 * A query without criteria reads everything in id order.
	 *
	 * @return void
	 */
	public function test_a_query_without_criteria_reads_everything_in_id_order(): void {
		list( $sql, $args ) = ( new StatementQuery() )->select( 'wp_triples_statements' );

		$this->assertSame( 'SELECT s.id, s.subject_type, s.subject_id, s.predicate, s.object_type, s.object_id, s.created_gmt, s.updated_gmt FROM wp_triples_statements s ORDER BY s.id ASC', $sql );
		$this->assertSame( array(), $args );
	}

	/**
	 * The subject the object and the predicates are conditions.
	 *
	 * @return void
	 */
	public function test_the_subject_the_object_and_the_predicates_are_conditions(): void {
		$query = ( new StatementQuery() )
			->with_subject( EntityRef::parse( 'post:12' ) )
			->with_object( new Literal( 'integer', '5' ) )
			->with_predicates( array( 'a/b', 'c/d', 'a/b' ) );

		list( $sql, $args ) = $query->select( 'wp_t' );

		$this->assertStringContainsString( ' WHERE s.subject_type = %s AND s.subject_id = %s AND s.object_type = %s AND s.object_id = %s AND s.predicate IN (%s,%s) ORDER BY', $sql );
		$this->assertSame( array( 'post', '12', 'integer', '5', 'a/b', 'c/d' ), $args );
	}

	/**
	 * A qualification is an exists on the statements about the statement.
	 *
	 * @return void
	 */
	public function test_a_qualification_is_an_exists_on_the_statements_about_the_statement(): void {
		$query = ( new StatementQuery() )->qualified( 'modes/mode', array( EntityRef::parse( 'mode:print' ), EntityRef::parse( 'mode:web' ) ) );

		list( $sql, $args ) = $query->select( 'wp_t' );

		$this->assertStringContainsString(
			'EXISTS ( SELECT 1 FROM wp_t q WHERE q.subject_type = %s AND q.subject_id = CAST( s.id AS BINARY ) AND q.predicate = %s AND ( ( q.object_type = %s AND q.object_id = %s ) OR ( q.object_type = %s AND q.object_id = %s ) ) )',
			$sql
		);
		$this->assertStringNotContainsString( 'NOT EXISTS', $sql );
		$this->assertSame( array( 'statement', 'modes/mode', 'mode', 'print', 'mode', 'web' ), $args );
	}

	/**
	 * A qualification without objects accepts any object.
	 *
	 * @return void
	 */
	public function test_a_qualification_without_objects_accepts_any_object(): void {
		list( $sql, $args ) = ( new StatementQuery() )->qualified( 'modes/mode' )->select( 'wp_t' );

		$this->assertStringContainsString( 'q.predicate = %s )', $sql );
		$this->assertSame( array( 'statement', 'modes/mode' ), $args );
	}

	/**
	 * The absence of a qualification is a not exists.
	 *
	 * @return void
	 */
	public function test_the_absence_of_a_qualification_is_a_not_exists(): void {
		list( $sql, $args ) = ( new StatementQuery() )->unqualified( 'modes/mode' )->select( 'wp_t' );

		$this->assertStringContainsString( 'NOT EXISTS ( SELECT 1 FROM wp_t q WHERE q.subject_type = %s AND q.subject_id = CAST( s.id AS BINARY ) AND q.predicate = %s )', $sql );
		$this->assertSame( array( 'statement', 'modes/mode' ), $args );
	}

	/**
	 * The arguments follow the order of the placeholders.
	 *
	 * @return void
	 */
	public function test_the_arguments_follow_the_order_of_the_placeholders(): void {
		$query = ( new StatementQuery() )
			->qualified( 'modes/mode', array( EntityRef::parse( 'mode:print' ) ) )
			->with_subject( EntityRef::parse( 'post:12' ) )
			->unqualified( 'books/part' )
			->limit( 10, 20 );

		list( $sql, $args ) = $query->select( 'wp_t' );

		$this->assertSame( substr_count( $sql, '%s' ) + substr_count( $sql, '%d' ), count( $args ) );
		$this->assertSame( array( 'post', '12', 'statement', 'modes/mode', 'mode', 'print', 'statement', 'books/part', 10, 20 ), $args );
		$this->assertStringEndsWith( 'ORDER BY s.id ASC LIMIT %d OFFSET %d', $sql );
	}

	/**
	 * The order can be reversed and the paging is bounded.
	 *
	 * @return void
	 */
	public function test_the_order_can_be_reversed_and_the_paging_is_bounded(): void {
		list( $sql, $args ) = ( new StatementQuery() )->descending()->limit( 0, -5 )->select( 'wp_t' );

		$this->assertStringContainsString( 'ORDER BY s.id DESC LIMIT %d OFFSET %d', $sql );
		$this->assertSame( array( 1, 0 ), $args );
	}

	/**
	 * The count has no order and no paging.
	 *
	 * @return void
	 */
	public function test_the_count_has_no_order_and_no_paging(): void {
		list( $sql, $args ) = ( new StatementQuery() )->with_subject( EntityRef::parse( 'post:12' ) )->limit( 5 )->count( 'wp_t' );

		$this->assertSame( 'SELECT COUNT(*) FROM wp_t s WHERE s.subject_type = %s AND s.subject_id = %s', $sql );
		$this->assertSame( array( 'post', '12' ), $args );
	}

	/**
	 * A query is immutable.
	 *
	 * @return void
	 */
	public function test_a_query_is_immutable(): void {
		$base = new StatementQuery();
		$base->with_subject( EntityRef::parse( 'post:12' ) )->limit( 3 );

		list( $sql, $args ) = $base->select( 'wp_t' );

		$this->assertStringNotContainsString( 'WHERE', $sql );
		$this->assertSame( array(), $args );
	}

	/**
	 * A table name with anything but word characters is refused.
	 *
	 * @return void
	 */
	public function test_a_table_name_with_anything_but_word_characters_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		( new StatementQuery() )->select( 'wp_t; DROP TABLE x' );
	}
}
