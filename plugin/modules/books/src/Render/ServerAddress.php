<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The address of a rendering server.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Checks and normalises the address of the rendering server (slice 220): an `http` or `https` address with a host, no user or password,
 * no query and no fragment; a path is allowed (a server behind a reverse proxy) and the trailing slash is removed. The address is set by
 * an administrator and is often internal on purpose (a Docker container name, a private IP address).
 */
final class ServerAddress {
	/**
	 * Longest address accepted.
	 */
	public const MAX_LENGTH = 300;

	/**
	 * Returns the normalised address, or null when it is not valid.
	 *
	 * @param mixed $value Address typed.
	 * @return string|null
	 */
	public static function normalize( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( '' === $value || strlen( $value ) > self::MAX_LENGTH || 1 === preg_match( '/[\x00-\x20\x7F"<>\\\\^`{|}]/', $value ) ) {
			return null;
		}

		$parts = wp_parse_url( $value );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || '' === $parts['host'] ) {
			return null;
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}

		if ( false !== strpos( $value, '?' ) || false !== strpos( $value, '#' ) || false !== strpos( $value, '@' ) ) {
			return null;
		}

		return rtrim( $value, '/' );
	}
}
