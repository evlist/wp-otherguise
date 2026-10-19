<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The settings screen of the Books module.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Admin;

use Otherguise\Books\Settings\PageFormat;

defined( 'ABSPATH' ) || exit;

/**
 * **Settings → Otherguise books**: the format of the page (width, height, margins, in millimetres). The form is posted to `options.php`,
 * so the Settings API checks the nonce and the capability (`manage_options`, changed by the filter `books_admin_capability`).
 */
final class SettingsScreen {
	/**
	 * Slug of the page, under Settings.
	 */
	public const PAGE = 'books';

	/**
	 * Settings group.
	 */
	public const GROUP = 'books_settings_group';

	/**
	 * Format of the page.
	 *
	 * @var PageFormat
	 */
	private $format;

	/**
	 * Tells whether the current user may use the screen: receives the capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Builds the screen.
	 *
	 * @param PageFormat    $format Format of the page.
	 * @param callable|null $can    Receives a capability and tells whether the current user has it; defaults to `current_user_can`.
	 */
	public function __construct( PageFormat $format, $can = null ) {
		$this->format = $format;
		$this->can    = $can ?? 'current_user_can';
	}

	/**
	 * Returns the capability the screen asks for.
	 *
	 * @return string
	 */
	public function capability() {
		$capability = apply_filters( 'books_admin_capability', 'manage_options' );

		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Registers the option with the Settings API. Action `admin_init`.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::GROUP,
			PageFormat::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->format, 'sanitize' ),
				'default'           => PageFormat::defaults(),
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
			__( 'Otherguise books', 'otherguise' ),
			__( 'Otherguise books', 'otherguise' ),
			$this->capability(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Returns the capability the Settings API asks for when the form is saved. Filter `option_page_capability_{group}`.
	 *
	 * @return string
	 */
	public function save_capability() {
		return $this->capability();
	}

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! ( $this->can )( $this->capability() ) ) {
			return;
		}

		$values = $this->format->get();

		echo '<div class="wrap"><h1>' . esc_html__( 'Otherguise books', 'otherguise' ) . '</h1>';
		echo '<p>' . esc_html__( 'The format of the page of a book, in millimetres. For now the preview of the editor uses it; the print stylesheet of the theme does not read it yet.', 'otherguise' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';

		$fields = array(
			PageFormat::WIDTH  => __( 'Width of the page (mm)', 'otherguise' ),
			PageFormat::HEIGHT => __( 'Height of the page (mm)', 'otherguise' ),
			PageFormat::MARGIN => __( 'Margins (mm)', 'otherguise' ),
		);

		foreach ( $fields as $key => $label ) {
			$id = 'books-' . $key;

			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="number" step="0.1" min="0" id="' . esc_attr( $id ) . '" name="' . esc_attr( PageFormat::OPTION . '[' . $key . ']' ) . '" value="' . esc_attr( (string) $values[ $key ] ) . '" class="small-text" />';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Save', 'otherguise' ) . '" /></form></div>';
	}
}
