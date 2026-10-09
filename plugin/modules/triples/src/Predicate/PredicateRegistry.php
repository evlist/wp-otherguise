<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Registry of predicates.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Predicate;

use Otherguise\Triples\Datatype\DatatypeRegistry;
use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Support\LazyRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Predicates by slug.
 *
 * A predicate is checked against the entity types and the datatypes when it is registered. The references between predicates
 * (`qualified_by`, `qualifies`) and the namespace shared by entity types and datatypes are checked once, the first time the registry
 * is read after the registrations, because modules register in any order.
 */
final class PredicateRegistry extends LazyRegistry {
	/**
	 * Entity type of the statements, subject of the statements that qualify a statement.
	 */
	private const STATEMENT = 'statement';

	/**
	 * Entity types.
	 *
	 * @var EntityTypeRegistry
	 */
	private $entity_types;

	/**
	 * Datatypes.
	 *
	 * @var DatatypeRegistry
	 */
	private $datatypes;

	/**
	 * Whether the references have been checked since the last registration.
	 *
	 * @var bool
	 */
	private $checked = false;

	/**
	 * Builds the registry.
	 *
	 * @param EntityTypeRegistry $entity_types Entity types.
	 * @param DatatypeRegistry   $datatypes    Datatypes.
	 * @param callable|null      $initializer  Called once with the registry before its first use.
	 */
	public function __construct( EntityTypeRegistry $entity_types, DatatypeRegistry $datatypes, $initializer = null ) {
		parent::__construct( $initializer );

		$this->entity_types = $entity_types;
		$this->datatypes    = $datatypes;
	}

	/**
	 * Registers a predicate.
	 *
	 * @param PredicateDefinition $predicate Predicate.
	 * @return void
	 */
	public function register( PredicateDefinition $predicate ) {
		parent::ensure_initialized();
		$this->check_types( $predicate );
		$this->add( $predicate->slug(), $predicate, 'Predicate' );

		$this->checked = false;
	}

	/**
	 * Returns a predicate.
	 *
	 * @param string $slug Slug.
	 * @return PredicateDefinition
	 */
	public function get( $slug ) {
		return $this->item( $slug, 'Predicate' );
	}

	/**
	 * Tells whether a predicate may qualify the statements of another one.
	 *
	 * It may when the target lists it in `qualified_by`, or when it lists the target, or `*`, in `qualifies`.
	 *
	 * @param string $qualifier Slug of the qualifying predicate.
	 * @param string $target    Slug of the predicate of the statements qualified.
	 * @return bool
	 */
	public function can_qualify( $qualifier, $target ) {
		$qualifying = $this->get( $qualifier );
		$qualified  = $this->get( $target );

		return in_array( $qualifier, $qualified->qualified_by(), true )
			|| in_array( $target, $qualifying->qualifies(), true )
			|| in_array( PredicateDefinition::WILDCARD, $qualifying->qualifies(), true );
	}

	/**
	 * Runs the initializer, then checks the references once the registrations are over.
	 *
	 * @return void
	 * @throws \Throwable When a reference is not valid, after leaving the registry ready for a new check.
	 */
	protected function ensure_initialized() {
		parent::ensure_initialized();

		if ( $this->checked || $this->is_initializing() ) {
			return;
		}

		$this->checked = true;

		try {
			$this->check_references( parent::all() );
		} catch ( \Throwable $problem ) {
			$this->checked = false;

			throw $problem;
		}
	}

	/**
	 * Checks that the types of a predicate are registered and fit their role.
	 *
	 * @param PredicateDefinition $predicate Predicate.
	 * @return void
	 * @throws \InvalidArgumentException When a type is unknown, when a literal is a subject, or when a symmetric predicate relates different kinds of things.
	 */
	private function check_types( PredicateDefinition $predicate ) {
		$slug = $predicate->slug();

		foreach ( $predicate->subject_types() as $type ) {
			if ( $this->datatypes->has( $type ) && ! $this->entity_types->has( $type ) ) {
				throw new \InvalidArgumentException( sprintf( 'Predicate "%1$s": the literal type "%2$s" cannot be a subject.', esc_html( $slug ), esc_html( $type ) ) );
			}

			if ( ! $this->entity_types->has( $type ) ) {
				throw new \InvalidArgumentException( sprintf( 'Predicate "%1$s" uses the unknown entity type "%2$s".', esc_html( $slug ), esc_html( $type ) ) );
			}
		}

		foreach ( $predicate->object_types() as $type ) {
			if ( ! $this->entity_types->has( $type ) && ! $this->datatypes->has( $type ) ) {
				throw new \InvalidArgumentException( sprintf( 'Predicate "%1$s" uses the unknown type "%2$s".', esc_html( $slug ), esc_html( $type ) ) );
			}
		}

		$subjects = $predicate->subject_types();
		$objects  = $predicate->object_types();

		sort( $subjects );
		sort( $objects );

		if ( $predicate->is_symmetric() && $subjects !== $objects ) {
			throw new \InvalidArgumentException( sprintf( 'Predicate "%s" is symmetric: its subject and object types must be the same entity types.', esc_html( $slug ) ) );
		}
	}

	/**
	 * Checks the references between predicates and the namespace shared by entity types and datatypes.
	 *
	 * @param array<string, PredicateDefinition> $predicates All the predicates.
	 * @return void
	 * @throws \InvalidArgumentException When a reference is unknown, a qualifier cannot have a statement as subject, or a name is both an entity type and a datatype.
	 */
	private function check_references( array $predicates ) {
		$both = array_intersect( array_keys( $this->entity_types->all() ), array_keys( $this->datatypes->all() ) );

		if ( array() !== $both ) {
			throw new \InvalidArgumentException( sprintf( 'The name "%s" is used by both an entity type and a datatype.', esc_html( (string) reset( $both ) ) ) );
		}

		foreach ( $predicates as $predicate ) {
			foreach ( $predicate->qualified_by() as $qualifier ) {
				if ( ! isset( $predicates[ $qualifier ] ) ) {
					throw new \InvalidArgumentException( sprintf( 'Predicate "%1$s" is qualified by the unknown predicate "%2$s".', esc_html( $predicate->slug() ), esc_html( $qualifier ) ) );
				}

				$this->check_accepts_statements( $predicates[ $qualifier ] );
			}

			foreach ( $predicate->qualifies() as $target ) {
				if ( PredicateDefinition::WILDCARD !== $target && ! isset( $predicates[ $target ] ) ) {
					throw new \InvalidArgumentException( sprintf( 'Predicate "%1$s" qualifies the unknown predicate "%2$s".', esc_html( $predicate->slug() ), esc_html( $target ) ) );
				}
			}

			if ( array() !== $predicate->qualifies() ) {
				$this->check_accepts_statements( $predicate );
			}
		}
	}

	/**
	 * Checks that a predicate used as a qualifier accepts a statement as subject.
	 *
	 * @param PredicateDefinition $predicate Qualifying predicate.
	 * @return void
	 * @throws \InvalidArgumentException When "statement" is not among its subject types.
	 */
	private function check_accepts_statements( PredicateDefinition $predicate ) {
		$subjects = $predicate->subject_types();

		if ( array() !== $subjects && ! in_array( self::STATEMENT, $subjects, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Predicate "%s" is used as a qualifier but "statement" is not among its subject types.', esc_html( $predicate->slug() ) ) );
		}
	}
}
