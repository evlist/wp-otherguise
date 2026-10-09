<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the schema manager that need no database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Storage\Database;
use Otherguise\Triples\Storage\SchemaManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-wpdb.php';

/**
 * Tests of the schema manager that need no database.
 *
 * @covers \Otherguise\Triples\Storage\SchemaManager
 * @covers \Otherguise\Triples\Storage\Database
 */
class TriplesSchemaManagerTest extends TestCase {

	/**
	 * Stand-in for wpdb that records the queries.
	 *
	 * @var Otherguise_Test_Wpdb
	 */
	private $wpdb;

	/**
	 * Prepares a recording database.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();

		$this->wpdb = new Otherguise_Test_Wpdb( 'wp_' );
	}

	/**
	 * Builds a manager.
	 *
	 * @param array<int, callable>|null $steps Migration steps.
	 * @return SchemaManager
	 */
	private function manager( $steps = null ) {
		return new SchemaManager( new Database( $this->wpdb ), $steps );
	}

	/**
	 * The table is named after the module and the site prefix.
	 *
	 * @return void
	 */
	public function test_the_table_is_named_after_the_module_and_the_site_prefix(): void {
		$this->assertSame( 'wp_triples_statements', $this->manager()->table_name() );

		$this->wpdb->prefix = 'wp_3_';

		$this->assertSame( 'wp_3_triples_statements', $this->manager()->table_name() );
	}

	/**
	 * The create statement follows the conventions of dbdelta.
	 *
	 * @return void
	 */
	public function test_the_create_statement_follows_the_conventions_of_dbdelta(): void {
		$sql = SchemaManager::create_table_sql( 'wp_triples_statements', 'DEFAULT CHARACTER SET utf8mb4' );

		$this->assertStringStartsWith( 'CREATE TABLE wp_triples_statements (', $sql );
		$this->assertStringContainsString( "\n  PRIMARY KEY  (id),", $sql, 'Two spaces after PRIMARY KEY.' );
		$this->assertStringEndsWith( ') DEFAULT CHARACTER SET utf8mb4;', $sql );
		$this->assertStringNotContainsString( '`', $sql );
		$this->assertStringNotContainsString( 'INDEX', $sql );
		$this->assertStringContainsString( "\n  UNIQUE KEY triple (subject_type,subject_id,predicate,object_type,object_id),", $sql );

		foreach ( array_slice( explode( "\n", $sql ), 1, -1 ) as $line ) {
			$this->assertSame( 1, preg_match( '/^  [A-Za-z_ ]+/', $line ), 'One definition per line, indented by two spaces: ' . $line );
		}
	}

	/**
	 * The columns have the sizes the registries enforce.
	 *
	 * @return void
	 */
	public function test_the_columns_have_the_sizes_the_registries_enforce(): void {
		$sql = SchemaManager::create_table_sql( 't', '' );

		foreach ( array( 'subject_type varchar(20)', 'object_type varchar(20)', 'predicate varchar(64)', 'subject_id varbinary(191)', 'object_id varbinary(191)' ) as $column ) {
			$this->assertStringContainsString( $column . ' NOT NULL', $sql );
		}
	}

	/**
	 * A missing table is created and the version recorded.
	 *
	 * @return void
	 */
	public function test_a_missing_table_is_created_and_the_version_recorded(): void {
		$this->assertTrue( $this->manager()->maybe_upgrade() );

		$this->assertCount( 1, $GLOBALS['otherguise_test_dbdelta'] );
		$this->assertStringContainsString( 'CREATE TABLE wp_triples_statements', $GLOBALS['otherguise_test_dbdelta'][0] );
		$this->assertSame( SchemaManager::SCHEMA_VERSION, get_option( SchemaManager::VERSION_OPTION ) );
	}

	/**
	 * An up to date schema costs one option read.
	 *
	 * @return void
	 */
	public function test_an_up_to_date_schema_costs_one_option_read(): void {
		update_option( SchemaManager::VERSION_OPTION, SchemaManager::SCHEMA_VERSION );

		$this->assertTrue( $this->manager()->maybe_upgrade() );
		$this->assertSame( array(), $GLOBALS['otherguise_test_dbdelta'] );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	/**
	 * The steps newer than the stored version run in version order.
	 *
	 * @return void
	 */
	public function test_the_steps_newer_than_the_stored_version_run_in_version_order(): void {
		$ran = array();

		update_option( SchemaManager::VERSION_OPTION, 0 );

		$manager = $this->manager(
			array(
				1 => static function () use ( &$ran ) {
					$ran[] = 1;
				},
				2 => static function () use ( &$ran ) {
					$ran[] = 2;
				},
			)
		);

		// The current version is 1: a step of a later version is not run, and a step of the current one runs once.
		$this->assertTrue( $manager->maybe_upgrade() );
		$this->assertSame( array( 1 ), $ran );
	}

	/**
	 * A failing step leaves the stored version untouched.
	 *
	 * @return void
	 */
	public function test_a_failing_step_leaves_the_stored_version_untouched(): void {
		$manager = $this->manager(
			array(
				1 => static function () {
					return false;
				},
			)
		);

		$this->assertFalse( $manager->maybe_upgrade() );
		$this->assertSame( 'Step 1 failed.', $manager->last_error() );
		$this->assertFalse( get_option( SchemaManager::VERSION_OPTION ) );
	}

	/**
	 * A step that throws is a failure too.
	 *
	 * @return void
	 */
	public function test_a_step_that_throws_is_a_failure_too(): void {
		$manager = $this->manager(
			array(
				1 => static function () {
					throw new RuntimeException( 'boom' );
				},
			)
		);

		$this->assertFalse( $manager->maybe_upgrade() );
		$this->assertSame( 'Step 1 failed: boom', $manager->last_error() );
		$this->assertFalse( get_option( SchemaManager::VERSION_OPTION ) );
	}

	/**
	 * The table is dropped with its version.
	 *
	 * @return void
	 */
	public function test_the_table_is_dropped_with_its_version(): void {
		update_option( SchemaManager::VERSION_OPTION, 1 );

		$this->manager()->drop_table();

		$this->assertSame( array( 'DROP TABLE IF EXISTS `wp_triples_statements`' ), $this->wpdb->queries );
		$this->assertFalse( get_option( SchemaManager::VERSION_OPTION ) );
	}

	/**
	 * A prefix with unexpected characters is never written in a query.
	 *
	 * @return void
	 */
	public function test_a_prefix_with_unexpected_characters_is_never_written_in_a_query(): void {
		$this->wpdb->prefix = 'wp_`; DROP DATABASE x; --';

		$this->manager()->drop_table();

		$this->assertSame( array(), $this->wpdb->queries );
	}
}
