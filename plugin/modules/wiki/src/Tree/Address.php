<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The address of a page of the tree.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * The address of a page is **the exact titles of its ancestors and of the page** (slice 503): `/Biography/Sant_Llorenç_de_Morunys`. The
 * underscore stands for the space, every other character is percent-encoded in UTF-8 in **upper case** but for a few that are valid in a
 * path and that a reader expects to see (`: ( ) , ' ! * @`). No trailing slash, no prefix.
 */
final class Address {
	/**
	 * What `rawurlencode()` writes and that stays readable in an address.
	 */
	private const READABLE = array(
		'%3A' => ':',
		'%28' => '(',
		'%29' => ')',
		'%2C' => ',',
		'%27' => "'",
		'%21' => '!',
		'%2A' => '*',
		'%40' => '@',
	);

	/**
	 * The path of a page, with a leading slash.
	 *
	 * @param string[] $titles The titles from the first level to the page.
	 * @return string
	 */
	public static function path( array $titles ) {
		$segments = array_map(
			static function ( $title ) {
				return strtr( rawurlencode( str_replace( ' ', '_', (string) $title ) ), self::READABLE );
			},
			array_values( $titles )
		);

		return '/' . implode( '/', $segments );
	}

	/**
	 * The titles asked for by a path, decoded. WordPress hands the path **as the server received it**: a server that gives `PATH_INFO`
	 * gives it decoded and nginx does not. A title never holds a percent sign (slice 501), so a `%` followed by two digits is always an
	 * encoding to undo, and a segment that is already decoded is left as it is.
	 *
	 * @param string $path The path, with or without the slashes at the ends.
	 * @return string[]|null Null when the path is empty or has an empty segment (`//`).
	 */
	public static function titles( $path ) {
		$path = trim( (string) $path, '/' );

		if ( '' === $path ) {
			return null;
		}

		$titles = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment ) {
				return null;
			}

			$titles[] = 1 === preg_match( '/%[0-9A-Fa-f]{2}/', $segment ) ? rawurldecode( $segment ) : $segment;
		}

		return $titles;
	}
}
