<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the predicate registry.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Datatype\DatatypeRegistry;
use Otherguise\Triples\Entity\EntityType;
use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Predicate\PredicateDefinition;
use Otherguise\Triples\Predicate\PredicateRegistry;
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
	 * @param array|null    $entity_types Entity type registry, or null for the built-ins.
	 * @return PredicateRegistry
	 */
	private function registry( $initializer = null, $entity_types = null ) {
		return new PredicateRegistry( $entity_types ?? EntityTypeRegistry::with_builtins(), DatatypeRegistry::with_builtins(), $initializer );
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
	 * Subject types must be registered entity types.
	 *
	 * @return void
	 */
	public function test_subject_types_must_be_registered_entity_types(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Predicate "triples/related-to" uses the unknown entity type "ghost".' );

		$this->registry()->register( $this->definition( array( 'subject_types' => array( 'post', 'ghost' ) ) ) );
	}

	/**
	 * Object types may be entity types or datatypes.
	 *
	 * @return void
	 */
	public function test_object_types_may_be_entity_types_or_datatypes(): void {
		$registry = $this->registry();
		$registry->register( $this->definition( array( 'object_types' => array( 'post', 'integer' ) ) ) );

		$this->assertSame( array( 'post', 'integer' ), $registry->get( 'triples/related-to' )->object_types() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Predicate "triples/other" uses the unknown type "ghost".' );

		$registry->register(
			$this->definition(
				array(
					'slug'         => 'triples/other',
					'object_types' => array( 'ghost' ),
				)
			)
		);
	}

	/**
	 * A literal cannot be a subject.
	 *
	 * @return void
	 */
	public function test_a_literal_cannot_be_a_subject(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'the literal type "integer" cannot be a subject' );

		$this->registry()->register( $this->definition( array( 'subject_types' => array( 'integer' ) ) ) );
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
					'object_types'  => array( 'integer' ),
				)
			)
		);
	}

	/**
	 * A predicate may qualify through the target or through itself.
	 *
	 * @return void
	 */
	public function test_a_predicate_may_qualify_through_the_target_or_through_itself(): void {
		$registry = $this->registry();
		$registry->register(
			$this->definition(
				array(
					'slug'         => 'books/contains',
					'qualified_by' => array( 'modes/mode' ),
				)
			)
		);
		$registry->register(
			$this->definition(
				array(
					'slug'          => 'modes/mode',
					'subject_types' => array( 'statement' ),
				)
			)
		);
		$registry->register(
			$this->definition(
				array(
					'slug'          => 'modes/note',
					'subject_types' => array( 'statement' ),
					'qualifies'     => array( 'books/contains' ),
				)
			)
		);
		$registry->register(
			$this->definition(
				array(
					'slug'          => 'triples/position',
					'subject_types' => array( 'statement' ),
					'qualifies'     => array( '*' ),
				)
			)
		);
		$registry->register( $this->definition( array( 'slug' => 'media/caption' ) ) );

		$this->assertTrue( $registry->can_qualify( 'modes/mode', 'books/contains' ), 'The target lists the qualifier.' );
		$this->assertTrue( $registry->can_qualify( 'modes/note', 'books/contains' ), 'The qualifier lists the target.' );
		$this->assertTrue( $registry->can_qualify( 'triples/position', 'media/caption' ), 'The wildcard qualifies anything.' );
		$this->assertFalse( $registry->can_qualify( 'modes/mode', 'media/caption' ) );
		$this->assertFalse( $registry->can_qualify( 'modes/note', 'media/caption' ) );
	}

	/**
	 * References may point to predicates registered later.
	 *
	 * @return void
	 */
	public function test_references_may_point_to_predicates_registered_later(): void {
		$registry = $this->registry();
		$registry->register(
			$this->definition(
				array(
					'slug'         => 'books/contains',
					'qualified_by' => array( 'modes/mode' ),
				)
			)
		);
		$registry->register(
			$this->definition(
				array(
					'slug'          => 'modes/mode',
					'subject_types' => array( 'statement' ),
				)
			)
		);

		$this->assertTrue( $registry->can_qualify( 'modes/mode', 'books/contains' ) );
	}

	/**
	 * An unknown reference is reported when the registry is read.
	 *
	 * @return void
	 */
	public function test_an_unknown_reference_is_reported_when_the_registry_is_read(): void {
		$registry = $this->registry();
		$registry->register(
			$this->definition(
				array(
					'slug'         => 'books/contains',
					'qualified_by' => array( 'modes/mode' ),
				)
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Predicate "books/contains" is qualified by the unknown predicate "modes/mode".' );

		$registry->all();
	}

	/**
	 * An unknown qualified target is reported when the registry is read.
	 *
	 * @return void
	 */
	public function test_an_unknown_qualified_target_is_reported_when_the_registry_is_read(): void {
		$registry = $this->registry();
		$registry->register(
			$this->definition(
				array(
					'slug'          => 'modes/mode',
					'subject_types' => array( 'statement' ),
					'qualifies'     => array( 'books/contains' ),
				)
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Predicate "modes/mode" qualifies the unknown predicate "books/contains".' );

		$registry->get( 'modes/mode' );
	}

	/**
	 * A qualifier must accept a statement as subject.
	 *
	 * @return void
	 */
	public function test_a_qualifier_must_accept_a_statement_as_subject(): void {
		$registry = $this->registry();
		$registry->register(
			$this->definition(
				array(
					'slug'         => 'books/contains',
					'qualified_by' => array( 'modes/mode' ),
				)
			)
		);
		$registry->register(
			$this->definition(
				array(
					'slug'          => 'modes/mode',
					'subject_types' => array( 'post' ),
				)
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Predicate "modes/mode" is used as a qualifier but "statement" is not among its subject types.' );

		$registry->all();
	}

	/**
	 * A qualifier without subject types is accepted.
	 *
	 * @return void
	 */
	public function test_a_qualifier_without_subject_types_is_accepted(): void {
		$registry = $this->registry();
		$registry->register(
			$this->definition(
				array(
					'slug'         => 'books/contains',
					'qualified_by' => array( 'modes/mode' ),
				)
			)
		);
		$registry->register( $this->definition( array( 'slug' => 'modes/mode' ) ) );

		$this->assertTrue( $registry->can_qualify( 'modes/mode', 'books/contains' ) );
	}

	/**
	 * The references are checked again after a late registration.
	 *
	 * @return void
	 */
	public function test_the_references_are_checked_again_after_a_late_registration(): void {
		$registry = $this->registry();
		$registry->register(
			$this->definition(
				array(
					'slug'          => 'modes/mode',
					'subject_types' => array( 'statement' ),
				)
			)
		);
		$registry->all();
		$registry->register(
			$this->definition(
				array(
					'slug'         => 'books/contains',
					'qualified_by' => array( 'modes/ghost' ),
				)
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unknown predicate "modes/ghost"' );

		$registry->all();
	}

	/**
	 * An entity type and a datatype cannot share a name.
	 *
	 * @return void
	 */
	public function test_an_entity_type_and_a_datatype_cannot_share_a_name(): void {
		$entity_types = EntityTypeRegistry::with_builtins();
		$entity_types->register( EntityType::positive_integer( 'integer', 'Clashing type' ) );

		$registry = $this->registry( null, $entity_types );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The name "integer" is used by both an entity type and a datatype.' );

		$registry->all();
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
				$registry->register(
					$this->definition(
						array(
							'slug'         => 'books/contains',
							'qualified_by' => array( 'modes/mode' ),
						)
					)
				);
				$this->assertFalse( $registry->has( 'books/contains' ) && false, 'Reading during the initialization does not check the references.' );
				$registry->register(
					$this->definition(
						array(
							'slug'          => 'modes/mode',
							'subject_types' => array( 'statement' ),
						)
					)
				);
			}
		);

		$this->assertSame( 0, $calls );
		$this->assertTrue( $registry->has( 'books/contains' ) );
		$registry->register( $this->definition( array( 'slug' => 'modes/has-variant' ) ) );
		$registry->all();

		$this->assertSame( 1, $calls );
		$this->assertSame( array( 'books/contains', 'modes/mode', 'modes/has-variant' ), array_keys( $registry->all() ) );
	}
}
