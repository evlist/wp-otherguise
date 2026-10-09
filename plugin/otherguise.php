<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Plugin Name: Otherguise
 * Description: The same content in another guise: relations between things, alternative templates selected by mode, and books assembled from posts.
 * Version: 0.0.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Eric van der Vlist
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: otherguise
 *
 * @package Otherguise
 */

defined( 'ABSPATH' ) || exit;

define( 'OTHERGUISE_VERSION', '0.0.1' );
define( 'OTHERGUISE_PLUGIN_FILE', __FILE__ );

require_once plugin_dir_path( __FILE__ ) . 'includes/Core/Autoloader.php';

\Otherguise\Core\Autoloader::register( plugin_dir_path( __FILE__ ) );

/**
 * Loads plugin translations.
 *
 * WordPress.org language packs are loaded automatically. This function adds
 * a fallback for direct distributions by loading MO files from the plugin
 * `languages` directory when needed.
 *
 * @return void
 */
function otherguise_load_textdomain() {
	$locale = determine_locale();

	$wp_lang_mofile = WP_LANG_DIR . '/plugins/otherguise-' . $locale . '.mo';
	if ( is_readable( $wp_lang_mofile ) ) {
		load_textdomain( 'otherguise', $wp_lang_mofile );
		return;
	}

	$plugin_mofile = plugin_dir_path( __FILE__ ) . 'languages/otherguise-' . $locale . '.mo';
	if ( is_readable( $plugin_mofile ) ) {
		load_textdomain( 'otherguise', $plugin_mofile );
	}
}
add_action( 'init', 'otherguise_load_textdomain' );

/**
 * Activates the modules, dependencies first: creates their tables and options.
 *
 * @return void
 */
function otherguise_activate() {
	$modules = require plugin_dir_path( OTHERGUISE_PLUGIN_FILE ) . 'includes/modules.php';

	( new \Otherguise\Core\ModuleLoader( $modules ) )->activate();
}
register_activation_hook( __FILE__, 'otherguise_activate' );

/**
 * Boots the enabled modules, dependencies first.
 *
 * @return void
 */
function otherguise_bootstrap() {
	$modules = require plugin_dir_path( OTHERGUISE_PLUGIN_FILE ) . 'includes/modules.php';

	/**
	 * Filters the identifiers of the enabled modules.
	 *
	 * Returning null enables every module. A module cannot be enabled without the modules it depends on.
	 *
	 * @param string[]|null $enabled Identifiers of the enabled modules, or null for all.
	 */
	$enabled = apply_filters( 'otherguise_enabled_modules', null );

	$loader = new \Otherguise\Core\ModuleLoader( $modules, is_array( $enabled ) ? $enabled : null );

	\Otherguise\Core\Modules::set( $loader );
	$loader->boot();
}
add_action( 'plugins_loaded', 'otherguise_bootstrap' );
