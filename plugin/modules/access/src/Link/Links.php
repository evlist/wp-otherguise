<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The private links: a secret token per post.
 *
 * @package Otherguise
 */

namespace Otherguise\Access\Link;

defined( 'ABSPATH' ) || exit;

/**
 * One token per post, created on demand and valid until it is revoked or replaced (slice 400). The token is 16 random bytes in the URL-safe
 * form of base64 (22 characters, 128 bits), kept in clear because a shared link has to be shown again. The address of a link is the
 * address of the site with the argument `shared`: the token alone identifies the post.
 */
final class Links {
	public const TOKEN   = '_access_token';
	public const CREATED = '_access_token_created';
	public const ARGUMENT = 'shared';

	/**
	 * Where the tokens are kept (see `PostMeta`).
	 *
	 * @var object
	 */
	private $meta;

	/**
	 * Gives 16 random bytes.
	 *
	 * @var callable
	 */
	private $random;

	/**
	 * Gives the date and time in GMT.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Tells whether a type of post can be seen on the front end.
	 *
	 * @var callable
	 */
	private $viewable;

	/**
	 * Builds the service.
	 *
	 * @param object|null   $meta     Default `PostMeta`.
	 * @param callable|null $random   Default `random_bytes( 16 )`.
	 * @param callable|null $now      Default the date and time in GMT.
	 * @param callable|null $viewable Default `is_post_type_viewable`.
	 */
	public function __construct( $meta = null, $random = null, $now = null, $viewable = null ) {
		$this->meta     = $meta ?? new PostMeta();
		$this->random   = $random ?? static function () {
			return random_bytes( 16 );
		};
		$this->now      = $now ?? static function () {
			return gmdate( 'Y-m-d H:i:s' );
		};
		$this->viewable = $viewable ?? 'is_post_type_viewable';
	}

	/**
	 * Tells whether a string has the form of a token.
	 *
	 * @param mixed $token Value.
	 * @return bool
	 */
	public static function is_token( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9_-]{22}\\z/', $token );
	}

	/**
	 * Tells whether a post can have a link: a post that can be seen on the front end (not an attachment), which is not in the trash.
	 *
	 * @param object|null $post Post.
	 * @return bool
	 */
	public function eligible( $post ) {
		if ( ! is_object( $post ) || ! isset( $post->ID, $post->post_type, $post->post_status ) ) {
			return false;
		}

		return 'attachment' !== $post->post_type
			&& ! in_array( (string) $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true )
			&& (bool) ( $this->viewable )( (string) $post->post_type );
	}

	/**
	 * The token of a post, or an empty string.
	 *
	 * @param int $id Id of the post.
	 * @return string
	 */
	public function token( $id ) {
		$token = $this->meta->get( (int) $id, self::TOKEN );

		return self::is_token( $token ) ? $token : '';
	}

	/**
	 * The date, in GMT, at which the token was made, or an empty string.
	 *
	 * @param int $id Id of the post.
	 * @return string
	 */
	public function created( $id ) {
		return '' === $this->token( $id ) ? '' : $this->meta->get( (int) $id, self::CREATED );
	}

	/**
	 * Makes the token of a post when it has none (the one it has is kept).
	 *
	 * @param int $id Id of the post.
	 * @return string The token.
	 */
	public function create( $id ) {
		$token = $this->token( $id );

		return '' === $token ? $this->replace( (int) $id ) : $token;
	}

	/**
	 * Replaces the token of a post by a new one: the old link stops working at once.
	 *
	 * @param int $id Id of the post.
	 * @return string The new token.
	 */
	public function regenerate( $id ) {
		return $this->replace( (int) $id );
	}

	/**
	 * Takes the link away: the token is deleted.
	 *
	 * @param int $id Id of the post.
	 * @return bool Whether the post had one.
	 */
	public function revoke( $id ) {
		$had = '' !== $this->token( $id );

		$this->meta->delete( (int) $id, self::TOKEN );
		$this->meta->delete( (int) $id, self::CREATED );

		return $had;
	}

	/**
	 * Finds the post of a token: the token must have the form of one, and its value is compared with the stored one in constant time.
	 *
	 * @param mixed $token Value of the argument.
	 * @return int Id of the post, 0 when the token opens nothing.
	 */
	public function find( $token ) {
		if ( ! self::is_token( $token ) ) {
			return 0;
		}

		foreach ( $this->meta->find( self::TOKEN, $token ) as $id ) {
			if ( hash_equals( $this->token( $id ), $token ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * The posts that have a link, by id, with the date of the token.
	 *
	 * @return array<int, string>
	 */
	public function all() {
		$links = array();

		foreach ( $this->meta->find( self::TOKEN ) as $id ) {
			if ( '' !== $this->token( $id ) ) {
				$links[ (int) $id ] = $this->created( $id );
			}
		}

		return $links;
	}

	/**
	 * The address of the link of a post, or false when it has none.
	 *
	 * @param int      $id        Id of the post.
	 * @param string[] $arguments `mode`: the slug of a mode to open the page in.
	 * @param string   $site      Address of the site (the home page).
	 * @return string|false
	 */
	public function address( $id, array $arguments = array(), $site = '' ) {
		$token = $this->token( $id );

		if ( '' === $token ) {
			return false;
		}

		$site  = '' === $site ? home_url( '/' ) : $site;
		$query = array( self::ARGUMENT => $token );
		$mode  = isset( $arguments['mode'] ) && is_string( $arguments['mode'] ) ? preg_replace( '/[^a-z0-9_-]/', '', strtolower( $arguments['mode'] ) ) : '';

		if ( '' !== $mode ) {
			$query['mode'] = $mode;
		}

		return add_query_arg( $query, $site );
	}

	/**
	 * Writes a new token for a post.
	 *
	 * @param int $id Id of the post.
	 * @return string
	 */
	private function replace( $id ) {
		$old   = $this->token( $id );
		$token = $this->draw();

		// A new link must differ from the old one (a draw twice the same is not expected from 128 bits; a double of the tests can).
		if ( $token === $old ) {
			$token = $this->draw();
		}

		$this->meta->set( $id, self::TOKEN, $token );
		$this->meta->set( $id, self::CREATED, ( $this->now )() );

		return $token;
	}

	/**
	 * A token: 16 random bytes in the URL-safe form of base64, without the padding.
	 *
	 * @return string
	 */
	private function draw() {
		return rtrim( strtr( base64_encode( ( $this->random )() ), '+/', '-_' ), '=' );
	}
}
