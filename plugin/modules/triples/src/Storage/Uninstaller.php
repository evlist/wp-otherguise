<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Removal of the data of the module.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the table and the options of the module when the plugin is deleted.
 *
 * The statements are the work of the author, so nothing is removed unless the administrator asked for it with the setting
 * `delete_data_on_uninstall` of the option `triples_settings` (set on the maintenance tab of the administration screen). On a multisite network every site is processed.
 */
final class Uninstaller {
	/**
	 * Option holding the settings of the module.
	 */
	public const SETTINGS_OPTION = 'triples_settings';

	/**
	 * Key of the setting that asks for the deletion.
	 */
	public const DELETE_FLAG = 'delete_data_on_uninstall';

	/**
	 * User meta of the screen option "statements per page" (WordPress stores it per site, under the table prefix; removed for all the users with the data).
	 */
	public const PER_PAGE_META = 'triples_per_page';

	/**
	 * The wpdb object.
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * Builds the uninstaller.
	 *
	 * @param object $wpdb A wpdb or a compatible object.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Removes the data of every site where the deletion was requested.
	 *
	 * @return void
	 */
	public function run() {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				$this->uninstall_current_site();
				restore_current_blog();
			}

			return;
		}

		$this->uninstall_current_site();
	}

	/**
	 * Removes the table and the options of the current site if the deletion was requested.
	 *
	 * @return void
	 */
	private function uninstall_current_site() {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $settings ) || empty( $settings[ self::DELETE_FLAG ] ) ) {
			return;
		}

		( new SchemaManager( new Database( $this->wpdb ) ) )->drop_table();

		delete_option( self::SETTINGS_OPTION );
		delete_metadata( 'user', 0, $this->wpdb->prefix . self::PER_PAGE_META, '', true ); // The screen option is stored per site, under the prefix of the site.
	}
}
