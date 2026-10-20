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
	 * Builds the reader.
	 *
	 * @param callable|null $get_post  Defaults to `get_post`.
	 * @param callable|null $permalink Defaults to `get_permalink`.
	 * @param callable|null $address   Defaults to the permalink with `?mode=<slug>`.
	 */
	public function __construct( $get_post = null, $permalink = null, $address = null ) {
		$this->get_post  = $get_post ?? 'get_post';
		$this->permalink = $permalink ?? 'get_permalink';
		$this->address   = $address ?? array( $this, 'default_address' );
	}

	/**
	 * Returns what is known of a post, or null when it does not exist.
	 *
	 * @param int $post_id Post.
	 * @return array{status:string, modified:string, slug:string, url:string}|null
	 */
	public function info( $post_id ) {
		$post = ( $this->get_post )( (int) $post_id );

		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			return null;
		}

		$url = ( $this->permalink )( $post );

		return array(
			'status'   => (string) ( $post->post_status ?? '' ),
			'modified' => (string) ( $post->post_modified_gmt ?? '' ),
			'slug'     => (string) ( $post->post_name ?? '' ),
			'url'      => is_string( $url ) ? $url : '',
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
