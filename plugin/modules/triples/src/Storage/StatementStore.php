<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Store of the statements.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Storage;

use Otherguise\Triples\Datatype\DatatypeRegistry;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\NodeInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes statements, without checking what is allowed (slice 103 does that).
 *
 * Statements are immutable: there is no update. A statement qualified by others is removed with them.
 */
final class StatementStore {
	/**
	 * Number of ids per statement when a list is deleted or read.
	 */
	private const CHUNK = 200;

	/**
	 * Columns read.
	 */
	private const COLUMNS = 'id, subject_type, subject_id, predicate, object_type, object_id, created_gmt, updated_gmt';

	/**
	 * Database.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Datatypes, used to tell a literal from an entity when a row is read.
	 *
	 * @var DatatypeRegistry
	 */
	private $datatypes;

	/**
	 * Returns the current time, GMT, as `Y-m-d H:i:s`.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Builds the store.
	 *
	 * @param Database         $database  Database.
	 * @param DatatypeRegistry $datatypes Datatypes.
	 * @param callable|null    $now       Returns the current GMT time; defaults to the system clock.
	 */
	public function __construct( Database $database, DatatypeRegistry $datatypes, $now = null ) {
		$this->database  = $database;
		$this->datatypes = $datatypes;
		$this->now       = $now ?? static function () {
			return gmdate( 'Y-m-d H:i:s' );
		};
	}

	/**
	 * Stores a statement.
	 *
	 * @param Statement $statement Statement without id.
	 * @return int Id of the new statement. A DuplicateStatementException is thrown, with the statement that holds the triple, when it is already stored.
	 * @throws \InvalidArgumentException When the statement already has an id.
	 * @throws \RuntimeException         When the database refuses the statement for another reason.
	 */
	public function insert( Statement $statement ) {
		if ( null !== $statement->id() ) {
			throw new \InvalidArgumentException( 'The statement is already stored.' );
		}

		$now = ( $this->now )();
		$sql = $this->database->prepare(
			'INSERT INTO %i (subject_type, subject_id, predicate, object_type, object_id, created_gmt, updated_gmt) VALUES (%s, %s, %s, %s, %s, %s, %s)',
			array(
				$this->table(),
				$statement->subject()->type(),
				$statement->subject()->key(),
				$statement->predicate(),
				$statement->object()->type(),
				$statement->object()->key(),
				$now,
				$now,
			)
		);

		$previous = $this->database->suppress_errors( true );
		$result   = $this->database->execute( $sql );
		$this->database->suppress_errors( $previous );

		if ( false !== $result ) {
			return $this->database->insert_id();
		}

		$existing = $this->find_by_triple( $statement->subject(), $statement->predicate(), $statement->object() );

		if ( null !== $existing ) {
			$duplicate = DuplicateStatementException::for_existing( $existing );

			throw $duplicate;
		}

		throw new \RuntimeException( 'The statement could not be stored.' );
	}

	/**
	 * Reads a statement by id.
	 *
	 * @param int $id Statement id.
	 * @return Statement|null
	 */
	public function find( $id ) {
		$rows = $this->database->rows(
			$this->database->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', array( $this->table(), (int) $id ) )
		);

		return array() === $rows ? null : $this->hydrate( $rows[0] );
	}

	/**
	 * Reads the statement that holds a triple.
	 *
	 * @param EntityRef     $subject   Subject.
	 * @param string        $predicate Predicate slug.
	 * @param NodeInterface $target    Object.
	 * @return Statement|null
	 */
	public function find_by_triple( EntityRef $subject, $predicate, NodeInterface $target ) {
		$rows = $this->database->rows(
			$this->database->prepare(
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE subject_type = %s AND subject_id = %s AND predicate = %s AND object_type = %s AND object_id = %s',
				array( $this->table(), $subject->type(), $subject->key(), $predicate, $target->type(), $target->key() )
			)
		);

		return array() === $rows ? null : $this->hydrate( $rows[0] );
	}

	/**
	 * Reads the statements that match a query.
	 *
	 * @param StatementQuery $query Query.
	 * @return Statement[]
	 */
	public function query( StatementQuery $query ) {
		list( $sql, $args ) = $query->select( $this->table() );

		return array_map( array( $this, 'hydrate' ), $this->database->rows( $this->database->prepare( $sql, $args ) ) );
	}

	/**
	 * Counts the statements that match a query.
	 *
	 * @param StatementQuery $query Query.
	 * @return int
	 */
	public function count( StatementQuery $query ) {
		list( $sql, $args ) = $query->count( $this->table() );

		return (int) $this->database->value( $this->database->prepare( $sql, $args ) );
	}

