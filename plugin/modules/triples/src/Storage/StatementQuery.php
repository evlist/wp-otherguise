<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Query on statements.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Storage;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\NodeInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Criteria on statements and the SQL that applies them.
 *
 * Immutable: each `with_*` method returns a modified copy. The SQL building is pure, the store executes it.
 */
final class StatementQuery {
	/**
	 * Columns read.
	 */
	private const COLUMNS = 's.id, s.subject_type, s.subject_id, s.predicate, s.object_type, s.object_id, s.created_gmt, s.updated_gmt';

	/**
	 * Subject, or null.
	 *
	 * @var EntityRef|null
	 */
	private $subject;

	/**
	 * Object, or null.
	 *
	 * @var NodeInterface|null
	 */
	private $object;

	/**
	 * Entity that must be the subject or the object, or null.
	 *
	 * @var EntityRef|null
	 */
	private $involved;

	/**
	 * Predicates that must not match, or empty.
	 *
	 * @var string[]
	 */
	private $other_than = array();

	/**
	 * Subject type that must not match, or null.
	 *
	 * @var string|null
	 */
	private $not_subject_type;

	/**
	 * Entity or datatype type that must be on one end, or null.
	 *
	 * @var string|null
	 */
	private $type;

	/**
	 * Only the statements with an id above this one, or null.
	 *
	 * @var int|null
	 */
	private $after_id;

	/**
	 * Column of the order: `id` or `created_gmt`.
	 *
	 * @var string
	 */
	private $order_by = 'id';

	/**
	 * Allowed predicates (empty: any).
	 *
	 * @var string[]
	 */
	private $predicates = array();

	/**
	 * Conditions on the statements about the statement: predicate, then allowed objects (empty: any), and whether they must exist.
	 *
	 * @var array<int, array{0: string, 1: NodeInterface[], 2: bool}>
	 */
	private $qualifications = array();

	/**
	 * Whether to sort by decreasing id.
	 *
	 * @var bool
	 */
	private $descending = false;

	/**
	 * Maximum number of rows, or null.
	 *
	 * @var int|null
	 */
	private $limit;

	/**
	 * Offset.
	 *
	 * @var int
	 */
	private $offset = 0;

	/**
	 * Restricts the query to the statements of a subject.
	 *
	 * @param EntityRef $subject Subject.
	 * @return self
	 */
	public function with_subject( EntityRef $subject ) {
		$copy          = clone $this;
		$copy->subject = $subject;

		return $copy;
	}

	/**
	 * Restricts the query to the statements about an object.
	 *
	 * @param NodeInterface $target Object.
	 * @return self
	 */
	public function with_object( NodeInterface $target ) {
		$copy         = clone $this;
		$copy->object = $target;

		return $copy;
	}

	/**
	 * Keeps the statements where an entity is the subject or the object (used for symmetric predicates).
	 *
	 * @param EntityRef $entity Entity.
	 * @return self
	 */
	public function involving( EntityRef $entity ) {
		$copy           = clone $this;
		$copy->involved = $entity;

		return $copy;
	}

	/**
	 * Restricts the query to some predicates.
	 *
	 * @param string[] $predicates Predicate slugs.
	 * @return self
	 */
	public function with_predicates( array $predicates ) {
		$copy             = clone $this;
		$copy->predicates = array_values( array_unique( $predicates ) );

		return $copy;
	}

	/**
	 * Keeps the statements that have a statement about them with this predicate, optionally with one of these objects.
	 *
	 * Example: the statements qualified by `modes/mode` with the object `mode:print`.
	 *
	 * @param string          $predicate Predicate of the statement about the statement.
	 * @param NodeInterface[] $objects   Allowed objects; empty for any.
	 * @return self
	 */
	public function qualified( $predicate, array $objects = array() ) {
		$copy                   = clone $this;
		$copy->qualifications[] = array( $predicate, array_values( $objects ), true );

		return $copy;
	}

	/**
	 * Keeps the statements that have no statement about them with this predicate.
	 *
	 * Example: the statements without any `modes/mode` statement, which are shown in no mode.
	 *
	 * @param string $predicate Predicate of the statement about the statement.
	 * @return self
	 */
	public function unqualified( $predicate ) {
		$copy                   = clone $this;
		$copy->qualifications[] = array( $predicate, array(), false );

		return $copy;
	}

	/**
	 * Sorts by decreasing id instead of increasing id.
	 *
	 * @return self
	 */
	public function descending() {
		$copy             = clone $this;
		$copy->descending = true;

		return $copy;
	}

	/**
	 * Limits the number of rows.
	 *
	 * @param int $limit  Maximum number of rows.
	 * @param int $offset Number of rows to skip.
	 * @return self
	 */
	public function limit( $limit, $offset = 0 ) {
		$copy         = clone $this;
		$copy->limit  = max( 1, (int) $limit );
		$copy->offset = max( 0, (int) $offset );

		return $copy;
	}

	/**
	 * Keeps the statements whose predicate is none of these (the ones that are no longer registered, for example).
	 *
	 * @param string[] $predicates Predicate slugs; an empty list restricts nothing.
	 * @return self
	 */
	public function with_other_predicates( array $predicates ) {
		$copy             = clone $this;
		$copy->other_than = array_values( array_unique( $predicates ) );

		return $copy;
	}

	/**
	 * Leaves out the statements whose subject is of a type (the statements about statements, for example).
	 *
	 * @param string $type Entity type.
	 * @return self
	 */
	public function excluding_subject_type( $type ) {
		$copy                   = clone $this;
		$copy->not_subject_type = $type;

		return $copy;
	}

