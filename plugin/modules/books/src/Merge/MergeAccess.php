<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Who may have a section printed with all its units.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

defined( 'ABSPATH' ) || exit;

/**
 * Merging a section renders every one of its units in one request: it is heavy, and nobody may ask for it by an address (slice 231). It is
 * allowed to a user who can edit the section, and to the PDF job, which has no user: the job adds to the address a **token** that holds the
 * id of the section and an expiry, signed with a key of the site (`wp_salt()`), so that it works for that section only and for an hour.
 */
final class MergeAccess {
	/**
	 * Name of the query argument that carries the token.
	 */
	public const ARGUMENT = 'books_merge_token';

	/**
	 * How long a token is valid, in seconds.
	 */
	public const LIFETIME = 3600;

	/**
	 * Returns the time.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Returns the secret key.
	 *
	 * @var callable
	 */
	private $secret;

	/**
	 * Receives a section id; tells whether the current user may edit it.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * Returns the token of the address, an empty string when there is none.
	 *
	 * @var callable
	 */
	private $presented;

	/**
	 * Builds the check.
	 *
	 * @param callable|null $now       Defaults to `time`.
	 * @param callable|null $secret    Defaults to `wp_salt( 'auth' )`.
	 * @param callable|null $can_edit  Defaults to `current_user_can( 'edit_post', $id )`.
	 * @param callable|null $presented Defaults to a read of `books_merge_token` in the query string.
	 */
	public function __construct( $now = null, $secret = null, $can_edit = null, $presented = null ) {
		$this->now       = $now ?? 'time';
		$this->secret    = $secret ?? static function () {
			return wp_salt( 'auth' );
		};
		$this->can_edit  = $can_edit ?? static function ( $section ) {
			return current_user_can( 'edit_post', (int) $section );
		};
		$this->presented = $presented ?? static function () {
			$value = filter_input( INPUT_GET, self::ARGUMENT, FILTER_UNSAFE_RAW );

			return is_string( $value ) ? $value : '';
		};
	}

	/**
	 * A token for a section, for an hour.
	 *
	 * @param int $section Id of the section.
	 * @return string
	 */
	public function token( $section ) {
		$expires = (int) ( $this->now )() + self::LIFETIME;

		return $expires . '.' . $this->signature( (int) $section, $expires );
	}

	/**
	 * Tells whether a token is valid for a section: well formed, not expired, signed for that section.
	 *
	 * @param int    $section Id of the section.
	 * @param string $token   The token.
	 * @return bool
	 */
	public function valid( $section, $token ) {
		if ( 1 !== preg_match( '/^([0-9]{1,12})\.([0-9a-f]{64})$/', (string) $token, $parts ) ) {
			return false;
		}

		return (int) $parts[1] >= (int) ( $this->now )() && hash_equals( $this->signature( (int) $section, (int) $parts[1] ), $parts[2] );
	}

	/**
	 * Tells whether the request may have the section merged: the user can edit it, or the address carries a valid token for it.
	 *
	 * @param int $section Id of the section.
	 * @return bool
	 */
	public function allowed( $section ) {
		return $section > 0 && ( ( $this->can_edit )( (int) $section ) || $this->valid( (int) $section, ( $this->presented )() ) );
	}

	/**
	 * The signature of a section and an expiry.
	 *
	 * @param int $section Id of the section.
	 * @param int $expires Expiry.
	 * @return string
	 */
	private function signature( $section, $expires ) {
		return hash_hmac( 'sha256', $section . '|' . $expires, (string) ( $this->secret )() );
	}
}
