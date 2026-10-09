<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Checks of a statement before it is stored.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Service;

use Otherguise\Triples\Datatype\DatatypeRegistry;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Predicate\PredicateDefinition;
use Otherguise\Triples\Predicate\PredicateRegistry;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\StatementQuery;
use Otherguise\Triples\Storage\StatementStore;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the rules of the predicate registry to a triple, in the order documented in slice 103:
 * predicate, subject, object (entity or literal), existence, qualification, then the limits.
 * The duplicate check belongs to the caller, which needs the existing statement.
 *
 * The limits are checked without a database lock: two simultaneous requests can both pass a limit of 1.
 */
final class StatementValidator {
	/**
	 * Entity types.
	 *
	 * @var EntityTypeRegistry
	 */
	private $types;

	/**
	 * Datatypes.
	 *
	 * @var DatatypeRegistry
	 */
	private $datatypes;

	/**
	 * Predicates.
	 *
	 * @var PredicateRegistry
	 */
	private $predicates;

	/**
	 * Resolver of the arguments.
	 *
	 * @var EntityResolver
	 */
	private $resolver;

	/**
	 * Store.
	 *
	 * @var StatementStore
	 */
	private $store;

	/**
	 * Builds the validator.
	 *
	 * @param EntityTypeRegistry $types      Entity types.
	 * @param DatatypeRegistry   $datatypes  Datatypes.
	 * @param PredicateRegistry  $predicates Predicates.
	 * @param EntityResolver     $resolver   Resolver.
	 * @param StatementStore     $store      Store.
	 */
	public function __construct( EntityTypeRegistry $types, DatatypeRegistry $datatypes, PredicateRegistry $predicates, EntityResolver $resolver, StatementStore $store ) {
		$this->types      = $types;
		$this->datatypes  = $datatypes;
		$this->predicates = $predicates;
		$this->resolver   = $resolver;
		$this->store      = $store;
	}

	/**
	 * Returns the definition of a predicate.
	 *
	 * @param string $slug Predicate slug.
	 * @return PredicateDefinition
	 * @throws InvalidStatementException With `unknown_predicate`.
	 */
	public function predicate( $slug ) {
		if ( ! is_string( $slug ) || ! PredicateDefinition::is_valid_slug( $slug ) || ! $this->predicates->has( $slug ) ) {
			InvalidStatementException::refuse(
				InvalidStatementException::UNKNOWN_PREDICATE,
				'The predicate "' . ( is_string( $slug ) ? $slug : gettype( $slug ) ) . '" is not registered.'
			);
		}

		return $this->predicates->get( $slug );
	}

	/**
	 * Checks the types, the ids and the values, and returns the statement as it would be stored: the object normalized, the two ends of a
	 * symmetric statement in canonical order. Nothing is read from the store.
	 *
	 * @param mixed $subject   Subject given by the caller.
	 * @param mixed $predicate Predicate slug.
	 * @param mixed $target    Object given by the caller.
	 * @return Statement
	 * @throws InvalidStatementException When a check fails.
	 */
	public function normalize( $subject, $predicate, $target ) {
		$definition = $this->predicate( $predicate );
		$subject    = $this->entity( $this->resolver->entity( $subject ), $definition->subject_types(), InvalidStatementException::SUBJECT_TYPE_NOT_ALLOWED, $predicate );
		$target     = $this->object_node( $definition, $target );

		if ( $definition->is_symmetric() && $target instanceof EntityRef && strcmp( (string) $target, (string) $subject ) < 0 ) {
			list( $subject, $target ) = array( $target, $subject );
		}

		return new Statement( $subject, $predicate, $target );
	}

	/**
	 * Checks that the ends exist and that a statement about a statement is allowed by the predicate it qualifies.
	 *
	 * @param Statement $statement Normalized statement.
	 * @return void
	 * @throws InvalidStatementException When a check fails.
	 */
	public function check_references( Statement $statement ) {
		$this->check_exists( $statement->subject(), InvalidStatementException::SUBJECT_MISSING );

		if ( $statement->object() instanceof EntityRef ) {
			$this->check_exists( $statement->object(), InvalidStatementException::OBJECT_MISSING );
		}

		if ( 'statement' !== $statement->subject()->type() ) {
			return;
		}

		$qualified = $this->store->find( (int) $statement->subject()->id() );

		if ( null === $qualified ) {
			InvalidStatementException::refuse( InvalidStatementException::SUBJECT_MISSING, 'The statement ' . $statement->subject() . ' does not exist.' );
		}

		if ( ! $this->predicates->has( $qualified->predicate() ) || ! $this->predicates->can_qualify( $statement->predicate(), $qualified->predicate() ) ) {
			InvalidStatementException::refuse(
				InvalidStatementException::QUALIFICATION_NOT_ALLOWED,
				'The predicate "' . $statement->predicate() . '" cannot qualify "' . $qualified->predicate() . '".'
			);
		}
	}

	/**
	 * Checks the cardinality limits of the predicate against what is stored.
	 *
	 * @param Statement $statement Normalized statement that is not stored yet.
	 * @return void
	 * @throws InvalidStatementException With `too_many_objects` or `too_many_subjects`.
	 */
	public function check_limits( Statement $statement ) {
		$definition = $this->predicate( $statement->predicate() );
		$maximum    = $definition->max_objects_per_subject();
		$query      = ( new StatementQuery() )->with_predicates( array( $statement->predicate() ) );

		if ( null !== $maximum ) {
			$ends = $definition->is_symmetric() ? array_unique( array( (string) $statement->subject(), (string) $statement->object() ) ) : array( (string) $statement->subject() );

			foreach ( $ends as $end ) {
				$entity = EntityRef::parse( $end );
				$count  = $this->store->count( $definition->is_symmetric() ? $query->involving( $entity ) : $query->with_subject( $entity ) );

				if ( $count >= $maximum ) {
					InvalidStatementException::refuse(
						InvalidStatementException::TOO_MANY_OBJECTS,
						'"' . $statement->predicate() . '" allows ' . $maximum . ' object(s) per subject and ' . $end . ' has reached it.'
					);
				}
			}
		}

		$maximum = $definition->max_subjects_per_object();

		if ( null !== $maximum && ! $definition->is_symmetric() && $this->store->count( $query->with_object( $statement->object() ) ) >= $maximum ) {
			InvalidStatementException::refuse(
				InvalidStatementException::TOO_MANY_SUBJECTS,
				'"' . $statement->predicate() . '" allows ' . $maximum . ' subject(s) per object and ' . $statement->object() . ' has reached it.'
			);
		}
	}

