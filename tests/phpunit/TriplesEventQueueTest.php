<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the queue of the events of the statements.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Service\EventQueue;
use Otherguise\Triples\Storage\Database;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\Transaction;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-wpdb.php';

/**
 * No database: the test object only records the queries.
 *
 * @covers \Otherguise\Triples\Service\EventQueue
 */
class TriplesEventQueueTest extends TestCase {

	/**
	 * Database.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Queue.
	 *
	 * @var EventQueue
	 */
	private $queue;

	/**
	 * Events fired, as "kind:id".
	 *
	 * @var string[]
	 */
	private $fired = array();

	/**
	 * Builds the queue.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->database = new Database( new Otherguise_Test_Wpdb( 'wp_' ) );
		$this->fired    = array();
		$this->queue    = new EventQueue(
			$this->database,
			function ( $hook, Statement $statement ) {
				$this->fired[] = str_replace( 'triples_statement_', '', $hook ) . ':' . $statement->id();
			}
		);
	}

	/**
	 * Builds a stored statement with an id.
	 *
	 * @param int $id Id.
	 * @return Statement
	 */
	private function statement( $id ) {
		return new Statement( new EntityRef( 'post', '1' ), 'a/b', new EntityRef( 'post', '2' ), $id );
	}

	/**
	 * Outside a transaction an event is fired at once.
	 *
	 * @return void
	 */
	public function test_events_outside_a_transaction_are_fired_at_once(): void {
		$this->queue->created( $this->statement( 1 ) );
		$this->queue->deleted( $this->statement( 2 ) );

		$this->assertSame( array( 'created:1', 'deleted:2' ), $this->fired );
	}

	/**
	 * In a transaction the events wait for the commit and keep their order.
	 *
	 * @return void
	 */
	public function test_events_wait_for_the_commit(): void {
		Transaction::run(
			$this->database,
			function () {
				$this->queue->created( $this->statement( 1 ) );
				$this->queue->deleted( $this->statement( 2 ) );
				$this->queue->created( $this->statement( 3 ) );

				$this->assertSame( array(), $this->fired );
			}
		);

		$this->assertSame( array( 'created:1', 'deleted:2', 'created:3' ), $this->fired );
	}

	/**
	 * A rollback drops the events.
	 *
	 * @return void
	 */
	public function test_a_rollback_drops_the_events(): void {
		try {
			Transaction::run(
				$this->database,
				function () {
					$this->queue->created( $this->statement( 1 ) );

					throw new RuntimeException( 'x' );
				}
			);
		} catch ( RuntimeException $problem ) {
			unset( $problem );
		}

		$this->assertSame( array(), $this->fired );

		$this->queue->created( $this->statement( 2 ) );

		$this->assertSame( array( 'created:2' ), $this->fired );
	}

	/**
	 * Nested transactions fire once, at the outermost commit; a nested rollback drops only its own events.
	 *
	 * @return void
	 */
	public function test_nested_transactions(): void {
		Transaction::run(
			$this->database,
			function () {
				$this->queue->created( $this->statement( 1 ) );

				Transaction::run( $this->database, fn() => $this->queue->created( $this->statement( 2 ) ) );

				$this->assertSame( array(), $this->fired );

				try {
					Transaction::run(
						$this->database,
						function () {
							$this->queue->created( $this->statement( 3 ) );

							throw new RuntimeException( 'x' );
						}
					);
				} catch ( RuntimeException $problem ) {
					unset( $problem );
				}

				$this->queue->created( $this->statement( 4 ) );
			}
		);

		$this->assertSame( array( 'created:1', 'created:2', 'created:4' ), $this->fired );
	}

	/**
	 * An outer rollback drops the events of the inner transactions that had committed.
	 *
	 * @return void
	 */
	public function test_an_outer_rollback_drops_the_inner_events(): void {
		try {
			Transaction::run(
				$this->database,
				function () {
					Transaction::run( $this->database, fn() => $this->queue->created( $this->statement( 1 ) ) );

					throw new RuntimeException( 'x' );
				}
			);
		} catch ( RuntimeException $problem ) {
			unset( $problem );
		}

		$this->assertSame( array(), $this->fired );
	}

	/**
	 * A callback that writes while the events are fired does not disturb the order.
	 *
	 * @return void
	 */
	public function test_a_callback_may_queue_more_events(): void {
		$again = new EventQueue(
			$this->database,
			function ( $hook, Statement $statement ) use ( &$again ) {
				$this->fired[] = $statement->id();

				if ( 1 === $statement->id() ) {
					$again->created( $this->statement( 2 ) );
				}
			}
		);

		Transaction::run( $this->database, fn() => $again->created( $this->statement( 1 ) ) );

		$this->assertSame( array( 1, 2 ), $this->fired );
	}
}
