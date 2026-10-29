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
use Otherguise\Core\AdminHub;

defined( 'ABSPATH' ) || exit;

/**
 * **Settings → Otherguise → Access** (slice 401). The form is posted to `options.php`, so the Settings API checks the nonce and the
 * capability (`manage_options`, changed by the filter `access_admin_capability`).
 */
final class SettingsScreen {
	/**
	 * Slug of the page, under Settings, reached from the hub.
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
		$add_action( AdminHub::FILTER, array( $this, 'declare_screen' ), 10, 1 );
		$add_action( 'admin_init', array( $this, 'register_setting' ), 10, 0 );
		$add_action( 'option_page_capability_' . self::GROUP, array( $this, 'capability' ), 10, 0 );
	}

	/**
	 * Declares the screen to the hub "Otherguise" of the Settings menu. Filter `otherguise_admin_screens`.
	 *
	 * @param mixed $screens The screens declared so far.
	 * @return array<int|string, mixed>
	 */
	public function declare_screen( $screens ) {
		$screens   = is_array( $screens ) ? $screens : array();
		$screens[] = array(
			'title'       => __( 'Access', 'otherguise' ),
			'description' => __( 'Private links to posts that are not published, and posts that are published but unlisted.', 'otherguise' ),
			'capability'  => $this->capability(),
			'page'        => self::PAGE,
			'order'       => 30,
		);

		return $screens;
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
	 * Adds the page to the Settings menu, which the hub "Otherguise" then takes out of the menu (it leads to it). Action `admin_menu`.
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
		echo '<p class="description">' . esc_html__( 'An unlisted post keeps its address but is left out of the home page, the archives, the search, the feeds, the sitemaps and the previous and next links. Switched off, the posts already unlisted are back in the lists; their state is kept.', 'otherguise' ) . '</p></td></tr>';

		echo '</tbody></table>';
		echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Save', 'otherguise' ) . '" /></form></div>';
	}
}
