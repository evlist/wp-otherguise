<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The setting that enables the modes.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Admin;

use Otherguise\Modes\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the option with the Settings API (which checks the nonce; the capability is the one of the screen) and prints the form at
 * the top of the screen: one checkbox, and a warning while the modes are disabled.
 */
final class SettingsPanel {
	/**
	 * Settings group.
	 */
	public const GROUP = 'modes_settings_group';

	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Builds the panel.
	 *
	 * @param Environment $environment Environment.
	 * @param Settings    $settings    Settings.
	 */
	public function __construct( Environment $environment, Settings $settings ) {
		$this->environment = $environment;
		$this->settings    = $settings;
	}

	/**
	 * Registers the option. Called on `admin_init`.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Returns the capability needed to save the settings of the group. Filter `option_page_capability_{group}`.
	 *
	 * @return string
	 */
	public function capability() {
		return $this->environment->capability();
	}

	/**
	 * Prints the form.
	 *
	 * @return void
	 */
	public function render() {
		$enabled = $this->settings->is_enabled();
		$name    = Settings::OPTION . '[' . Settings::ENABLED . ']';

		if ( ! $enabled ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The modes are disabled: the site ignores ?mode= and ?print, shows the normal templates and adds no mode class to the page. The variants below are kept.', 'modes' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( $this->environment->admin_url( 'options.php' ) ) . '">';
		$this->environment->print_settings_fields( self::GROUP );
		echo '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . ( $enabled ? ' checked="checked"' : '' ) . ' /> ' . esc_html__( 'Enable the modes on this site', 'modes' ) . '</label> ';
		echo '<input type="submit" class="button" value="' . esc_attr__( 'Save', 'modes' ) . '" /></p></form>';
	}
}
