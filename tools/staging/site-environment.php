<?php
/**
 * Plugin Name: Site environment
 * Description: Says which site this is, with a coloured frame and a label that never get in the way: production (red, administration only), staging (orange) and testing (green), everywhere.
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Must-use plugin of tools/staging/, installed on the three sites (on production by hand: the scripts never write there). The site says
 * what it is through WP_ENVIRONMENT_TYPE, which WordPress already knows: `production` (the default when nothing is defined), `staging`,
 * and `development` or `local`, shown as "testing". The sync script of staging also records the date of the copy in the option
 * `otherguise_site_synced_at`, shown in the label.
 *
 * @package Otherguise
 */

defined( 'ABSPATH' ) || exit;

/**
 * Describes the environment of this site.
 *
 * @return array{label: string, color: string, everywhere: bool}
 */
function otherguise_site_environment() {
	switch ( wp_get_environment_type() ) {
		case 'staging':
			return array(
				'label'      => 'staging',
				'color'      => '#e65100',
				'everywhere' => true,
			);
		case 'development':
		case 'local':
			return array(
				'label'      => 'testing',
				'color'      => '#2e7d32',
				'everywhere' => true,
			);
		default:
			return array(
				'label'      => 'production',
				'color'      => '#c62828',
				'everywhere' => false,
			);
	}
}

/**
 * Returns the text of the label: the name of the environment and, for a copy, the date of the copy.
 *
 * @param array $environment Environment, see otherguise_site_environment().
 * @return string
 */
function otherguise_site_environment_text( $environment ) {
	$text   = $environment['label'];
	$synced = $environment['everywhere'] ? strtotime( (string) get_option( 'otherguise_site_synced_at', '' ) ) : false;

	return false === $synced ? $text : $text . ' (copy of ' . wp_date( 'Y-m-d H:i', $synced ) . ')';
}

/**
 * Prints the frame and the label: fixed, above the page, and transparent to clicks so that they never hide a button. Not printed on
 * paper nor in a PDF.
 *
 * @return void
 */
function otherguise_site_environment_print() {
	$environment = otherguise_site_environment();

	if ( ! $environment['everywhere'] && ! is_admin() && 'wp-login.php' !== $GLOBALS['pagenow'] ) {
		return;
	}

	printf(
		'<style>#otherguise-site-environment{position:fixed;inset:0;z-index:2147483647;pointer-events:none;box-sizing:border-box;border:4px solid %1$s}#otherguise-site-environment span{position:absolute;right:8px;bottom:6px;padding:2px 10px;border-radius:3px;background:%1$s;color:#fff;font:600 12px/1.6 sans-serif;letter-spacing:.04em;text-transform:uppercase;opacity:.85}@media print{#otherguise-site-environment{display:none}}</style><div id="otherguise-site-environment" aria-hidden="true"><span>%2$s</span></div>',
		esc_attr( $environment['color'] ),
		esc_html( otherguise_site_environment_text( $environment ) )
	);
}
add_action( 'wp_footer', 'otherguise_site_environment_print', 1000 );
add_action( 'admin_footer', 'otherguise_site_environment_print', 1000 );
add_action( 'login_footer', 'otherguise_site_environment_print', 1000 );

/**
 * Adds the label to the admin bar, which only the people who are logged in see: on production this is how the front end says it too.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 * @return void
 */
function otherguise_site_environment_admin_bar( $bar ) {
	$environment = otherguise_site_environment();

	$bar->add_node(
		array(
			'id'    => 'otherguise-site-environment',
			'title' => '<span style="background:' . esc_attr( $environment['color'] ) . ';color:#fff;padding:0 8px;border-radius:3px;text-transform:uppercase">' . esc_html( $environment['label'] ) . '</span>',
		)
	);
}
add_action( 'admin_bar_menu', 'otherguise_site_environment_admin_bar', 2 );
