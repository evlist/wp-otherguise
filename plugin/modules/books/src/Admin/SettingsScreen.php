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

use Otherguise\Books\Render\GotenbergClient;
use Otherguise\Books\Settings\PageFormat;
use Otherguise\Books\Settings\RendererSettings;

defined( 'ABSPATH' ) || exit;

/**
 * **Settings → Otherguise → Books**: the format of the page (width, height, margins, in millimetres). The form is posted to `options.php`,
 * so the Settings API checks the nonce and the capability (`manage_options`, changed by the filter `books_admin_capability`).
 */
final class SettingsScreen {
	/**
	 * Slug of the page, under Settings, reached from the hub.
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
	 * Settings of the rendering server.
	 *
	 * @var RendererSettings
	 */
	private $renderer;

	/**
	 * Tells whether the current user may use the screen: receives the capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Checks the rendering server: receives its address, returns `up` and `message`.
	 *
	 * @var callable
	 */
	private $health;

	/**
	 * Builds the screen.
	 *
	 * @param PageFormat       $format   Format of the page.
	 * @param RendererSettings $renderer Settings of the rendering server.
	 * @param callable|null    $can      Receives a capability and tells whether the current user has it; defaults to `current_user_can`.
	 * @param callable|null    $health   Receives the address of the server and returns `up` and `message`; defaults to a check of Gotenberg.
	 */
	public function __construct( PageFormat $format, RendererSettings $renderer, $can = null, $health = null ) {
		$this->format   = $format;
		$this->renderer = $renderer;
		$this->can      = $can ?? 'current_user_can';
		$this->health   = $health ?? static function ( $address ) {
			return ( new GotenbergClient( $address ) )->health();
		};
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

		register_setting(
			self::GROUP,
			RendererSettings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->renderer, 'sanitize' ),
				'default'           => RendererSettings::defaults(),
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
			__( 'Otherguise books', 'otherguise' ),
			__( 'Otherguise books', 'otherguise' ),
			$this->capability(),
			self::PAGE,
			array( $this, 'render' )
		);
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
			'title'       => __( 'Books', 'otherguise' ),
			'description' => __( 'The format of the page of a book and the PDF server.', 'otherguise' ),
			'capability'  => $this->capability(),
			'page'        => self::PAGE,
			'order'       => 20,
		);

		return $screens;
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
		$this->render_renderer_section();
		echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Save', 'otherguise' ) . '" /></form></div>';
	}

	/**
	 * Prints the section of the rendering server: the address, how the conversion waits, the media, how long the files are kept, and, when
	 * a server is set, whether it answers.
	 *
	 * @return void
	 */
	private function render_renderer_section() {
		$values = $this->renderer->get();
		$name   = static function ( $key ) {
			return RendererSettings::OPTION . '[' . $key . ']';
		};

		echo '<h2>' . esc_html__( 'PDF server', 'otherguise' ) . '</h2>';
		echo '<p>' . esc_html__( 'The address of a Gotenberg server (version 8), for example http://gotenberg:3000 on the Docker network of the site. Without an address there is no PDF preview. WordPress calls the server, not the browser; the server must be able to reach the pages of this site.', 'otherguise' ) . '</p>';

		if ( '' !== $values[ RendererSettings::URL ] ) {
			$health = ( $this->health )( $values[ RendererSettings::URL ] );

			if ( ! empty( $health['up'] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The server answers.', 'otherguise' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The server does not answer: ', 'otherguise' ) . esc_html( (string) ( $health['message'] ?? '' ) ) . '</p></div>';
			}
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		$rows = array(
			RendererSettings::URL             => array( __( 'Address of the server', 'otherguise' ), 'url', 'regular-text', '' ),
			RendererSettings::SITE            => array( __( 'Address of this site for the server', 'otherguise' ), 'url', 'regular-text', __( 'Optional: the address by which the PDF server reaches this site, for example http://wordpress on the same Docker network. The beginning of the public address of the page is replaced by it, which avoids the proxy and its time limits; the images and styles keep their addresses. Leave empty to use the public address.', 'otherguise' ) ),
			RendererSettings::WAIT_DELAY      => array( __( 'Wait (seconds)', 'otherguise' ), 'number', 'small-text', __( 'A fixed wait before the page is converted. Gotenberg waits for nothing by default: 0 is allowed.', 'otherguise' ) ),
			RendererSettings::WAIT_EXPRESSION => array( __( 'Wait until (JavaScript)', 'otherguise' ), 'text', 'large-text', __( 'Optional: a JavaScript expression that the page makes true when it is ready, for example window.status === "ready".', 'otherguise' ) ),
			RendererSettings::WAIT_SELECTOR   => array( __( 'Wait for (CSS selector)', 'otherguise' ), 'text', 'regular-text', __( 'Optional: a CSS selector of an element that appears when the page is ready.', 'otherguise' ) ),
			RendererSettings::KEEP_DAYS       => array( __( 'Keep the PDF files (days)', 'otherguise' ), 'number', 'small-text', __( 'The files are deleted after this number of days, between 1 and 90.', 'otherguise' ) ),
		);

		foreach ( $rows as $key => $row ) {
			$id = 'books-' . $key;

			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $row[0] ) . '</label></th><td>';
			echo '<input type="' . esc_attr( $row[1] ) . '"' . ( 'number' === $row[1] ? ' step="' . ( RendererSettings::WAIT_DELAY === $key ? '0.1' : '1' ) . '" min="0"' : '' ) . ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name( $key ) ) . '" value="' . esc_attr( (string) $values[ $key ] ) . '" class="' . esc_attr( $row[2] ) . '" />';

			if ( '' !== $row[3] ) {
				echo '<p class="description">' . esc_html( $row[3] ) . '</p>';
			}

			echo '</td></tr>';
		}

		echo '<tr><th scope="row"><label for="books-media">' . esc_html__( 'Media', 'otherguise' ) . '</label></th><td><select id="books-media" name="' . esc_attr( $name( RendererSettings::MEDIA ) ) . '">';

		foreach ( array(
			'print'  => __( 'print (the default of Gotenberg)', 'otherguise' ),
			'screen' => __( 'screen (what the preview panel measures)', 'otherguise' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . ( $value === $values[ RendererSettings::MEDIA ] ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select></td></tr></tbody></table>';
	}
}
