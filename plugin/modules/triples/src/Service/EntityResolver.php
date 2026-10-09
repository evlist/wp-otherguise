<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Turns the arguments of the service into references to entities.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Service;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Entity\NodeInterface;
use Otherguise\Triples\Storage\Statement;

defined( 'ABSPATH' ) || exit;

/**
 * Recognizes what the caller holds (a WordPress object, a stored statement, a reference) and finds the object again from a reference.
 */
final class EntityResolver {
	/**
	 * Entity types.
	 *
	 * @var EntityTypeRegistry
	 */
	private $types;

	/**
	 * Builds the resolver.
	 *
	 * @param EntityTypeRegistry $types Entity types.
	 */
	public function __construct( EntityTypeRegistry $types ) {
		$this->types = $types;
	}

	/**
	 * Returns the reference to the entity a value stands for.
	 *
	 * An `EntityRef` is kept and a stored `Statement` is `statement:ID`. Any other value must be recognized by exactly one registered
	 * entity type. A bare integer or string is refused: it does not say what it is.
	 *
	 * @param mixed $value Value given by the caller.
	 * @return EntityRef
	 * @throws InvalidStatementException With `unknown_entity` or `ambiguous_entity`.
	 */
	public function entity( $value ) {
		if ( $value instanceof EntityRef ) {
			return $value;
		}

		if ( $value instanceof Statement ) {
			if ( null === $value->id() ) {
				InvalidStatementException::refuse( InvalidStatementException::UNKNOWN_ENTITY, 'A statement that is not stored has no identity.' );
			}

			return $value->as_entity();
		}

		if ( ! is_object( $value ) || $value instanceof NodeInterface ) {
			InvalidStatementException::refuse(
				InvalidStatementException::UNKNOWN_ENTITY,
				'Expected a WordPress object, a statement or a reference to an entity (for example Ref::post( 12 )), not ' . ( is_object( $value ) ? get_class( $value ) : gettype( $value ) ) . '.'
			);
		}

		$found = array();

		foreach ( $this->types->all() as $slug => $type ) {
			$id = $type->identify( $value );

			if ( null !== $id ) {
				$found[ $slug ] = $id;
			}
		}

		if ( 1 === count( $found ) ) {
			return new EntityRef( (string) key( $found ), (string) reset( $found ) );
		}

		InvalidStatementException::refuse(
			array() === $found ? InvalidStatementException::UNKNOWN_ENTITY : InvalidStatementException::AMBIGUOUS_ENTITY,
			array() === $found
				? 'No entity type recognizes an object of class ' . get_class( $value ) . '.'
			: 'Several entity types recognize an object of class ' . get_class( $value ) . ': ' . implode( ', ', array_keys( $found ) ) . '.'
		);
	}

	/**
	 * Returns the object an entity stands for, or null when the type has no loader or the entity does not exist.
	 *
	 * @param EntityRef $entity Entity.
	 * @return mixed
	 */
	public function resolve( EntityRef $entity ) {
		return $this->types->has( $entity->type() ) ? $this->types->get( $entity->type() )->load( $entity->id() ) : null;
	}
}
