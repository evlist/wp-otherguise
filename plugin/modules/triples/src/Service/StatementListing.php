<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ordered and scoped lists of statements.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Service;

use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Storage\Statement;

defined( 'ABSPATH' ) || exit;

/**
 * The reading rule of slice 103: natural order and global pins, then the filter by scope, then the scope pins.
 *
 * Triples knows nothing about what a scope is: the caller names the predicate (`modes/mode`) and the value (`mode:print`).
 */
final class StatementListing {
	/**
	 * Predicate of the explicit ranks.
	 */
	private const POSITION = 'triples/position';

	/**
	 * Reader.
	 *
	 * @var StatementReader
	 */
	private $reader;

	/**
	 * Resolver.
	 *
	 * @var EntityResolver
	 */
	private $resolver;

	/**
	 * Validator.
	 *
	 * @var StatementValidator
	 */
	private $validator;

	/**
	 * Builds the listing.
	 *
	 * @param StatementReader    $reader    Reader.
	 * @param EntityResolver     $resolver  Resolver.
	 * @param StatementValidator $validator Validator.
	 */
	public function __construct( StatementReader $reader, EntityResolver $resolver, StatementValidator $validator ) {
		$this->reader    = $reader;
		$this->resolver  = $resolver;
		$this->validator = $validator;
	}

	/**
	 * Lists the statements of a subject and a predicate.
	 *
	 * Options: `natural_order` (a comparator on two statements; the default is the id) and `scope` (`array( scope predicate, value )`).
	 * Without `scope` nothing is filtered. With it, a statement that has no statement of the scope predicate with that value is left
	 * out: it belongs to no scope.
	 *
	 * @param mixed                $subject   Subject.
	 * @param string               $predicate Predicate slug.
	 * @param array<string, mixed> $options   Options.
	 * @return Statement[]
	 * @throws InvalidStatementException When an argument is refused.
	 */
	public function listing( $subject, $predicate, array $options = array() ) {
		$this->validator->predicate( $predicate );

		$statements = $this->reader->match( $this->resolver->entity( $subject ), $predicate );

		if ( isset( $options['natural_order'] ) ) {
			usort( $statements, $options['natural_order'] );
		}

		$by_id  = array();
		$global = array();

		foreach ( $this->reader->qualifications_of( $statements ) as $id => $qualifications ) {
			$global[ $id ] = $this->rank( $qualifications );
		}

		foreach ( $statements as $statement ) {
			$by_id[ $statement->id() ] = $statement;
		}

		$ids = PinnedOrder::merge( array_keys( $by_id ), array_filter( $global, static fn( $rank ) => null !== $rank ) );

		if ( isset( $options['scope'] ) ) {
			$ids = $this->scoped( $ids, $options['scope'] );
		}

		return array_map( static fn( $id ) => $by_id[ $id ], $ids );
	}

	/**
	 * Keeps the statements that belong to a scope and puts the ones with a scope pin at their rank.
	 *
	 * @param int[]   $ids   Ids in the order of the unscoped list.
	 * @param mixed[] $scope Scope predicate and value.
	 * @return int[]
	 * @throws InvalidStatementException When the scope is malformed or refused.
	 */
	private function scoped( array $ids, $scope ) {
		if ( ! is_array( $scope ) || 2 !== count( $scope ) ) {
			InvalidStatementException::refuse( InvalidStatementException::UNKNOWN_PREDICATE, 'The scope is a pair: the scope predicate and its value.' );
		}

		list( $scope_predicate, $value ) = array_values( $scope );

		$this->validator->predicate( $scope_predicate );

		$value   = $value instanceof Literal ? $value : $this->resolver->entity( $value );
		$members = array();
		$kept    = array();

		foreach ( $this->reader->qualifications_of( $ids ) as $id => $qualifications ) {
			foreach ( $qualifications[ $scope_predicate ] ?? array() as $in_scope ) {
				if ( $in_scope->object()->equals( $value ) ) {
					$members[ $id ] = $in_scope;
					break;
				}
			}
		}

		foreach ( $ids as $id ) {
			if ( isset( $members[ $id ] ) ) {
				$kept[] = $id;
			}
		}

		$link_of = array();
		$pins    = array();

		foreach ( $members as $link_id => $in_scope ) {
			$link_of[ $in_scope->id() ] = $link_id;
		}

		foreach ( $this->reader->qualifications_of( $members ) as $scope_statement_id => $qualifications ) {
			$rank = $this->rank( $qualifications );

			if ( null !== $rank ) {
				$pins[ $link_of[ $scope_statement_id ] ] = $rank;
			}
		}

		return PinnedOrder::merge( $kept, $pins );
	}

	/**
	 * Reads the explicit rank among the statements about a statement.
	 *
	 * @param array<string, Statement[]> $qualifications Statements about a statement, by predicate.
	 * @return int|null
	 */
	private function rank( array $qualifications ) {
		return isset( $qualifications[ self::POSITION ] ) ? (int) $qualifications[ self::POSITION ][0]->object()->key() : null;
	}
}
