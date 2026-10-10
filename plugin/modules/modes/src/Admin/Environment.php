<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The administration screen's contact with WordPress.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * What the screen asks of the request and of the session: capability, nonces, input, redirections, links. The screen and the handlers
 * call only this class for it, so the tests replace it. Not tried on a real site by the unit tests.
 */
class Environment {
	/**
	 * Slug of the page, under Tools.
	 */
	public const PAGE = 'modes';

	/**
	 * Tells whether the current user may use the screen.
	 *
	 * The capability is `edit_theme_options` (the one of the site editor, since the screen is about templates), changed by the filter
	 * `modes_admin_capability`.
	 *
	 * @return bool
	 */
	public function can() {
		return current_user_can( $this->capability() );
	}

	/**
	 * Returns the capability the screen asks for.
	 *
	 * @return string
	 */
	public function capability() {
		$capability = apply_filters( 'modes_admin_capability', 'edit_theme_options' );

		return is_string( $capability ) && '' !== $capability ? $capability : 'edit_theme_options';
	}

	/**
	 * Tells whether a nonce is valid.
	 *
	 * @param string $value  Nonce received.
	 * @param string $action Action it was made for.
	 * @return bool
	 */
	public function verify_nonce( $value, $action ) {
		return false !== wp_verify_nonce( $value, $action );
	}

	/**
	 * Prints the hidden nonce field of a form.
	 *
	 * @param string $action Action.
	 * @return void
	 */
	public function print_nonce_field( $action ) {
		wp_nonce_field( $action );
	}

	/**
	 * Prints the hidden fields of the Settings API (option page, nonce, referer) for a settings group.
	 *
	 * @param string $group Group.
	 * @return void
	 */
	public function print_settings_fields( $group ) {
		settings_fields( $group );
	}

	/**
	 * Returns an URL of the administration.
	 *
	 * @param string $path Path relative to the admin directory.
	 * @return string
	 */
	public function admin_url( $path ) {
		return admin_url( $path );
	}

	/**
	 * Returns the URL of the screen.
	 *
	 * @param array<string, scalar> $args Query string arguments.
	 * @return string
	 */
	public function page_url( array $args = array() ) {
		return $this->admin_url( 'tools.php?' . http_build_query( array( 'page' => self::PAGE ) + $args ) );
	}

	/**
	 * Returns an URL of the site with a query string: the home page in a mode, for instance.
	 *
	 * @param array<string, scalar> $args Query string arguments.
	 * @return string
	 */
	public function front_url( array $args = array() ) {
		return home_url( '/' ) . ( array() === $args ? '' : '?' . http_build_query( $args ) );
	}

	/**
	 * Redirects inside the site and ends the request.
	 *
	 * @param string $url URL.
	 * @return void
	 */
	public function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Ends the request with a refusal.
	 *
	 * @return void
	 */
	public function deny() {
		wp_die( esc_html__( 'You are not allowed to do this.', 'otherguise' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Returns the query string: only the keys the screen uses, as received.
	 *
	 * @return array<string, string>
	 */
	public function query() {
		return $this->input( INPUT_GET );
	}

	/**
	 * Returns the posted form: only the keys the screen uses.
	 *
	 * @return array<string, string>
	 */
	public function form() {
		return $this->input( INPUT_POST );
	}

	/**
	 * Reads the keys the screen uses from the request, without touching the superglobals.
	 *
	 * @param int $source INPUT_GET or INPUT_POST.
	 * @return array<string, string>
	 */
	private function input( $source ) {
		$values = filter_input_array(
			$source,
			array(
				'modes_notice' => FILTER_UNSAFE_RAW,
				'code'         => FILTER_UNSAFE_RAW,
				'kind'         => FILTER_UNSAFE_RAW,
				'source'       => FILTER_UNSAFE_RAW,
				'variant'      => FILTER_UNSAFE_RAW,
				'mode'         => FILTER_UNSAFE_RAW,
				'_wpnonce'     => FILTER_UNSAFE_RAW,
			),
			false
		);

		return is_array( $values ) ? array_filter( $values, 'is_string' ) : array();
	}
}
