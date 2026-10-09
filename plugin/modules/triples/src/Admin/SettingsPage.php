<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The settings of the module.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

use Otherguise\Triples\Storage\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * One setting: whether the data is deleted when the plugin is deleted. Saved through the Settings API, which checks the nonce; the
 * capability is the one of the screen.
 */
final class SettingsPage {
	/**
	 * Settings group.
	 */
	public const GROUP = 'triples_settings_group';

	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Builds the page.
	 *
	 * @param Environment $environment Environment.
	 */
	public function __construct( Environment $environment ) {
		$this->environment = $environment;
	}

	/**
	 * Registers the setting. Called on `admin_init`.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::GROUP,
			Uninstaller::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
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
	 * Sanitizes the option: the flag becomes a boolean, other keys already stored are kept.
	 *
	 * @param mixed $input Value posted.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$stored = get_option( Uninstaller::SETTINGS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$input  = is_array( $input ) ? $input : array();

		$stored[ Uninstaller::DELETE_FLAG ] = ! empty( $input[ Uninstaller::DELETE_FLAG ] );

		return $stored;
	}

	/**
	 * Prints the form.
	 *
	 * @return void
	 */
	public function render_form() {
		$settings = get_option( Uninstaller::SETTINGS_OPTION, array() );
		$checked  = is_array( $settings ) && ! empty( $settings[ Uninstaller::DELETE_FLAG ] );
		$name     = Uninstaller::SETTINGS_OPTION . '[' . Uninstaller::DELETE_FLAG . ']';

		echo '<form method="post" action="' . esc_url( $this->environment->admin_url( 'options.php' ) ) . '">';
		$this->print_settings_fields();
		echo '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . ( $checked ? ' checked="checked"' : '' ) . ' /> ' . esc_html__( 'Delete all the data of the statements when the plugin is deleted', 'triples' ) . '</label></p>';
		echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Save', 'triples' ) . '" /></form>';
	}

	/**
	 * Prints the hidden fields of the Settings API (option page, nonce, referer).
	 *
	 * @return void
	 */
	protected function print_settings_fields() {
		settings_fields( self::GROUP );
	}
}
