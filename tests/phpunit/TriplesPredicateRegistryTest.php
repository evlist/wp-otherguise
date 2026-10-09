<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the predicate registry.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Predicate\PredicateDefinition;
use Otherguise\Triples\Predicate\PredicateRegistry;
use Otherguise\Triples\Qualifier\QualifierTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the predicate registry.
 *
 * @covers \Otherguise\Triples\Predicate\PredicateRegistry
 */
class TriplesPredicateRegistryTest extends TestCase {

	/**
	 * Builds a registry with the built-in types.
	 *
	 * @param callable|null $initializer Initializer.
	 * @return PredicateRegistry
	 */
	private function registry( $initializer = null ) {
		return new PredicateRegistry( EntityTypeRegistry::with_builtins(), QualifierTypeRegistry::with_builtins(), $initializer );
	}

	/**
	 * Builds a definition.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return PredicateDefinition
	 */
	private function definition( array $overrides = array() ) {
		return PredicateDefinition::from_array(
			array_merge(
				array(
					'slug'  => 'triples/related-to',
					'label' => 'Related to',
				),
				$overrides
			)
		);
	}

	/**
	 * A predicate is registered and found.
	 *
	 * @return void
	 */
	public function test_a_predicate_is_registered_and_found(): void {
		$registry = $this->registry();
		$registry->register( $this->definition() );

		$this->assertTrue( $registry->has( 'triples/related-to' ) );
		$this->assertFalse( $registry->has( 'triples/other' ) );
		$this->assertSame( 'Related to', $registry->get( 'triples/related-to' )->label() );
	}

	/**
	 * All returns the predicates in registration order.
	 *
	 * @return void
	 */
	public function test_all_returns_the_predicates_in_registration_order(): void {
		$registry = $this->registry();
		$registry->register( $this->definition( array( 'slug' => 'books/contains' ) ) );
		$registry->register( $this->definition( array( 'slug' => 'modes/has-variant' ) ) );
		$registry->register( $this->definition( array( 'slug' => 'triples/related-to' ) ) );

		$this->assertSame( array( 'books/contains', 'modes/has-variant', 'triples/related-to' ), array_keys( $registry->all() ) );
	}

	/**
	 * A predicate cannot be registered twice.
	 *
	 * @return void
	 */
	public function test_a_predicate_cannot_be_registered_twice(): void {
		$registry = $this->registry();
		$registry->register( $this->definition() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Predicate "triples/related-to" is already registered.' );

		$registry->register( $this->definition() );
	}

	/**
	 * An unknown predicate is reported.
	 *
	 * @return void
	 */
	public function test_an_unknown_predicate_is_reported(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown predicate "triples/ghost".' );

		$this->registry()->get( 'triples/ghost' );
	}

	/**
	 * The entity types must be registered.
	 *
	 * @return void
	 */
	public function test_the_entity_types_must_be_registered(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Predicate "triples/related-to" uses the unknown entity type "ghost".' );

		$this->registry()->register( $this->definition( array( 'subject_types' => array( 'post', 'ghost' ) ) ) );
	}

	/**
	 * The qualifier types must be registered.
	 *
	 * @return void
	 */
	public function test_the_qualifier_types_must_be_registered(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Predicate "triples/related-to" uses the unknown qualifier type "mode".' );

		$this->registry()->register(
			$this->definition(
				array(
					'qualifiers' => array(
						array(
							'name' => 'mode',
							'type' => 'mode',
						),
					),
				)
			)
		);
	}

	/**
	 * The options of a qualifier are checked by its type.
	 *
	 * @return void
	 */
	public function test_the_options_of_a_qualifier_are_checked_by_its_type(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->registry()->register(
			$this->definition(
				array(
					'qualifiers' => array(
						array(
							'name' => 'mode',
							'type' => 'enum',
						),
					),
				)
			)
		);
	}

	/**
	 * A symmetric predicate relates the same kinds of things.
	 *
	 * @return void
	 */
	public function test_a_symmetric_predicate_relates_the_same_kinds_of_things(): void {
		$registry = $this->registry();
		$registry->register(
			$this->definition(
				array(
					'slug'          => 'triples/same-as',
					'symmetric'     => true,
					'subject_types' => array( 'post', 'term' ),
					'object_types'  => array( 'term', 'post' ),
				)
			)
		);

		$this->assertTrue( $registry->get( 'triples/same-as' )->is_symmetric() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'symmetric' );

		$registry->register(
			$this->definition(
				array(
					'slug'          => 'triples/bad',
					'symmetric'     => true,
					'subject_types' => array( 'post' ),
					'object_types'  => array( 'term' ),
				)
			)
		);
	}

	/**
	 * The initializer runs once and only when the registry is used.
	 *
	 * @return void
	 */
	public function test_the_initializer_runs_once_and_only_when_the_registry_is_used(): void {
		$calls    = 0;
		$registry = $this->registry(
			function ( PredicateRegistry $registry ) use ( &$calls ) {
				++$calls;
				$registry->register( $this->definition( array( 'slug' => 'books/contains' ) ) );
			}
		);

		$this->assertSame( 0, $calls );
		$this->assertTrue( $registry->has( 'books/contains' ) );
		$registry->register( $this->definition( array( 'slug' => 'modes/has-variant' ) ) );
		$registry->all();

		$this->assertSame( 1, $calls );
		$this->assertSame( array( 'books/contains', 'modes/has-variant' ), array_keys( $registry->all() ) );
	}
}
