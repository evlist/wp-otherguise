<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Deletion of statements.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Service;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Predicate\PredicateDefinition;
use Otherguise\Triples\Predicate\PredicateRegistry;
use Otherguise\Triples\Storage\Database;
use Otherguise\Triples\Storage\StatementStore;
use Otherguise\Triples\Storage\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes statements with the statements about them and tells `EventQueue` about each one, in one transaction.
 */
final class StatementEraser {
	/**
	 * Store.
	 *
	 * @var StatementStore
	 */
	private $store;

	/**
	 * Database.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Events.
	 *
	 * @var EventQueue
	 */
	private $events;

	/**
	 * Predicates.
	 *
	 * @var PredicateRegistry
	 */
	private $predicates;

	/**
	 * Builds the eraser.
	 *
	 * @param StatementStore    $store      Store.
	 * @param Database          $database   Database.
	 * @param EventQueue        $events     Events.
	 * @param PredicateRegistry $predicates Predicates.
	 */
	public function __construct( StatementStore $store, Database $database, EventQueue $events, PredicateRegistry $predicates ) {
		$this->store      = $store;
		$this->database   = $database;
		$this->events     = $events;
		$this->predicates = $predicates;
	}

	/**
	 * Deletes some statements and, recursively, the statements about them.
	 *
	 * @param int[] $ids Statement ids.
	 * @return int Number of statements deleted.
	 */
	public function erase( array $ids ) {
		if ( array() === $ids ) {
			return 0;
		}

		return (int) Transaction::run(
			$this->database,
			function () use ( $ids ) {
				$all        = $this->store->ids_with_dependents( array_map( 'intval', $ids ) );
				$statements = $this->store->find_many( $all );
				$deleted    = $this->store->delete_ids( $all );

				foreach ( $statements as $statement ) {
					$this->events->deleted( $statement );
				}

				return $deleted;
			}
		);
	}

	/**
	 * Deletes what involves an entity that disappeared, except the statements of the predicates that keep them (`on_delete = keep`).
	 *
	 * @param EntityRef $entity Entity.
	 * @return int Number of statements deleted.
	 */
	public function forget( EntityRef $entity ) {
		return $this->erase( $this->store->ids_for_entity( $entity, $this->removable( $entity ) ) );
	}

	/**
	 * Returns the predicates whose statements go with an entity: `on_delete` is `remove` and the entity type can be a subject or an
	 * object of the predicate (an empty list accepts any entity type).
	 *
	 * @param EntityRef $entity Entity.
	 * @return string[]
	 */
	public function removable( EntityRef $entity ) {
		$slugs = array();

		foreach ( $this->predicates->all() as $slug => $predicate ) {
			if ( 'remove' === $predicate->on_delete() && $this->involves( $predicate, $entity->type() ) ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Tells whether an entity type can be an end of a predicate.
	 *
	 * @param PredicateDefinition $predicate Predicate.
	 * @param string              $type      Entity type.
	 * @return bool
	 */
	private function involves( PredicateDefinition $predicate, $type ) {
		foreach ( array( $predicate->subject_types(), $predicate->object_types() ) as $types ) {
			if ( array() === $types || in_array( $type, $types, true ) ) {
				return true;
			}
		}

		return false;
	}
}
