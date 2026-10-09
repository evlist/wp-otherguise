<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Queue of the events of the statements.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Service;

use Otherguise\Triples\Storage\Database;
use Otherguise\Triples\Storage\Statement;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the events (`triples_statement_created`, `triples_statement_deleted`) of a transaction and fires them when the outermost one
 * commits, in the order they happened. A rollback drops the events of the work it undoes (a nested rollback drops only its own).
 * Outside a transaction an event is fired at once.
 *
 * A callback that throws is not caught: the exception goes to the caller, the statement is already committed, and the events that
 * were still waiting are lost.
 */
final class EventQueue {
	/**
	 * Database, for the depth of the transactions.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Fires an event: receives the hook name and the statement.
	 *
	 * @var callable
	 */
	private $fire;

	/**
	 * Events waiting, as `array( hook, statement )`.
	 *
	 * @var array<int, array{0: string, 1: Statement}>
	 */
	private $events = array();

	/**
	 * Number of events waiting when each open transaction began.
	 *
	 * @var int[]
	 */
	private $marks = array();

	/**
	 * Builds the queue and listens to the transactions of the database.
	 *
	 * @param Database $database Database.
	 * @param callable $fire     Fires an event.
	 */
	public function __construct( Database $database, $fire ) {
		$this->database = $database;
		$this->fire     = $fire;

		$database->listen( array( $this, 'on_transaction' ) );
	}

	/**
	 * Records that a statement was created.
	 *
	 * @param Statement $statement Statement.
	 * @return void
	 */
	public function created( Statement $statement ) {
		$this->push( 'triples_statement_created', $statement );
	}

	/**
	 * Records that a statement was deleted.
	 *
	 * @param Statement $statement Statement as it was stored.
	 * @return void
	 */
	public function deleted( Statement $statement ) {
		$this->push( 'triples_statement_deleted', $statement );
	}

	/**
	 * Follows the transactions of the database. Called by the database.
	 *
	 * @param string $event `begin`, `commit` or `rollback`.
	 * @param int    $level Level of the transaction.
	 * @return void
	 */
	public function on_transaction( $event, $level ) {
		if ( 'begin' === $event ) {
			$this->marks[] = count( $this->events );

			return;
		}

		$mark = array_pop( $this->marks );

		if ( 'rollback' === $event ) {
			$this->events = array_slice( $this->events, 0, (int) $mark );

			return;
		}

		if ( 1 === $level ) {
			$this->flush();
		}
	}

	/**
	 * Adds an event, and fires it when no transaction is open.
	 *
	 * @param string    $hook      Hook name.
	 * @param Statement $statement Statement.
	 * @return void
	 */
	private function push( $hook, Statement $statement ) {
		$this->events[] = array( $hook, $statement );

		if ( 0 === $this->database->transaction_depth() ) {
			$this->flush();
		}
	}

	/**
	 * Fires the events waiting.
	 *
	 * @return void
	 */
	private function flush() {
		$events       = $this->events;
		$this->events = array();

		foreach ( $events as $event ) {
			( $this->fire )( $event[0], $event[1] );
		}
	}
}
