<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The address of a wiki page.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Address;

defined( 'ABSPATH' ) || exit;

/**
 * The address of a page is its space and its exact title (slice 503): `Biography:Sant_Llorenç_de_Morunys`, `Biography:1958-1968/fr`, the form
 * of MediaWiki. The title is the one of the name (slice 501) with **the underscore for the space** and the slash kept; every other
 * character is percent-encoded in UTF-8, in **upper case** (`rawurlencode`), but for a few that are valid in a path and that a reader
 * expects to see (`: ( ) , ' ! * @`). There is no trailing slash. The first colon of the path separates the space; the others belong to the
 * title.
 */
final class Address {
	/**
	 * What `rawurlencode()` writes and that stays readable in an address: the colon, the parentheses, the comma, the apostrophe, the
	 * exclamation mark, the star and the at sign are valid in a segment of a path.
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
	 * The path of a page, without a leading slash.
	 *
	 * @param string $space The space, as registered.
	 * @param string $title The title of the name.
	 * @return string
	 */
	public static function path( $space, $title ) {
		$segments = array_map(
			static function ( $segment ) {
				return strtr( rawurlencode( $segment ), self::READABLE );
			},
			explode( '/', str_replace( ' ', '_', $title ) )
		);

		return rawurlencode( $space ) . ':' . implode( '/', $segments );
	}
}
