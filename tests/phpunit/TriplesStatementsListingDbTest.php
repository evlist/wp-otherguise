<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the ordered and scoped lists, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\Statement;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';

/**
 * The example of slice 103: four photos A, B, C, D of post 12, in the natural order A, B, C, D. A is in web and print, B in web, C in web
 * and print, D in no mode.
 *
 * @covers \Otherguise\Triples\Service\StatementListing
 * @covers \Otherguise\Triples\Service\PinnedOrder
 */
class TriplesStatementsListingDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Service.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * The links of the four photos, by letter.
	 *
	 * @var Statement[]
	 */
	private $links = array();

	/**
	 * Builds the example.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->statements = Otherguise_Test_Fixtures::module( $this->wpdb )->statements();

		foreach ( array(
			'A' => array( 88, array( 'web', 'print' ) ),
			'B' => array( 90, array( 'web' ) ),
			'C' => array( 91, array( 'web', 'print' ) ),
			'D' => array( 94, array() ),
		) as $letter => list( $photo, $modes ) ) {
			$this->links[ $letter ] = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( $photo ) );

			foreach ( $modes as $mode ) {
				$this->statements->triple( $this->links[ $letter ], 'modes/mode', new EntityRef( 'mode', $mode ) );
			}
		}
	}

	/**
	 * Lists the photos of post 12 and returns the letters.
	 *
	 * @param array $options Options of the listing.
	 * @return string
	 */
	private function letters( array $options = array() ) {
		$by_id = array_combine( array_map( static fn( Statement $link ) => $link->id(), $this->links ), array_keys( $this->links ) );
		$list  = $this->statements->listing( get_post( 12 ), 'media/illustrated-by', $options );

		return implode( '', array_map( static fn( Statement $statement ) => $by_id[ $statement->id() ], $list ) );
	}

	/**
	 * Returns the scope option for a mode.
	 *
	 * @param string $mode Mode.
	 * @return array
	 */
	private function scope( $mode ) {
		return array( 'scope' => array( 'modes/mode', new EntityRef( 'mode', $mode ) ) );
	}

	/**
	 * Without options the list is in the order of creation and nothing is filtered, the photos in no mode included.
	 *
	 * @return void
	 */
	public function test_without_scope_nothing_is_filtered(): void {
		$this->assertSame( 'ABCD', $this->letters() );
	}

	/**
	 * A scope keeps the statements that have that mode; the photo with no mode statement belongs to none.
	 *
	 * @return void
	 */
	public function test_a_scope_filters(): void {
		$this->assertSame( 'ABC', $this->letters( $this->scope( 'web' ) ) );
		$this->assertSame( 'AC', $this->letters( $this->scope( 'print' ) ) );
		$this->assertSame( '', $this->letters( $this->scope( 'book' ) ) );
	}

	/**
	 * The natural order is a comparator given by the consumer.
	 *
	 * @return void
	 */
	public function test_the_natural_order_is_a_comparator(): void {
		$reverse = array(
			'natural_order' => static fn( Statement $a, Statement $b ) => $b->id() <=> $a->id(),
		);

		$this->assertSame( 'DCBA', $this->letters( $reverse ) );
		$this->assertSame( 'CBA', $this->letters( $reverse + $this->scope( 'web' ) ) );
	}

	/**
	 * A global pin applies to the list and to every scope.
	 *
	 * @return void
	 */
	public function test_a_global_pin(): void {
		$this->statements->replace( $this->links['C'], 'triples/position', 1 );

		$this->assertSame( 'CABD', $this->letters() );
		$this->assertSame( 'CAB', $this->letters( $this->scope( 'web' ) ) );
		$this->assertSame( 'CA', $this->letters( $this->scope( 'print' ) ) );
	}

	/**
	 * A scope pin changes the order for that scope only and overrides the global pin.
	 *
	 * @return void
	 */
	public function test_a_scope_pin(): void {
		$this->statements->replace( $this->links['C'], 'triples/position', 1 );

		$print = $this->statements->find_by_triple( $this->links['C'], 'modes/mode', new EntityRef( 'mode', 'print' ) );
		$this->statements->replace( $print, 'triples/position', 2 );

		$this->assertSame( 'AC', $this->letters( $this->scope( 'print' ) ) );
		$this->assertSame( 'CAB', $this->letters( $this->scope( 'web' ) ) );
		$this->assertSame( 'CABD', $this->letters() );
	}

	/**
	 * Removing a mode from a link takes it out of that scope, and its scope pin with it.
	 *
	 * @return void
	 */
	public function test_removing_a_mode_removes_the_item_from_the_scope(): void {
		$print = $this->statements->triple( $this->links['A'], 'modes/mode', new EntityRef( 'mode', 'print' ) );
		$this->statements->replace( $print, 'triples/position', 2 );

		$this->assertSame( 2, $this->statements->remove( $this->links['A'], 'modes/mode', new EntityRef( 'mode', 'print' ) ) );
		$this->assertSame( 'C', $this->letters( $this->scope( 'print' ) ) );
		$this->assertSame( 'ABC', $this->letters( $this->scope( 'web' ) ) );
	}

	/**
	 * The same photo in another post has its own modes.
	 *
	 * @return void
	 */
	public function test_modes_belong_to_the_link_not_to_the_photo(): void {
		$other = $this->statements->triple( get_post( 13 ), 'media/illustrated-by', get_post( 90 ) );
		$this->statements->triple( $other, 'modes/mode', new EntityRef( 'mode', 'print' ) );

		$this->assertSame( 'AC', $this->letters( $this->scope( 'print' ) ) );
		$this->assertCount( 1, $this->statements->listing( get_post( 13 ), 'media/illustrated-by', $this->scope( 'print' ) ) );
		$this->assertCount( 0, $this->statements->listing( get_post( 13 ), 'media/illustrated-by', $this->scope( 'web' ) ) );
	}

	/**
	 * Malformed scopes and unknown predicates are refused.
	 *
	 * @return void
	 */
	public function test_bad_arguments_are_refused(): void {
		$this->assertSame( 'unknown_predicate', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->listing( get_post( 12 ), 'nobody/knows' ) ) );
		$this->assertSame( 'unknown_predicate', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->listing( get_post( 12 ), 'media/illustrated-by', array( 'scope' => 'print' ) ) ) );
		$this->assertSame( 'unknown_predicate', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->listing( get_post( 12 ), 'media/illustrated-by', array( 'scope' => array( 'nobody/knows', new EntityRef( 'mode', 'web' ) ) ) ) ) );
		$this->assertSame( 'unknown_entity', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->listing( 12, 'media/illustrated-by' ) ) );
	}
}
