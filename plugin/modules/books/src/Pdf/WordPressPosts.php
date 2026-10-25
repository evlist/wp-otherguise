<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the PDF conversion asks WordPress about a post.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * What the conversion needs to know of a post (slice 220): its status, its last change, its slug and its permalink, and the address of
 * its page in a mode. The functions of WordPress are given, so that the tests do not need it.
 */
final class WordPressPosts {
	/**
	 * Returns a post: receives its id.
	 *
	 * @var callable
	 */
	private $get_post;

	/**
	 * Returns the permalink of a post: receives the post.
	 *
	 * @var callable
	 */
	private $permalink;

	/**
	 * Returns the address of a page in a mode: receives the permalink and the slug of the mode.
	 *
	 * @var callable
	 */
	private $address;

	/**
	 * Returns the private link of a post that is not published (slice 403): receives the post and whether to make the link.
	 *
	 * @var callable
	 */
	private $link;

	/**
	 * Tells whether a post that is not published can have a private link: receives the post.
	 *
	 * @var callable
	 */
	private $link_available;

	/**
	 * Tells whether the current user may make the private link of a post: receives the post.
	 *
	 * @var callable
	 */
	private $link_allowed;

	/**
	 * Builds the reader.
	 *
	 * @param callable|null $get_post       Defaults to `get_post`.
	 * @param callable|null $permalink      Defaults to `get_permalink`.
	 * @param callable|null $address        Defaults to the permalink with `?mode=<slug>`.
	 * @param callable|null $link           Defaults to `access_url()` of the Access module, when there is one.
	 * @param callable|null $link_available Defaults to `access_links_available()` of the Access module, when there is one.
	 * @param callable|null $link_allowed   Defaults to `access_user_may_share()` of the Access module, when there is one.
	 */
	public function __construct( $get_post = null, $permalink = null, $address = null, $link = null, $link_available = null, $link_allowed = null ) {
		$this->get_post       = $get_post ?? 'get_post';
		$this->permalink      = $permalink ?? 'get_permalink';
		$this->address        = $address ?? array( $this, 'default_address' );
		$this->link           = $link ?? static function ( $post, $create ) {
			return function_exists( 'access_url' ) ? access_url( $post, array( 'create' => (bool) $create ) ) : false;
		};
		$this->link_available = $link_available ?? static function ( $post ) {
			return function_exists( 'access_links_available' ) && access_links_available( $post );
		};
		$this->link_allowed   = $link_allowed ?? static function ( $post ) {
			return function_exists( 'access_user_may_share' ) && access_user_may_share( $post );
		};
	}

	/**
	 * Returns what is known of a post, or null when it does not exist.
	 *
	 * A post that is not published is converted through its **private link** of the Access module (slice 403): `preview` is then true,
	 * `available` tells whether such a link can exist, `allowed` whether the current user may have it made (the capability of the settings
	 * of the Access module; WP-Cron has no user, so the job does not look at it), and `url` is the link, which is made only when `$create` is true (the polling of
	 * the state must not make tokens), an empty string while there is none.
	 *
	 * @param int  $post_id Post.
	 * @param bool $create  Make the private link of a post that is not published when it has none.
	 * @return array{status:string, modified:string, slug:string, url:string, preview:bool, available:bool, allowed:bool}|null
	 */
	public function info( $post_id, $create = false ) {
		$post = ( $this->get_post )( (int) $post_id );

		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			return null;
		}

		$status  = (string) ( $post->post_status ?? '' );
		$preview = 'publish' !== $status;

		if ( $preview ) {
			$available = (bool) ( $this->link_available )( $post );
			$allowed   = $available && (bool) ( $this->link_allowed )( $post );
			$url       = $available && ( $allowed || ! $create ) ? ( $this->link )( $post, (bool) $create && $allowed ) : '';
		} else {
			$available = true;
			$allowed   = true;
			$url       = ( $this->permalink )( $post );
		}

		return array(
			'status'    => $status,
			'modified'  => (string) ( $post->post_modified_gmt ?? '' ),
			'slug'      => (string) ( $post->post_name ?? '' ),
			'url'       => is_string( $url ) ? $url : '',
			'preview'   => $preview,
			'available' => $available,
			'allowed'   => $allowed,
		);
	}

	/**
	 * Returns the address of a page in a mode.
	 *
	 * @param string $permalink Permalink.
	 * @param string $mode      Slug of the mode.
	 * @return string
	 */
	public function address( $permalink, $mode ) {
		return (string) ( $this->address )( $permalink, $mode );
	}

	/**
	 * The permalink with the argument `mode`.
	 *
	 * @param string $permalink Permalink.
	 * @param string $mode      Slug of the mode.
	 * @return string
	 */
	public function default_address( $permalink, $mode ) {
		return $permalink . ( false === strpos( $permalink, '?' ) ? '?' : '&' ) . 'mode=' . rawurlencode( $mode );
	}
}