	/**
	 * Checks an entity reference: registered type, well formed id, type accepted by the predicate.
	 *
	 * @param EntityRef $entity    Entity.
	 * @param string[]  $allowed   Types accepted by the predicate; empty accepts any entity type.
	 * @param string    $not_allowed Error code when the type is refused.
	 * @param string    $predicate Predicate slug, for the message.
	 * @return EntityRef
	 * @throws InvalidStatementException When the check fails.
	 */
	private function entity( EntityRef $entity, array $allowed, $not_allowed, $predicate ) {
		if ( ! $this->types->has( $entity->type() ) ) {
			InvalidStatementException::refuse( InvalidStatementException::UNKNOWN_TYPE, 'The entity type "' . $entity->type() . '" is not registered.' );
		}

		if ( ! $this->types->get( $entity->type() )->is_valid_id( $entity->id() ) ) {
			InvalidStatementException::refuse( InvalidStatementException::INVALID_ID, 'The id of ' . $entity . ' is malformed for its type.' );
		}

		if ( array() !== $allowed && ! in_array( $entity->type(), $allowed, true ) ) {
			InvalidStatementException::refuse( $not_allowed, '"' . $predicate . '" does not accept ' . $entity->type() . ' here.' );
		}

		return $entity;
	}

	/**
	 * Reads the object: an entity, a `Literal` or a scalar read as a literal of the only datatype the predicate accepts; the checks of
	 * the types, ids and values apply, and the value of a literal is normalized.
	 *
	 * @param PredicateDefinition $definition Predicate.
	 * @param mixed               $target     Object given by the caller.
	 * @return EntityRef|Literal
	 * @throws InvalidStatementException When a check fails.
	 */
	public function object_node( PredicateDefinition $definition, $target ) {
		if ( is_scalar( $target ) ) {
			return $this->literal( $definition, $this->scalar_datatype( $definition ), $target );
		}

		if ( $target instanceof Literal ) {
			if ( ! $this->datatypes->has( $target->type() ) ) {
				InvalidStatementException::refuse( InvalidStatementException::UNKNOWN_TYPE, 'The datatype "' . $target->type() . '" is not registered.' );
			}

			return $this->literal( $definition, $target->type(), $target->key() );
		}

		return $this->entity( $this->resolver->entity( $target ), $definition->object_types(), InvalidStatementException::OBJECT_TYPE_NOT_ALLOWED, $definition->slug() );
	}

	/**
	 * Returns the only datatype a predicate accepts.
	 *
	 * @param PredicateDefinition $definition Predicate.
	 * @return string
	 * @throws InvalidStatementException With `ambiguous_literal` when there is none or several.
	 */
	private function scalar_datatype( PredicateDefinition $definition ) {
		$names = array_values( array_filter( $definition->object_types(), array( $this->datatypes, 'has' ) ) );

		if ( 1 !== count( $names ) ) {
			InvalidStatementException::refuse(
				InvalidStatementException::AMBIGUOUS_LITERAL,
				'"' . $definition->slug() . '" accepts ' . ( array() === $names ? 'no datatype' : 'several datatypes' ) . ': pass a Literal naming the datatype.'
			);
		}

		return $names[0];
	}

	/**
	 * Checks a literal against the predicate and the datatype, and normalizes its value.
	 *
	 * @param PredicateDefinition $definition Predicate.
	 * @param string              $name       Datatype name.
	 * @param mixed               $value      Value.
	 * @return Literal
	 * @throws InvalidStatementException When a check fails.
	 */
	private function literal( PredicateDefinition $definition, $name, $value ) {
		if ( ! in_array( $name, $definition->object_types(), true ) ) {
			InvalidStatementException::refuse( InvalidStatementException::OBJECT_TYPE_NOT_ALLOWED, '"' . $definition->slug() . '" does not accept the datatype "' . $name . '".' );
		}

		$datatype = $this->datatypes->get( $name );

		if ( ! $datatype->validate( $value ) ) {
			InvalidStatementException::refuse( InvalidStatementException::INVALID_VALUE, 'The value is not a valid ' . $name . '.' );
		}

		try {
			return new Literal( $name, $datatype->normalize( $value ) );
		} catch ( \InvalidArgumentException $problem ) {
			InvalidStatementException::refuse( InvalidStatementException::INVALID_VALUE, 'The value is too long: a literal is limited to ' . Literal::MAX_BYTES . ' bytes.' );
		}
	}

	/**
	 * Checks that an entity exists, for the types that can tell.
	 *
	 * @param EntityRef $entity Entity.
	 * @param string    $code   Error code when it does not exist.
	 * @return void
	 * @throws InvalidStatementException When the entity does not exist.
	 */
	private function check_exists( EntityRef $entity, $code ) {
		if ( false === $this->types->get( $entity->type() )->exists( $entity->id() ) ) {
			InvalidStatementException::refuse( $code, $entity . ' does not exist.' );
		}
	}
}
