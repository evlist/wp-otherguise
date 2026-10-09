<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The administration screen's contact with WordPress.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the screen asks of the request and of the session: capability, nonces, the input, redirections, dates and links.
 * The screens and handlers call only this class for it, so the tests replace it. Not tried on a real WordPress site.
 */
class Environment {
	/**
	 * Slug of the page, under Tools.
	 */
	public const PAGE = 'triples';

	/**
	 * Option and screen option names.
	 */
	public const PER_PAGE_OPTION = 'triples_per_page';

	/**
	 * Tells whether the current user may use the screen.
	 *
	 * The capability is `manage_options`, changed by the filter `triples_admin_capability`.
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
		$capability = apply_filters( 'triples_admin_capability', 'manage_options' );

		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
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
		wp_die( esc_html__( 'You are not allowed to do this.', 'triples' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Formats a date of the database (GMT) in the time zone and the format of the site.
	 *
	 * @param string|null $gmt Date as `Y-m-d H:i:s`.
	 * @return string
	 */
	public function format_date( $gmt ) {
		$time = null === $gmt ? false : strtotime( $gmt . ' UTC' );

		return false === $time ? '' : wp_date( 'Y-m-d H:i', $time );
	}

	/**
	 * Returns the number of statements per page chosen by the user.
	 *
	 * @return int
	 */
	public function per_page() {
		$value = get_user_option( self::PER_PAGE_OPTION );

		return is_numeric( $value ) && (int) $value > 0 ? min( 200, (int) $value ) : 20;
	}

	/**
	 * Returns the query string: only the keys the screen uses, as received (text, or lists of text for `statement`).
	 *
	 * @return array<string, string|array<int, string>>
	 */
	public function query() {
		return $this->input( INPUT_GET );
	}

	/**
	 * Returns the posted form: only the keys the screen uses.
	 *
	 * @return array<string, string|array<int, string>>
	 */
	public function form() {
		return $this->input( INPUT_POST );
	}

	/**
	 * Reads the keys the screen uses from the request, without touching the superglobals.
	 *
	 * @param int $source INPUT_GET or INPUT_POST.
	 * @return array<string, string|array<int, string>>
	 */
	private function input( $source ) {
		$definitions = array(
			'tab'             => FILTER_UNSAFE_RAW,
			'action'          => FILTER_UNSAFE_RAW,
			'action2'         => FILTER_UNSAFE_RAW,
			'predicate'       => FILTER_UNSAFE_RAW,
			'entity_type'     => FILTER_UNSAFE_RAW,
			'entity'          => FILTER_UNSAFE_RAW,
			'with_qualifiers' => FILTER_UNSAFE_RAW,
			'orphans'         => FILTER_UNSAFE_RAW,
			'orderby'         => FILTER_UNSAFE_RAW,
			'order'           => FILTER_UNSAFE_RAW,
			'paged'           => FILTER_UNSAFE_RAW,
			'after'           => FILTER_UNSAFE_RAW,
			'triples_notice'  => FILTER_UNSAFE_RAW,
			'n'               => FILTER_UNSAFE_RAW,
			'_wpnonce'        => FILTER_UNSAFE_RAW,
			'statement'       => array(
				'filter' => FILTER_UNSAFE_RAW,
				'flags'  => FILTER_REQUIRE_ARRAY,
			),
		);

		$values = filter_input_array( $source, $definitions, false );

		return is_array( $values ) ? array_filter( $values, static fn( $value ) => null !== $value && false !== $value ) : array();
	}
}
