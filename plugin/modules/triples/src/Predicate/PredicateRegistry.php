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

use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Qualifier\QualifierTypeRegistry;
use Otherguise\Triples\Support\LazyRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Predicates by slug. A predicate is checked against the entity types and the qualifier types when it is registered.
 */
final class PredicateRegistry extends LazyRegistry {
	/**
	 * Entity types.
	 *
	 * @var EntityTypeRegistry
	 */
	private $entity_types;

	/**
	 * Qualifier types.
	 *
	 * @var QualifierTypeRegistry
	 */
	private $qualifier_types;

	/**
	 * Builds the registry.
	 *
	 * @param EntityTypeRegistry    $entity_types    Entity types.
	 * @param QualifierTypeRegistry $qualifier_types Qualifier types.
	 * @param callable|null         $initializer     Called once with the registry before its first use.
	 */
	public function __construct( EntityTypeRegistry $entity_types, QualifierTypeRegistry $qualifier_types, $initializer = null ) {
		parent::__construct( $initializer );

		$this->entity_types    = $entity_types;
		$this->qualifier_types = $qualifier_types;
	}

	/**
	 * Registers a predicate.
	 *
	 * @param PredicateDefinition $predicate Predicate.
	 * @return void
	 * @throws \InvalidArgumentException When the predicate is symmetric with different subject and object types.
	 */
	public function register( PredicateDefinition $predicate ) {
		$this->ensure_initialized();
		$this->check_entity_types( $predicate );
		$this->check_qualifiers( $predicate );

		$subjects = $predicate->subject_types();
		$objects  = $predicate->object_types();

		sort( $subjects );
		sort( $objects );

		if ( $predicate->is_symmetric() && $subjects !== $objects ) {
			throw new \InvalidArgumentException( sprintf( 'Predicate "%s" is symmetric: its subject and object types must be the same.', esc_html( $predicate->slug() ) ) );
		}

		$this->add( $predicate->slug(), $predicate, 'Predicate' );
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
	 * Checks that the entity types of a predicate are registered.
	 *
	 * @param PredicateDefinition $predicate Predicate.
	 * @return void
	 * @throws \InvalidArgumentException When an entity type is unknown.
	 */
	private function check_entity_types( PredicateDefinition $predicate ) {
		foreach ( array_merge( $predicate->subject_types(), $predicate->object_types() ) as $slug ) {
			if ( ! $this->entity_types->has( $slug ) ) {
				throw new \InvalidArgumentException( sprintf( 'Predicate "%1$s" uses the unknown entity type "%2$s".', esc_html( $predicate->slug() ), esc_html( $slug ) ) );
			}
		}
	}

	/**
	 * Checks that the qualifier types of a predicate are registered and accept the options of their qualifier.
	 *
	 * @param PredicateDefinition $predicate Predicate.
	 * @return void
	 * @throws \InvalidArgumentException When a qualifier type is unknown.
	 */
	private function check_qualifiers( PredicateDefinition $predicate ) {
		foreach ( $predicate->qualifiers() as $qualifier ) {
			if ( ! $this->qualifier_types->has( $qualifier->type() ) ) {
				throw new \InvalidArgumentException( sprintf( 'Predicate "%1$s" uses the unknown qualifier type "%2$s".', esc_html( $predicate->slug() ), esc_html( $qualifier->type() ) ) );
			}

			$this->qualifier_types->get( $qualifier->type() )->validate_options( $qualifier->options() );
		}
	}
}
