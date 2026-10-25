<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Settings of the Access module.
 *
 * @package Otherguise
 */

namespace Otherguise\Access\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The option `access_settings` (slice 401): whether the private links are on, who may make one, whether the unlisted state is on. A site
 * that never saved the screen has the defaults: everything on, who may edit a post may share it. Each answer goes through a filter, so
 * that another plugin or a must-use plugin can force it.
 */
final class Settings {
	/**
	 * Name of the option.
	 */
	public const OPTION = 'access_settings';

	/**
	 * Key: the private links are on.
	 */
	public const LINKS = 'links';

	/**
	 * Key: the capability asked, besides editing the post, to make a link.
	 */
	public const CAPABILITY = 'capability';

	/**
	 * Key: the unlisted state is on (slice 402).
	 */
	public const UNLISTED = 'unlisted';

	/**
	 * The capabilities that can be asked, from the least to the most strict. `edit_post` is the one of the post itself.
	 */
	public const CAPABILITIES = array( 'edit_post', 'edit_others_posts', 'manage_options' );

	/**
	 * Tells whether a capability is granted: `current_user_can`.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Builds the settings.
	 *
	 * @param callable|null $can Receives a capability and optionally a post id; default `current_user_can`.
	 */
	public function __construct( $can = null ) {
		$this->can = $can ?? 'current_user_can';
	}

	/**
	 * The values stored, completed by the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			self::LINKS      => true,
			self::CAPABILITY => 'edit_post',
			self::UNLISTED   => true,
		);
	}

	/**
	 * Returns the values, the defaults for what is not stored.
	 *
	 * @return array<string, mixed>
	 */
	public function get() {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? array_intersect_key( $stored, self::defaults() ) : array() );
	}

	/**
	 * Tells whether the private links are on. Filter `access_links_enabled`.
	 *
	 * @return bool
	 */
	public function links_enabled() {
		return (bool) apply_filters( 'access_links_enabled', ! empty( $this->get()[ self::LINKS ] ) );
	}

	/**
	 * Tells whether the unlisted state is on. Filter `access_unlisted_enabled`.
	 *
	 * @return bool
	 */
	public function unlisted_enabled() {
		return (bool) apply_filters( 'access_unlisted_enabled', ! empty( $this->get()[ self::UNLISTED ] ) );
	}

	/**
	 * The capability asked to make a link. Filter `access_link_capability`; a value that is not one of the three is ignored.
	 *
	 * @return string
	 */
	public function capability() {
		$value = $this->get()[ self::CAPABILITY ];
		$value = apply_filters( 'access_link_capability', is_string( $value ) ? $value : 'edit_post' );

		return in_array( $value, self::CAPABILITIES, true ) ? $value : 'edit_post';
	}

	/**
	 * Tells whether the current user may see, make, replace or revoke the link of a post: the links are on, the user may edit the post,
	 * and has the capability of the setting. The server decides; the browser is trusted for nothing.
	 *
	 * @param int $post_id Id of the post.
	 * @return bool
	 */
	public function may_share( $post_id ) {
		if ( $post_id <= 0 || ! $this->links_enabled() || ! (bool) ( $this->can )( 'edit_post', $post_id ) ) {
			return false;
		}

		$capability = $this->capability();

		return 'edit_post' === $capability || (bool) ( $this->can )( $capability );
	}

	/**
	 * Sanitizes the option when it is saved: two booleans and a capability from the list (the default when it is not one). Keys of the form
	 * that are not settings are dropped.
	 *
	 * @param mixed $input Value posted.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$capability = isset( $input[ self::CAPABILITY ] ) && is_string( $input[ self::CAPABILITY ] ) ? $input[ self::CAPABILITY ] : '';

		return array(
			self::LINKS      => ! empty( $input[ self::LINKS ] ),
			self::CAPABILITY => in_array( $capability, self::CAPABILITIES, true ) ? $capability : 'edit_post',
			self::UNLISTED   => ! empty( $input[ self::UNLISTED ] ),
		);
	}
}
