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
 * A transaction cannot be nested: `START TRANSACTION` commits the one in progress. The tables must use a transactional engine (InnoDB,
 * the default of MySQL and MariaDB) for the rollback to undo anything.
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
		$database->execute( 'START TRANSACTION' );

		try {
			$result = $work();
		} catch ( \Throwable $problem ) {
			$database->execute( 'ROLLBACK' );

			throw $problem;
		}

		if ( false === $database->execute( 'COMMIT' ) ) {
			$database->execute( 'ROLLBACK' );

			throw new \RuntimeException( 'The transaction could not be committed.' );
		}

		return $result;
	}
}