	/**
	 * Keeps the statements that have a type on one of their ends.
	 *
	 * @param string $type Entity type or datatype.
	 * @return self
	 */
	public function with_type( $type ) {
		$copy       = clone $this;
		$copy->type = $type;

		return $copy;
	}

	/**
	 * Keeps the statements with an id above a given one: the next batch of a scan.
	 *
	 * @param int $id Statement id.
	 * @return self
	 */
	public function after_id( $id ) {
		$copy           = clone $this;
		$copy->after_id = max( 0, (int) $id );

		return $copy;
	}

	/**
	 * Chooses the column of the order; the id breaks ties. Anything but `created_gmt` means the id.
	 *
	 * @param string $column `id` or `created_gmt`.
	 * @return self
	 */
	public function order_by( $column ) {
		$copy           = clone $this;
		$copy->order_by = 'created_gmt' === $column ? 'created_gmt' : 'id';

		return $copy;
	}

	/**
	 * Builds the SQL that reads the statements.
	 *
	 * @param string $table Table name (letters, digits and underscores).
	 * @return array{0: string, 1: array<int, mixed>} The query with its placeholders, and its values.
	 */
	public function select( $table ) {
		list( $where, $args ) = $this->conditions( $table );

		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . $this->identifier( $table ) . ' s' . $where . ' ORDER BY ' . $this->order();

		if ( null !== $this->limit ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = $this->limit;
			$args[] = $this->offset;
		}

		return array( $sql, $args );
	}

	/**
	 * Builds the SQL that counts the statements.
	 *
	 * @param string $table Table name.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	public function count( $table ) {
		list( $where, $args ) = $this->conditions( $table );

		return array( 'SELECT COUNT(*) FROM ' . $this->identifier( $table ) . ' s' . $where, $args );
	}

	/**
	 * Builds the ORDER BY clause: the chosen column, then the id.
	 *
	 * @return string
	 */
	private function order() {
		$direction = $this->descending ? 'DESC' : 'ASC';

		return 'id' === $this->order_by ? 's.id ' . $direction : 's.' . $this->order_by . ' ' . $direction . ', s.id ' . $direction;
	}

	/**
	 * Builds the WHERE clause.
	 *
	 * @param string $table Table name.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function conditions( $table ) {
		$clauses = array();
		$args    = array();

		if ( null !== $this->subject ) {
			$clauses[] = 's.subject_type = %s AND s.subject_id = %s';
			$args[]    = $this->subject->type();
			$args[]    = $this->subject->key();
		}

		if ( null !== $this->object ) {
			$clauses[] = 's.object_type = %s AND s.object_id = %s';
			$args[]    = $this->object->type();
			$args[]    = $this->object->key();
		}

		if ( null !== $this->involved ) {
			$clauses[] = '( ( s.subject_type = %s AND s.subject_id = %s ) OR ( s.object_type = %s AND s.object_id = %s ) )';
			$args[]    = $this->involved->type();
			$args[]    = $this->involved->key();
			$args[]    = $this->involved->type();
			$args[]    = $this->involved->key();
		}

		if ( null !== $this->not_subject_type ) {
			$clauses[] = 's.subject_type <> %s';
			$args[]    = $this->not_subject_type;
		}

		if ( null !== $this->type ) {
			$clauses[] = '( s.subject_type = %s OR s.object_type = %s )';
			$args[]    = $this->type;
			$args[]    = $this->type;
		}

		if ( null !== $this->after_id ) {
			$clauses[] = 's.id > %d';
			$args[]    = $this->after_id;
		}

		if ( array() !== $this->other_than ) {
			$clauses[] = 's.predicate NOT IN (' . implode( ',', array_fill( 0, count( $this->other_than ), '%s' ) ) . ')';
			$args      = array_merge( $args, $this->other_than );
		}

		if ( array() !== $this->predicates ) {
			$clauses[] = 's.predicate IN (' . implode( ',', array_fill( 0, count( $this->predicates ), '%s' ) ) . ')';
			$args      = array_merge( $args, $this->predicates );
		}

		foreach ( $this->qualifications as $qualification ) {
			list( $predicate, $objects, $must_exist ) = $qualification;

			$inner  = 'q.subject_type = %s AND q.subject_id = CAST( s.id AS BINARY ) AND q.predicate = %s';
			$args[] = 'statement';
			$args[] = $predicate;

			if ( array() !== $objects ) {
				$alternatives = array();

				foreach ( $objects as $object ) {
					$alternatives[] = '( q.object_type = %s AND q.object_id = %s )';
					$args[]         = $object->type();
					$args[]         = $object->key();
				}

				$inner .= ' AND ( ' . implode( ' OR ', $alternatives ) . ' )';
			}

			$clauses[] = ( $must_exist ? 'EXISTS' : 'NOT EXISTS' ) . ' ( SELECT 1 FROM ' . $this->identifier( $table ) . ' q WHERE ' . $inner . ' )';
		}

		$where = array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses );

		return array( $where, $args );
	}

	/**
	 * Checks a table name before it is written in a query.
	 *
	 * @param string $table Table name.
	 * @return string The same name.
	 * @throws \InvalidArgumentException When the name holds anything but letters, digits and underscores.
	 */
	private function identifier( $table ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+\z/', $table ) ) {
			throw new \InvalidArgumentException( 'Invalid table name.' );
		}

		return $table;
	}
}
