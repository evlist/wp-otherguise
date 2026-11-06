<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The screen of the spaces of the wiki.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Admin;

use Otherguise\Core\AdminMenu;
use Otherguise\Wiki\Name;
use Otherguise\Wiki\Space\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * **Otherguise → Wiki** (slice 500): the list of the spaces, with a row to add one. The form is posted to `options.php`, so the Settings
 * API checks the nonce and the capability (`manage_options`, changed by the filter `wiki_admin_capability`); the rows are cleaned by
 * `Spaces::sanitize()`.
 */
final class SettingsScreen {
	/**
	 * Slug of the page, under the Otherguise menu.
	 */
	public const PAGE = 'wiki';

	/**
	 * Settings group.
	 */
	public const GROUP = 'wiki_settings_group';

	/**
	 * Tells whether the current user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Tells whether the PHP extension `intl` is there.
	 *
	 * @var callable
	 */
	private $has_intl;

	/**
	 * Builds the screen.
	 *
	 * @param callable|null $can      Default `current_user_can`.
	 * @param callable|null $has_intl Default `Name::has_intl()`.
	 */
	public function __construct( $can = null, $has_intl = null ) {
		$this->can      = $can ?? 'current_user_can';
		$this->has_intl = $has_intl ?? array( Name::class, 'has_intl' );
	}

	/**
	 * Registers the callbacks.
	 *
	 * @param callable $add_action Adds an action or a filter.
	 * @return void
	 */
	public function register( $add_action ) {
		$add_action( 'admin_menu', array( $this, 'add_menu' ), 10, 0 );
		$add_action( AdminMenu::FILTER, array( $this, 'declare_screen' ), 10, 1 );
		$add_action( 'admin_init', array( $this, 'register_setting' ), 10, 0 );
		$add_action( 'option_page_capability_' . self::GROUP, array( $this, 'capability' ), 10, 0 );
	}

	/**
	 * Declares the screen to the overview of the Otherguise menu. Filter `otherguise_admin_screens`.
	 *
	 * @param mixed $screens The screens declared so far.
	 * @return array<int|string, mixed>
	 */
	public function declare_screen( $screens ) {
		$screens   = is_array( $screens ) ? $screens : array();
		$screens[] = array(
			'title'       => __( 'Wiki', 'otherguise' ),
			'description' => __( 'The spaces of the wiki: the sections of its addresses, such as Biography or Encyclopedia.', 'otherguise' ),
			'capability'  => $this->capability(),
			'page'        => self::PAGE,
			'group'       => 'settings',
			'order'       => 40,
		);

		return $screens;
	}

	/**
	 * The capability of the screen and of the saving. Filter `wiki_admin_capability`.
	 *
	 * @return string
	 */
	public function capability() {
		$capability = apply_filters( 'wiki_admin_capability', 'manage_options' );

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
			Spaces::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Spaces::class, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Adds the page to the Otherguise menu. Action `admin_menu`.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			AdminMenu::SLUG,
			__( 'Otherguise wiki', 'otherguise' ),
			__( 'Wiki', 'otherguise' ),
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

		$rows   = Spaces::all();
		$rows[] = array(
			'name'     => '',
			'label'    => '',
			'template' => '',
			'class'    => '',
			'status'   => '',
			'order'    => '',
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Otherguise wiki', 'otherguise' ) . '</h1>';
		// The screens of the Settings menu print the notices of the Settings API by themselves; this one is under the Otherguise menu.
		settings_errors();

		if ( ! (bool) ( $this->has_intl )() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The PHP extension intl is missing: the titles of the pages are not put in Unicode normal form, and the suggestions of similar titles keep the accents. Two titles that look the same may then be two names.', 'otherguise' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'A space is a section of the wiki: its name is the word before the colon in the address of a page (Biography:Paris).', 'otherguise' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="widefat striped" style="table-layout:fixed"><thead><tr>';

		foreach ( array(
			__( 'Name', 'otherguise' ),
			__( 'Label', 'otherguise' ),
			__( 'Template', 'otherguise' ),
			__( 'CSS class', 'otherguise' ),
			__( 'Status line', 'otherguise' ),
			__( 'Order', 'otherguise' ),
			__( 'Remove', 'otherguise' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( array_values( $rows ) as $index => $row ) {
			$this->render_row( $index, $row );
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'The name has letters, digits, hyphens and underscores only, and is unique whatever the case. A space that holds pages cannot be renamed or removed. The empty row at the end adds a space.', 'otherguise' ) . '</p>';
		echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Save', 'otherguise' ) . '" /></form></div>';
	}

	/**
	 * Prints a row of the table: the fields of one space, or the empty row.
	 *
	 * @param int                  $index Position of the row.
	 * @param array<string, mixed> $row   The record.
	 * @return void
	 */
	private function render_row( $index, array $row ) {
		$field = static function ( $key ) use ( $index ) {
			return Spaces::OPTION . '[' . $index . '][' . $key . ']';
		};

		echo '<tr>';
		echo '<td><input type="hidden" name="' . esc_attr( $field( 'was' ) ) . '" value="' . esc_attr( (string) $row['name'] ) . '" />';
		echo '<input type="text" style="width:100%" name="' . esc_attr( $field( 'name' ) ) . '" value="' . esc_attr( (string) $row['name'] ) . '" aria-label="' . esc_attr__( 'Name', 'otherguise' ) . '" /></td>';

		foreach ( array(
			'label'    => __( 'Label', 'otherguise' ),
			'template' => __( 'Template', 'otherguise' ),
			'class'    => __( 'CSS class', 'otherguise' ),
			'status'   => __( 'Status line', 'otherguise' ),
		) as $key => $label ) {
			echo '<td><input type="text" style="width:100%" name="' . esc_attr( $field( $key ) ) . '" value="' . esc_attr( (string) $row[ $key ] ) . '" aria-label="' . esc_attr( $label ) . '" /></td>';
		}

		echo '<td><input type="number" style="width:100%" name="' . esc_attr( $field( 'order' ) ) . '" value="' . esc_attr( (string) $row['order'] ) . '" aria-label="' . esc_attr__( 'Order', 'otherguise' ) . '" /></td>';
		echo '<td>';

		if ( '' !== $row['name'] ) {
			echo '<input type="checkbox" name="' . esc_attr( $field( 'remove' ) ) . '" value="1" aria-label="' . esc_attr__( 'Remove', 'otherguise' ) . '" />';
		}

		echo '</td></tr>';
	}
}
