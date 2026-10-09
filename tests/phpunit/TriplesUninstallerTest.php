<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the removal of the data.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Storage\SchemaManager;
use Otherguise\Triples\Storage\Uninstaller;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-wpdb.php';

/**
 * Tests of the removal of the data.
 *
 * @covers \Otherguise\Triples\Storage\Uninstaller
 */
class TriplesUninstallerTest extends TestCase {

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

		$this->wpdb      = new Otherguise_Test_Wpdb( 'wp_' );
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	/**
	 * The data is kept unless the administrator asked for its deletion.
	 *
	 * @return void
	 */
	public function test_the_data_is_kept_unless_the_administrator_asked_for_its_deletion(): void {
		update_option( SchemaManager::VERSION_OPTION, 1 );

		( new Uninstaller( $this->wpdb ) )->run();

		$this->assertSame( array(), $this->wpdb->queries );
		$this->assertSame( array(), $GLOBALS['otherguise_test_calls'] );
		$this->assertSame( 1, get_option( SchemaManager::VERSION_OPTION ) );
	}

	/**
	 * The deletion flag must be set in the settings.
	 *
	 * @return void
	 */
	public function test_the_deletion_flag_must_be_set_in_the_settings(): void {
		update_option( Uninstaller::SETTINGS_OPTION, array( Uninstaller::DELETE_FLAG => false ) );

		( new Uninstaller( $this->wpdb ) )->run();

		$this->assertSame( array(), $this->wpdb->queries );
	}

	/**
	 * The table and the options are removed when requested.
	 *
	 * @return void
	 */
	public function test_the_table_and_the_options_are_removed_when_requested(): void {
		update_option( SchemaManager::VERSION_OPTION, 1 );
		update_option( Uninstaller::SETTINGS_OPTION, array( Uninstaller::DELETE_FLAG => true ) );

		( new Uninstaller( $this->wpdb ) )->run();

		$this->assertSame( array( 'DROP TABLE IF EXISTS `wp_triples_statements`' ), $this->wpdb->queries );
		$this->assertFalse( get_option( SchemaManager::VERSION_OPTION ) );
		$this->assertFalse( get_option( Uninstaller::SETTINGS_OPTION ) );
		$this->assertSame(
			array(
				array( 'delete_metadata', 'user', 0, 'triples_per_page', '', true ),
				array( 'delete_metadata', 'user', 0, 'wp_triples_per_page', '', true ),
			),
			$GLOBALS['otherguise_test_calls']
		);
	}

	/**
	 * On a network every site that asked is processed with its own prefix.
	 *
	 * @return void
	 */
	public function test_on_a_network_every_site_that_asked_is_processed_with_its_own_prefix(): void {
		$GLOBALS['otherguise_test_sites'] = array( 2, 3, 4 );

		$GLOBALS['otherguise_test_site'] = 2;
		update_option( Uninstaller::SETTINGS_OPTION, array( Uninstaller::DELETE_FLAG => true ) );
		$GLOBALS['otherguise_test_site'] = 4;
		update_option( Uninstaller::SETTINGS_OPTION, array( Uninstaller::DELETE_FLAG => true ) );

		( new Uninstaller( $this->wpdb ) )->run();

		$this->assertSame( array( 'DROP TABLE IF EXISTS `wp_2_triples_statements`', 'DROP TABLE IF EXISTS `wp_4_triples_statements`' ), $this->wpdb->queries );
	}
}
