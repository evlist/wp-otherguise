<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the notifications sent to the listeners of a database about its transactions.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Storage\Database;
use Otherguise\Triples\Storage\Transaction;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-wpdb.php';

/**
 * No database: the test object only records the queries.
 *
 * @covers \Otherguise\Triples\Storage\Database
 * @covers \Otherguise\Triples\Storage\Transaction
 */
class TriplesTransactionEventsTest extends TestCase {

	/**
	 * Database.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Events received, as "event level".
	 *
	 * @var string[]
	 */
	private $events = array();

	/**
	 * Prepares the database and its listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->database = new Database( new Otherguise_Test_Wpdb( 'wp_' ) );
		$this->events   = array();

		$this->database->listen(
			function ( $event, $level ) {
				$this->events[] = $event . ' ' . $level;
			}
		);
	}

	/**
	 * A transaction that works begins and commits.
	 *
	 * @return void
	 */
	public function test_begin_and_commit(): void {
		Transaction::run( $this->database, fn() => 1 );

		$this->assertSame( array( 'begin 1', 'commit 1' ), $this->events );
	}

	/**
	 * A transaction that fails begins and rolls back.
	 *
	 * @return void
	 */
	public function test_begin_and_rollback(): void {
		try {
			Transaction::run(
				$this->database,
				function () {
					throw new RuntimeException( 'x' );
				}
			);
		} catch ( RuntimeException $problem ) {
			unset( $problem );
		}

		$this->assertSame( array( 'begin 1', 'rollback 1' ), $this->events );
	}

	/**
	 * Nested transactions report their level, and a caught inner failure rolls back only its level.
	 *
	 * @return void
	 */
	public function test_nested_levels(): void {
		Transaction::run(
			$this->database,
			function () {
				Transaction::run( $this->database, fn() => 1 );

				try {
					Transaction::run(
						$this->database,
						function () {
							throw new RuntimeException( 'x' );
						}
					);
				} catch ( RuntimeException $problem ) {
					unset( $problem );
				}
			}
		);

		$this->assertSame(
			array( 'begin 1', 'begin 2', 'commit 2', 'begin 2', 'rollback 2', 'commit 1' ),
			$this->events
		);
	}
}
