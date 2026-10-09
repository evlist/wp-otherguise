<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Base of the tests that need a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Storage\Database;
use Otherguise\Triples\Storage\SchemaManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/class-otherguise-test-wpdb.php';

/**
 * Connects to the MySQL or MariaDB database described by the environment variable OTHERGUISE_TEST_DB and creates the table of the
 * statements under a unique prefix. The tests are skipped, never silently passed, when the variable is missing.
 *
 * The value is a list of `key=value` pairs separated by semicolons: `host=127.0.0.1;port=3307;user=root;password=;dbname=wordpress`.
 */
abstract class Otherguise_Test_Database_Case extends TestCase {
	/**
	 * Connection.
	 *
	 * @var mysqli|null
	 */
	private $mysqli;

	/**
	 * Stand-in for wpdb, bound to the connection.
	 *
	 * @var Otherguise_Test_Wpdb|null
	 */
	protected $wpdb;

	/**
	 * Database wrapper.
	 *
	 * @var Database|null
	 */
	protected $database;

	/**
	 * Connects and creates the table.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();

		$dsn = getenv( 'OTHERGUISE_TEST_DB' );

		if ( ! is_string( $dsn ) || '' === $dsn ) {
			$this->markTestSkipped( 'OTHERGUISE_TEST_DB is not set: the integration tests need a MySQL or MariaDB database.' );
		}

		$settings = array();

		foreach ( explode( ';', $dsn ) as $pair ) {
			$parts = explode( '=', $pair, 2 );

			if ( 2 === count( $parts ) ) {
				$settings[ trim( $parts[0] ) ] = $parts[1];
			}
		}

		mysqli_report( MYSQLI_REPORT_OFF );

		$this->mysqli = new mysqli(
			$settings['host'] ?? '127.0.0.1',
			$settings['user'] ?? 'root',
			$settings['password'] ?? '',
			$settings['dbname'] ?? 'WordPress',
			(int) ( $settings['port'] ?? 3306 )
		);

		if ( $this->mysqli->connect_errno ) {
			$this->fail( 'Cannot connect to the test database: ' . $this->mysqli->connect_error );
		}

		$this->mysqli->set_charset( 'utf8mb4' );

		$this->wpdb     = new Otherguise_Test_Wpdb( 'ogt' . bin2hex( random_bytes( 4 ) ) . '_', $this->mysqli );
		$this->database = new Database( $this->wpdb );

		$GLOBALS['wpdb'] = $this->wpdb;

		$this->assertNotFalse(
			$this->wpdb->query( SchemaManager::create_table_sql( $this->table(), $this->wpdb->get_charset_collate() ) ),
			'Cannot create the table: ' . $this->wpdb->last_error
		);
	}

	/**
	 * Drops the table and disconnects.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null !== $this->mysqli ) {
			$this->wpdb->query( 'DROP TABLE IF EXISTS `' . $this->table() . '`' );
			$this->mysqli->close();
		}

		otherguise_test_reset();
	}

	/**
	 * Returns the name of the table of the statements.
	 *
	 * @return string
	 */
	protected function table() {
		return $this->wpdb->prefix . SchemaManager::TABLE;
	}
}
