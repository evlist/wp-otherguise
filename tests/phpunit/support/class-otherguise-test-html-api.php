<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Loads the tag processor of WordPress for the tests that read HTML.
 *
 * @package Otherguise
 */

/**
 * The tag processor and what it needs, taken from the core named by `OTHERGUISE_WP_CORE` (the `wp-includes` directory).
 */
final class Otherguise_Test_Html_Api {
	/**
	 * Loads the classes when they are not there.
	 *
	 * @return bool Whether the tag processor is available.
	 */
	public static function load() {
		$core = getenv( 'OTHERGUISE_WP_CORE' );

		if ( ! is_string( $core ) || ! is_file( $core . '/html-api/class-wp-html-tag-processor.php' ) || ! is_file( $core . '/class-wp-token-map.php' ) ) {
			return class_exists( 'WP_HTML_Tag_Processor' );
		}

		// Other tests load the processor without the table of the named references that decoding an attribute needs.
		require_once $core . '/class-wp-token-map.php';
		require_once $core . '/html-api/html5-named-character-references.php';

		foreach ( array( 'class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $file ) {
			require_once $core . '/html-api/' . $file . '.php';
		}

		require_once __DIR__ . '/function-wp-has-noncharacters.php';

		return true;
	}
}
