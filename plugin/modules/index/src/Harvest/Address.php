<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The address by which the site is asked for a page.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Harvest;

defined( 'ABSPATH' ) || exit;

/**
 * When the public address of the site does not resolve from the server (a proxy, a container), an administrator gives the address by which
 * the site reaches itself, for example `http://wordpress`: the beginning of the public address (scheme, host, port) is replaced by it,
 * nothing else.
 */
final class Address {
	/**
	 * Checks an address typed: an http or https address of a host with nothing after it.
	 *
	 * @param mixed $value Address.
	 * @return string The address without a trailing slash, or an empty string when it is not valid.
	 */
	public static function normalize( $value ) {
		$parts = is_string( $value ) ? wp_parse_url( trim( $value ) ) : false;

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		if ( ( isset( $parts['path'] ) && '/' !== $parts['path'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) ) {
			return '';
		}

		return strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * Replaces the beginning of an address of the site by the internal address. Another site's address, or an empty or invalid internal
	 * address, is returned as it is.
	 *
	 * @param string $url      The address asked for.
	 * @param string $public   The public address of the site (its home page).
	 * @param string $internal The internal address, or an empty string.
	 * @return string
	 */
	public static function rewrite( $url, $public, $internal ) {
		$internal = self::normalize( $internal );
		$from     = self::normalize( preg_replace( '#^([a-z]+://[^/?\#]*).*$#i', '$1', (string) $public ) );
		$origin   = self::normalize( preg_replace( '#^([a-z]+://[^/?\#]*).*$#i', '$1', (string) $url ) );

		if ( '' === $internal || '' === $from || $origin !== $from ) {
			return (string) $url;
		}

		return $internal . (string) substr( (string) $url, strlen( (string) preg_replace( '#^([a-z]+://[^/?\#]*).*$#i', '$1', (string) $url ) ) );
	}
}
