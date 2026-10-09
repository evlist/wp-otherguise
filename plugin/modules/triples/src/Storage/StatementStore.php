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
	 * Object cache of the reads, or null.
	 *
	 * @var Cache|null
	 */
	private $cache;

	/**
	 * Builds the store.
	 *
	 * @param Database         $database  Database.
	 * @param DatatypeRegistry $datatypes Datatypes.
	 * @param callable|null    $now       Returns the current GMT time; defaults to the system clock.
	 * @param Cache|null       $cache     Object cache of the reads; without it every read goes to the database. Every write, and the end
	 *                                    of every transaction, invalidates it.
	 */
	public function __construct( Database $database, DatatypeRegistry $datatypes, $now = null, ?Cache $cache = null ) {
		$this->database  = $database;
		$this->datatypes = $datatypes;
		$this->cache     = $cache;
		$this->now       = $now ?? static function () {
			return gmdate( 'Y-m-d H:i:s' );
		};

		if ( null !== $cache ) {
			// What was read between a write and the end of its transaction may come from a state that is not (or no longer) the committed one.
			$database->listen(
				static function ( $event, $level ) use ( $cache ) {
					if ( 'rollback' === $event || ( 'commit' === $event && 1 === $level ) ) {
						$cache->bump();
					}
				}
			);
		}
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
			$this->changed();

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
		$rows = $this->rows( $this->database->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', array( $this->table(), (int) $id ) ) );

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
		$rows = $this->rows(
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

		return array_map( array( $this, 'hydrate' ), $this->rows( $this->database->prepare( $sql, $args ) ) );
	}

	/**
	 * Counts the statements that match a query.
	 *
	 * @param StatementQuery $query Query.
	 * @return int
	 */
	public function count( StatementQuery $query ) {
		list( $sql, $args ) = $query->count( $this->table() );

		return (int) $this->scalar( $this->database->prepare( $sql, $args ) );
	}

	/**
	 * Counts the statements of each predicate.
	 *
	 * @return array<string, int> By predicate slug.
	 */
	public function counts_by_predicate() {
		$rows   = $this->rows( $this->database->prepare( 'SELECT predicate, COUNT(*) AS total FROM %i GROUP BY predicate ORDER BY predicate', array( $this->table() ) ) );
		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (string) $row['predicate'] ] = (int) $row['total'];
		}

		return $counts;
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
			$rows = $this->rows(
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
		return $this->delete_ids( $this->ids_with_dependents( array( (int) $id ) ) );
	}

	/**
	 * Deletes the statements where an entity is the subject or the object, with the statements about them.
	 *
	 * @param EntityRef     $entity     Entity.
	 * @param string[]|null $predicates Predicates to delete, or null for all of them.
	 * @return int Number of statements deleted.
	 */
	public function delete_by_entity( EntityRef $entity, $predicates = null ) {
		return $this->delete_ids( $this->ids_with_dependents( $this->ids_for_entity( $entity, $predicates ) ) );
	}

	/**
	 * Returns the ids of the statements where an entity is the subject or the object.
	 *
	 * @param EntityRef     $entity     Entity.
	 * @param string[]|null $predicates Predicates to keep, or null for all of them; an empty list matches nothing.
	 * @return int[]
	 */
	public function ids_for_entity( EntityRef $entity, $predicates = null ) {
		$sql  = 'SELECT id FROM %i WHERE ( ( subject_type = %s AND subject_id = %s ) OR ( object_type = %s AND object_id = %s ) )';
		$args = array( $this->table(), $entity->type(), $entity->key(), $entity->type(), $entity->key() );

		if ( null !== $predicates ) {
			if ( array() === $predicates ) {
				return array();
			}

			$sql .= ' AND predicate IN (' . implode( ',', array_fill( 0, count( $predicates ), '%s' ) ) . ')';
			$args = array_merge( $args, array_values( $predicates ) );
		}

		return array_map( 'intval', array_column( $this->rows( $this->database->prepare( $sql, $args ) ), 'id' ) );
	}

	/**
	 * Reads some statements by id.
	 *
	 * @param int[] $ids Statement ids.
	 * @return Statement[] In increasing id order; the ids that do not exist are left out.
	 */
	public function find_many( array $ids ) {
		$statements = array();

		foreach ( array_chunk( array_map( 'intval', $ids ), self::CHUNK ) as $chunk ) {
			$rows = $this->rows(
				$this->database->prepare(
					'SELECT ' . self::COLUMNS . ' FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
					array_merge( array( $this->table() ), $chunk )
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
	 * Adds to a list of ids the ids of all the statements about them, recursively.
	 *
	 * @param int[] $ids Statement ids.
	 * @return int[] Distinct ids.
	 */
	public function ids_with_dependents( array $ids ) {
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
	 * Deletes some statements in one transaction, without looking for the statements about them.
	 *
	 * @param int[] $ids Statement ids.
	 * @return int Number deleted.
	 */
	public function delete_ids( array $ids ) {
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

				$this->changed();

				return $deleted;
			}
		);
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
	 * Reads rows, from the cache when it has them.
	 *
	 * @param string $sql Prepared query.
	 * @return array<int, array<string, string|null>>
	 */
	private function rows( $sql ) {
		$cached = null === $this->cache ? false : $this->cache->get( $sql );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $this->database->rows( $sql );

		if ( null !== $this->cache ) {
			$this->cache->set( $sql, $rows );
		}

		return $rows;
	}

	/**
	 * Reads a single value, from the cache when it has it.
	 *
	 * @param string $sql Prepared query.
	 * @return string|null
	 */
	private function scalar( $sql ) {
		$key    = 'value:' . $sql;
		$cached = null === $this->cache ? false : $this->cache->get( $key );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$value = $this->database->value( $sql );

		if ( null !== $this->cache && null !== $value ) {
			$this->cache->set( $key, (string) $value );
		}

		return $value;
	}

	/**
	 * Invalidates the cache after a write.
	 *
	 * @return void
	 */
	private function changed() {
		if ( null !== $this->cache ) {
			$this->cache->bump();
		}
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