	/**
	 * Reads the statements about some statements: those whose subject is `statement:ID` for one of the ids. One level only.
	 *
	 * @param int[] $ids Statement ids.
	 * @return Statement[] In increasing id order.
	 */
	public function about( array $ids ) {
		$statements = array();

		foreach ( array_chunk( array_map( 'intval', $ids ), self::CHUNK ) as $chunk ) {
			$rows = $this->database->rows(
				$this->database->prepare(
					'SELECT ' . self::COLUMNS . ' FROM %i WHERE subject_type = %s AND subject_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ') ORDER BY id',
					array_merge( array( $this->table(), 'statement' ), array_map( 'strval', $chunk ) )
				)
			);

			foreach ( $rows as $row ) {
				$statements[] = $this->hydrate( $row );
			}
		}

		usort(
			$statements,
			static function ( Statement $a, Statement $b ) {
				return $a->id() <=> $b->id();
			}
		);

		return $statements;
	}

	/**
	 * Deletes a statement and, recursively, the statements about it.
	 *
	 * @param int $id Statement id.
	 * @return int Number of statements deleted.
	 */
	public function delete_with_dependents( $id ) {
		return $this->delete_ids( $this->with_dependents( array( (int) $id ) ) );
	}

	/**
	 * Deletes the statements where an entity is the subject or the object, with the statements about them.
	 *
	 * @param EntityRef     $entity     Entity.
	 * @param string[]|null $predicates Predicates to delete, or null for all of them.
	 * @return int Number of statements deleted.
	 */
	public function delete_by_entity( EntityRef $entity, $predicates = null ) {
		$sql  = 'SELECT id FROM %i WHERE ( ( subject_type = %s AND subject_id = %s ) OR ( object_type = %s AND object_id = %s ) )';
		$args = array( $this->table(), $entity->type(), $entity->key(), $entity->type(), $entity->key() );

		if ( null !== $predicates ) {
			if ( array() === $predicates ) {
				return 0;
			}

			$sql .= ' AND predicate IN (' . implode( ',', array_fill( 0, count( $predicates ), '%s' ) ) . ')';
			$args = array_merge( $args, array_values( $predicates ) );
		}

		$ids = array_map( 'intval', array_column( $this->database->rows( $this->database->prepare( $sql, $args ) ), 'id' ) );

		return $this->delete_ids( $this->with_dependents( $ids ) );
	}

	/**
	 * Returns the name of the table.
	 *
	 * @return string
	 * @throws \InvalidArgumentException When the prefix gives an invalid table name.
	 */
	private function table() {
		$table = $this->database->prefix() . SchemaManager::TABLE;

		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+\z/', $table ) ) {
			throw new \InvalidArgumentException( 'Invalid table name.' );
		}

		return $table;
	}

	/**
	 * Adds to a list of ids the ids of all the statements about them, recursively.
	 *
	 * @param int[] $ids Statement ids.
	 * @return int[] Distinct ids.
	 */
	private function with_dependents( array $ids ) {
		$all      = array_fill_keys( $ids, true );
		$frontier = $ids;

		while ( array() !== $frontier ) {
			$next = array();

			foreach ( $this->about( $frontier ) as $statement ) {
				if ( ! isset( $all[ $statement->id() ] ) ) {
					$all[ $statement->id() ] = true;
					$next[]                  = $statement->id();
				}
			}

			$frontier = $next;
		}

		return array_keys( $all );
	}

	/**
	 * Deletes some statements in one transaction.
	 *
	 * @param int[] $ids Statement ids.
	 * @return int Number deleted.
	 */
	private function delete_ids( array $ids ) {
		if ( array() === $ids ) {
			return 0;
		}

		return (int) Transaction::run(
			$this->database,
			function () use ( $ids ) {
				$deleted = 0;

				foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
					$result = $this->database->execute(
						$this->database->prepare(
							'DELETE FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
							array_merge( array( $this->table() ), $chunk )
						)
					);

					if ( false === $result ) {
						throw new \RuntimeException( 'The statements could not be deleted.' );
					}

					$deleted += (int) $result;
				}

				return $deleted;
			}
		);
	}

	/**
	 * Builds a statement from a row.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return Statement
	 */
	private function hydrate( array $row ) {
		$type   = (string) $row['object_type'];
		$object = $this->datatypes->has( $type ) ? new Literal( $type, (string) $row['object_id'] ) : new EntityRef( $type, (string) $row['object_id'] );

		return new Statement(
			new EntityRef( (string) $row['subject_type'], (string) $row['subject_id'] ),
			(string) $row['predicate'],
			$object,
			(int) $row['id'],
			(string) $row['created_gmt'],
			(string) $row['updated_gmt']
		);
	}
}
