<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Search for the statements that have an end that no longer exists.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\StatementQuery;
use Otherguise\Triples\Storage\StatementStore;

defined( 'ABSPATH' ) || exit;

/**
 * Goes through the table by batches in the order of the ids. A statement is an orphan when its subject, or its object when that is an
 * entity, belongs to a type that can check existence and does not exist. Types that cannot check are never reported. Nothing is stored
 * between two scans.
 */
final class OrphanScanner {
	/**
	 * Number of statements per batch.
	 */
	public const BATCH = 200;

	/**
	 * Store.
	 *
	 * @var StatementStore
	 */
	private $store;

	/**
	 * Entity types.
	 *
	 * @var EntityTypeRegistry
	 */
	private $types;

	/**
	 * Builds the scanner.
	 *
	 * @param StatementStore     $store Store.
	 * @param EntityTypeRegistry $types Entity types.
	 */
	public function __construct( StatementStore $store, EntityTypeRegistry $types ) {
		$this->store = $store;
		$this->types = $types;
	}

	/**
	 * Scans a batch.
	 *
	 * @param int                 $after Scan the statements with an id above this one.
	 * @param StatementQuery|null $base  Filters the batch must respect, or null for all the statements.
	 * @param int                 $count Size of the batch.
	 * @return array{orphans: Statement[], last_id: int, done: bool} The last id looked at ($after when the batch is empty) and whether the end of the table was reached.
	 */
	public function scan( $after, ?StatementQuery $base = null, $count = self::BATCH ) {
		$count = max( 1, (int) $count );
		$batch = $this->store->query( ( $base ?? new StatementQuery() )->after_id( $after )->limit( $count ) );
		$found = array();
		$last  = max( 0, (int) $after );

		foreach ( $batch as $statement ) {
			$last = (int) $statement->id();

			if ( $this->is_orphan( $statement ) ) {
				$found[] = $statement;
			}
		}

		return array(
			'orphans' => $found,
			'last_id' => $last,
			'done'    => count( $batch ) < $count,
		);
	}

	/**
	 * Tells whether a statement has a missing end.
	 *
	 * @param Statement $statement Statement.
	 * @return bool
	 */
	public function is_orphan( Statement $statement ) {
		return $this->is_missing( $statement->subject() ) || ( $statement->object() instanceof EntityRef && $this->is_missing( $statement->object() ) );
	}

	/**
	 * Tells whether an entity is known not to exist.
	 *
	 * @param EntityRef $entity Entity.
	 * @return bool
	 */
	private function is_missing( EntityRef $entity ) {
		return $this->types->has( $entity->type() ) && false === $this->types->get( $entity->type() )->exists( $entity->id() );
	}
}
