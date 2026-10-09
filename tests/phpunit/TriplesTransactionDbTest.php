<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the re-entrant transaction, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Datatype\DatatypeRegistry;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\StatementQuery;
use Otherguise\Triples\Storage\StatementStore;
use Otherguise\Triples\Storage\Transaction;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';

/**
 * Transactions can be nested: only the outermost one commits, and an inner failure undoes only the inner work.
 *
 * @covers \Otherguise\Triples\Storage\Database
 * @covers \Otherguise\Triples\Storage\Transaction
 */
class TriplesTransactionDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Builds a store.
	 *
	 * @return StatementStore
	 */
	private function store() {
		return new StatementStore( $this->database, DatatypeRegistry::with_builtins() );
	}

	/**
	 * Stores a statement `post:N related attachment:N`.
	 *
	 * @param StatementStore $store  Store.
	 * @param int            $number Number.
	 * @return int
	 */
	private function add( StatementStore $store, $number ) {
		return $store->insert( new Statement( new EntityRef( 'post', (string) $number ), 'p/q', new EntityRef( 'attachment', (string) $number ) ) );
	}

	/**
	 * A nested transaction does not commit the outer one: the outer rollback undoes everything.
	 *
	 * @return void
	 */
	public function test_an_outer_failure_undoes_the_inner_work(): void {
		$store = $this->store();

		try {
			Transaction::run(
				$this->database,
				function () use ( $store ) {
					$this->add( $store, 1 );
					Transaction::run( $this->database, fn() => $this->add( $store, 2 ) );

					throw new RuntimeException( 'outer failure' );
				}
			);
			$this->fail( 'The exception should have been rethrown.' );
		} catch ( RuntimeException $problem ) {
			$this->assertSame( 'outer failure', $problem->getMessage() );
		}

		$this->assertSame( 0, $store->count( new StatementQuery() ) );
		$this->assertSame( 0, $this->database->transaction_depth() );
	}

	/**
	 * An inner failure caught by the outer work undoes the inner work only.
	 *
	 * @return void
	 */
	public function test_a_caught_inner_failure_undoes_only_the_inner_work(): void {
		$store = $this->store();

		Transaction::run(
			$this->database,
			function () use ( $store ) {
				$this->add( $store, 1 );

				try {
					Transaction::run(
						$this->database,
						function () use ( $store ) {
							$this->add( $store, 2 );

							throw new RuntimeException( 'inner failure' );
						}
					);
				} catch ( RuntimeException $problem ) {
					unset( $problem );
				}

				$this->add( $store, 3 );
			}
		);

		$subjects = array_map(
			static function ( Statement $statement ) {
				return $statement->subject()->id();
			},
			$store->query( new StatementQuery() )
		);

		$this->assertSame( array( '1', '3' ), $subjects );
		$this->assertSame( 0, $this->database->transaction_depth() );
	}

	/**
	 * The work of nested transactions is committed once, by the outermost one, and results are returned.
	 *
	 * @return void
	 */
	public function test_nested_transactions_commit_together_and_return_their_result(): void {
		$store  = $this->store();
		$result = Transaction::run(
			$this->database,
			function () use ( $store ) {
				$this->add( $store, 1 );
				$this->assertSame( 1, $this->database->transaction_depth() );

				return Transaction::run(
					$this->database,
					function () use ( $store ) {
						$this->assertSame( 2, $this->database->transaction_depth() );

						return $this->add( $store, 2 );
					}
				);
			}
		);

		$this->assertSame( 2, $store->count( new StatementQuery() ) );
		$this->assertSame( $result, $store->count( new StatementQuery() ) );
	}
}
