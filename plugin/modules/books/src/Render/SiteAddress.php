<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The address of the site as the rendering server sees it.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Render;

defined( 'ABSPATH' ) || exit;

/**
 * The rendering server asks for the page of a post by its public address, which goes through the proxy of the site: TLS, and its time
 * limits (60 seconds is common), which a long page does not respect. When the server and the site are on the same network, an
 * administrator can give the address by which the server reaches the site directly, for example `http://wordpress` (slice 225): the
 * beginning of the public address (scheme, host, port) is replaced by it, nothing else. Only the page asked for goes that way: the
 * images, styles and scripts it contains keep the addresses that WordPress gives them.
 */
final class SiteAddress {
	/**
	 * Checks the address typed: an http or https address of a host, with nothing after it (no path, query, user or fragment).
	 *
	 * @param mixed $value Address typed.
	 * @return string|null The address without a trailing slash, or null when it is not valid.
	 */
	public static function normalize( $value ) {
		$address = ServerAddress::normalize( $value );

		if ( null === $address ) {
			return null;
		}

		$parts = wp_parse_url( $address );

		return is_array( $parts ) && ( ! isset( $parts['path'] ) || '' === $parts['path'] ) ? $address : null;
	}

	/**
	 * Replaces the beginning of an address of the site by the address of the rendering server's network. An address of another site, or
	 * an empty `$internal`, is returned as it is.
	 *
	 * @param string $url      Address asked for.
	 * @param string $public   Public address of the site (its home page).
	 * @param string $internal Address by which the server reaches the site, or an empty string.
	 * @return string
	 */
	public static function rewrite( $url, $public, $internal ) {
		$internal = self::normalize( $internal );
		$from     = self::origin( $public );

		if ( null === $internal || null === $from || self::origin( $url ) !== $from ) {
			return (string) $url;
		}

		return $internal . (string) substr( (string) $url, strlen( (string) preg_replace( '#^([a-z]+://[^/?\#]*).*$#i', '$1', (string) $url ) ) );
	}

	/**
	 * The host (and port) of an address, in lower case; null when it has none.
	 *
	 * @param mixed $value Address.
	 * @return string|null
	 */
	public static function host( $value ) {
		$parts = is_string( $value ) ? wp_parse_url( $value ) : false;

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || '' === $parts['host'] ) {
			return null;
		}

		return strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * The scheme, host and port of an address, in lower case; null when it has no host.
	 *
	 * @param mixed $value Address.
	 * @return string|null
	 */
	private static function origin( $value ) {
		$parts = is_string( $value ) ? wp_parse_url( $value ) : false;
		$host  = self::host( $value );

		if ( null === $host || ! isset( $parts['scheme'] ) ) {
			return null;
		}

		return strtolower( $parts['scheme'] ) . '://' . $host;
	}
}
