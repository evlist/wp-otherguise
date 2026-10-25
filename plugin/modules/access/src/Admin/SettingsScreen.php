<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The settings screen of the Access module.
 *
 * @package Otherguise
 */

namespace Otherguise\Access\Admin;

use Otherguise\Access\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * **Settings → Otherguise access** (slice 401). The form is posted to `options.php`, so the Settings API checks the nonce and the
 * capability (`manage_options`, changed by the filter `access_admin_capability`).
 */
final class SettingsScreen {
	/**
	 * Slug of the page, under Settings.
	 */
	public const PAGE = 'access';

	/**
	 * Settings group.
	 */
	public const GROUP = 'access_settings_group';

	/**
	 * The settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Tells whether the current user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Builds the screen.
	 *
	 * @param Settings      $settings The settings.
	 * @param callable|null $can      Default `current_user_can`.
	 */
	public function __construct( Settings $settings, $can = null ) {
		$this->settings = $settings;
		$this->can      = $can ?? 'current_user_can';
	}

	/**
	 * Registers the callbacks.
	 *
	 * @param callable $add_action Adds an action or a filter.
	 * @return void
	 */
	public function register( $add_action ) {
		$add_action( 'admin_menu', array( $this, 'add_menu' ), 10, 0 );
		$add_action( 'admin_init', array( $this, 'register_setting' ), 10, 0 );
		$add_action( 'option_page_capability_' . self::GROUP, array( $this, 'capability' ), 10, 0 );
	}

	/**
	 * The capability of the screen and of the saving. Filter `access_admin_capability`.
	 *
	 * @return string
	 */
	public function capability() {
		$capability = apply_filters( 'access_admin_capability', 'manage_options' );

		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Registers the option. Action `admin_init`.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Adds the page to the Settings menu. Action `admin_menu`.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Otherguise access', 'otherguise' ),
			__( 'Otherguise access', 'otherguise' ),
			$this->capability(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! (bool) ( $this->can )( $this->capability() ) ) {
			return;
		}

		$values = $this->settings->get();
		$name   = static function ( $key ) {
			return Settings::OPTION . '[' . $key . ']';
		};

		echo '<div class="wrap"><h1>' . esc_html__( 'Otherguise access', 'otherguise' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Private links', 'otherguise' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $name( Settings::LINKS ) ) . '" value="1"' . ( ! empty( $values[ Settings::LINKS ] ) ? ' checked="checked"' : '' ) . ' /> ' . esc_html__( 'Allow a post to be seen through a link with a secret token', 'otherguise' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'A link shows a post that is not published (draft, pending, private, scheduled) to anybody who has it, read only, until it is revoked. Switched off, the link is not answered, the panel of the editor and the REST routes are gone; the tokens already made are kept and work again if the setting is switched back on.', 'otherguise' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="access-capability">' . esc_html__( 'Who may make a link', 'otherguise' ) . '</label></th><td><select id="access-capability" name="' . esc_attr( $name( Settings::CAPABILITY ) ) . '">';

		foreach ( array(
			'edit_post'         => __( 'Whoever may edit the post', 'otherguise' ),
			'edit_others_posts' => __( 'Editors and administrators', 'otherguise' ),
			'manage_options'    => __( 'Administrators only', 'otherguise' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . ( $value === $values[ Settings::CAPABILITY ] ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Unlisted posts', 'otherguise' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $name( Settings::UNLISTED ) ) . '" value="1"' . ( ! empty( $values[ Settings::UNLISTED ] ) ? ' checked="checked"' : '' ) . ' /> ' . esc_html__( 'Allow a published post to be hidden from the lists of the site', 'otherguise' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Not available yet: this setting is kept for a later version.', 'otherguise' ) . '</p></td></tr>';

		echo '</tbody></table>';
		echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Save', 'otherguise' ) . '" /></form></div>';
	}
}
