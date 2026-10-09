<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Reading of the statements.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Service;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\NodeInterface;
use Otherguise\Triples\Predicate\PredicateRegistry;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\StatementQuery;
use Otherguise\Triples\Storage\StatementStore;

defined( 'ABSPATH' ) || exit;

/**
 * Finds statements by triple and by pattern, in both directions for the symmetric predicates, and reads the statements about statements.
 */
final class StatementReader {
	/**
	 * Store.
	 *
	 * @var StatementStore
	 */
	private $store;

	/**
	 * Validator.
	 *
	 * @var StatementValidator
	 */
	private $validator;

	/**
	 * Resolver.
	 *
	 * @var EntityResolver
	 */
	private $resolver;

	/**
	 * Predicates.
	 *
	 * @var PredicateRegistry
	 */
	private $predicates;

	/**
	 * Builds the reader.
	 *
	 * @param StatementStore     $store      Store.
	 * @param StatementValidator $validator  Validator.
	 * @param EntityResolver     $resolver   Resolver.
	 * @param PredicateRegistry  $predicates Predicates.
	 */
	public function __construct( StatementStore $store, StatementValidator $validator, EntityResolver $resolver, PredicateRegistry $predicates ) {
		$this->store      = $store;
		$this->validator  = $validator;
		$this->resolver   = $resolver;
		$this->predicates = $predicates;
	}

	/**
	 * Reads a statement by id.
	 *
	 * @param int $id Statement id.
	 * @return Statement|null
	 */
	public function find( $id ) {
		return $this->store->find( (int) $id );
	}

	/**
	 * Reads the statement that holds a triple, whichever way a symmetric one is given.
	 *
	 * @param mixed  $subject   Subject.
	 * @param string $predicate Predicate slug.
	 * @param mixed  $target    Object.
	 * @return Statement|null
	 */
	public function find_by_triple( $subject, $predicate, $target ) {
		$statement = $this->validator->normalize( $subject, $predicate, $target );

		return $this->store->find_by_triple( $statement->subject(), $statement->predicate(), $statement->object() );
	}

	/**
	 * Reads the statements that fit a pattern: a part left null is a wildcard.
	 *
	 * The statements of a symmetric predicate are found whichever end is given, and are returned oriented as asked: the entity given as
	 * subject (or, failing that, as object) is on that side. A scalar object needs a predicate, to know its datatype.
	 *
	 * @param mixed       $subject   Subject, or null.
	 * @param string|null $predicate Predicate slug, or null.
	 * @param mixed       $target    Object, or null.
	 * @return Statement[] In increasing id order.
	 */
	public function match( $subject = null, $predicate = null, $target = null ) {
		$from   = null === $subject ? null : $this->resolver->entity( $subject );
		$to     = $this->object_of_pattern( $predicate, $target );
		$query  = new StatementQuery();
		$found  = array();

		if ( null !== $from ) {
			$query = $query->with_subject( $from );
		}

		if ( null !== $to ) {
			$query = $query->with_object( $to );
		}

		if ( null !== $predicate ) {
			$query = $query->with_predicates( array( $predicate ) );
		}

		foreach ( $this->store->query( $query ) as $statement ) {
			$found[ $statement->id() ] = $statement;
		}

		foreach ( $this->reverse( $from, $predicate, $to ) as $statement ) {
			$found[ $statement->id() ] = $found[ $statement->id() ] ?? $statement;
		}

		ksort( $found );

		return array_values( $found );
	}

	/**
	 * Reads the statements about statements, one level, in one query.
	 *
	 * @param array<int, Statement|int> $statements Statements or ids.
	 * @return array<int, array<string, Statement[]>> By id of the qualified statement, then by predicate.
	 */
	public function qualifications_of( array $statements ) {
		$ids = array();

		foreach ( $statements as $statement ) {
			$ids[] = $statement instanceof Statement ? (int) $statement->id() : (int) $statement;
		}

		$grouped = array();

		foreach ( $this->store->about( $ids ) as $qualification ) {
			$grouped[ (int) $qualification->subject()->id() ][ $qualification->predicate() ][] = $qualification;
		}

		return $grouped;
	}

	/**
	 * Reads the object of a pattern.
	 *
	 * @param string|null $predicate Predicate slug, or null.
	 * @param mixed       $target    Object, or null.
	 * @return NodeInterface|null
	 * @throws InvalidStatementException When a scalar has no predicate to say its datatype, or when the object is refused.
	 */
	private function object_of_pattern( $predicate, $target ) {
		if ( null === $target ) {
			return null;
		}

		if ( null !== $predicate && ( is_scalar( $target ) || $target instanceof Literal ) ) {
			return $this->validator->object_node( $this->validator->predicate( $predicate ), $target );
		}

		if ( $target instanceof Literal ) {
			return $target;
		}

		if ( is_scalar( $target ) ) {
			InvalidStatementException::refuse( InvalidStatementException::AMBIGUOUS_LITERAL, 'A scalar object needs a predicate to know its datatype.' );
		}

		return $this->resolver->entity( $target );
	}

	/**
	 * Reads the statements of symmetric predicates stored the other way round, oriented as asked.
	 *
	 * @param EntityRef|null     $from      Subject of the pattern.
	 * @param string|null        $predicate Predicate of the pattern.
	 * @param NodeInterface|null $to        Object of the pattern.
	 * @return Statement[]
	 */
	private function reverse( $from, $predicate, $to ) {
		if ( ( null === $from && null === $to ) || ( null !== $to && ! $to instanceof EntityRef ) ) {
			return array();
		}

		$slugs = array();

		foreach ( null === $predicate ? array_keys( $this->predicates->all() ) : array( $predicate ) as $slug ) {
			if ( $this->validator->predicate( $slug )->is_symmetric() ) {
				$slugs[] = $slug;
			}
		}

		if ( array() === $slugs ) {
			return array();
		}

		$query = ( new StatementQuery() )->with_predicates( $slugs );
		$query = null === $from ? $query : $query->with_object( $from );
		$query = null === $to ? $query : $query->with_subject( $to );

		return array_map(
			static function ( Statement $statement ) {
				return new Statement( $statement->object(), $statement->predicate(), $statement->subject(), $statement->id(), $statement->created_gmt(), $statement->updated_gmt() );
			},
			$this->store->query( $query )
		);
	}
}
