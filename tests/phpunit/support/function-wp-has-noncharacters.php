<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A stand-in for the function of WordPress that its tag processor calls, for the tests that load the processor without WordPress.
 *
 * @package Otherguise
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- stand-ins for functions of WordPress.

/**
 * Whether a string holds a Unicode non-character (the tests use none).
 *
 * @param string $text The text.
 * @return bool
 */
function wp_has_noncharacters( $text ) {
	return 1 === preg_match( '/[\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}]/u', (string) $text );
}

if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
	/**
	 * The attributes that hold an address (the list of WordPress, shortened to what the tests meet).
	 *
	 * @return string[]
	 */
	function wp_kses_uri_attributes() {
		return array( 'href', 'src', 'action', 'cite', 'poster', 'xlink:href' );
	}
}
