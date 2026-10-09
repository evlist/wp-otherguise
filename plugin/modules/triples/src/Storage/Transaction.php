<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Database transaction.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a piece of work in a transaction: committed when it returns, rolled back when it throws.
 *
 * Transactions can be nested. Only the outermost call issues `START TRANSACTION` and `COMMIT`; an inner call sets a savepoint, so that
 * its failure undoes its own work and nothing else (a caller that catches the exception keeps the outer work), and a failure that goes up
 * to the outermost call undoes everything. The tables must use a transactional engine (InnoDB, the default of MySQL and MariaDB) for the
 * rollback to undo anything.
 */
final class Transaction {
	/**
	 * Runs the work.
	 *
	 * @param Database $database Database.
	 * @param callable $work     Work to do; its result is returned.
	 * @return mixed
	 * @throws \Throwable          What the work throws, after the rollback.
	 * @throws \RuntimeException When the commit fails.
	 */
	public static function run( Database $database, $work ) {
		if ( $database->transaction_depth() > 0 ) {
			return self::run_nested( $database, $work );
		}

		$database->execute( 'START TRANSACTION' );
		$database->enter_transaction();

		try {
			$result = $work();
		} catch ( \Throwable $problem ) {
			$database->leave_transaction();
			$database->execute( 'ROLLBACK' );

			throw $problem;
		}

		$database->leave_transaction();

		if ( false === $database->execute( 'COMMIT' ) ) {
			$database->execute( 'ROLLBACK' );

			throw new \RuntimeException( 'The transaction could not be committed.' );
		}

		return $result;
	}

	/**
	 * Runs the work inside the transaction in progress, behind a savepoint.
	 *
	 * @param Database $database Database.
	 * @param callable $work     Work to do.
	 * @return mixed
	 * @throws \Throwable What the work throws, after the rollback to the savepoint.
	 */
	private static function run_nested( Database $database, $work ) {
		$savepoint = 'og_sp_' . ( $database->transaction_depth() + 1 );

		$database->execute( 'SAVEPOINT ' . $savepoint );
		$database->enter_transaction();

		try {
			$result = $work();
		} catch ( \Throwable $problem ) {
			$database->leave_transaction();
			$database->execute( 'ROLLBACK TO SAVEPOINT ' . $savepoint );

			throw $problem;
		}

		$database->leave_transaction();
		$database->execute( 'RELEASE SAVEPOINT ' . $savepoint );

		return $result;
	}
}
