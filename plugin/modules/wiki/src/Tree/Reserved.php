<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The titles of the first level that something else would serve first.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * Tells whether an address of the first level is served by something else before the module (slice 503): a page with such a title
 * would exist and could never be shown. The test is made on the exact address:
 *
 * - a **specific rewrite rule** of WordPress answers it (the first rule that matches, as WordPress takes it, is not one of the three that
 *   produce `pagename`, `name` or `attachment`): `feed`, `category`, `author`, `search`, `wp-json`, `page`...;
 * - a **short list** of what the web server or WordPress serves with no rule (`wp-*`, `index.php`, `xmlrpc.php`, `robots.txt`...);
 * - **a file or a directory exists** at that place of the site.
 *
 * A title below the first level is never concerned. The rules are read from the option that WordPress keeps; with plain permalinks there are
 * none and only the other two tests apply.
 */
final class Reserved {
	/**
	 * What is served with no rewrite rule, compared without regard to the case.
	 */
	private const LIST = array( 'index.php', 'xmlrpc.php', 'robots.txt', 'favicon.ico', 'readme.html', 'license.txt', 'sitemap.xml' );

	/**
	 * Returns the rewrite rules (pattern => query).
	 *
	 * @var callable
	 */
	private $rules;

	/**
	 * Tells whether a file or a directory is at that place of the site.
	 *
	 * @var callable
	 */
	private $exists;

	/**
	 * Builds the test.
	 *
	 * @param callable|null $rules  Default: the option `rewrite_rules`.
	 * @param callable|null $exists Receives a name; default: it exists under `ABSPATH`.
	 */
	public function __construct( $rules = null, $exists = null ) {
		$this->rules  = $rules ?? static function () {
			$rules = get_option( 'rewrite_rules' );

			return is_array( $rules ) ? $rules : array();
		};
		$this->exists = $exists ?? static function ( $name ) {
			return file_exists( ABSPATH . $name );
		};
	}

	/**
	 * Tells whether a title of the first level is served first by something else.
	 *
	 * @param string $title The title, after the rules of the titles.
	 * @return bool
	 */
	public function __invoke( $title ) {
		$path = ltrim( Address::path( array( $title ) ), '/' );

		if ( 0 === stripos( $path, 'wp-' ) || in_array( strtolower( $path ), self::LIST, true ) ) {
			return true;
		}

		if ( (bool) ( $this->exists )( $path ) ) {
			return true;
		}

		foreach ( (array) ( $this->rules )() as $pattern => $query ) {
			if ( 1 === preg_match( '#^' . $pattern . '#', $path ) || 1 === preg_match( '#^' . $pattern . '#', $title ) ) {
				return 1 !== preg_match( '/^index\.php\?(pagename|name|attachment)=/', (string) $query );
			}
		}

		return false;
	}
}
