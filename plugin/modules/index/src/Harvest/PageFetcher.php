<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Asks the site for the web page of a post.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Harvest;

defined( 'ABSPATH' ) || exit;

/**
 * A request of the site to itself (slice 602): the address of the post, the web rendering (no `?print`), with a timeout. The result is the
 * HTML or **a reason**: a page that cannot be reached is reported, never skipped silently.
 */
final class PageFetcher {
	/**
	 * Returns the address of a post: `fn( int $post_id ): string`.
	 *
	 * @var callable
	 */
	private $permalink;

	/**
	 * Returns the public address of the site (its home page).
	 *
	 * @var callable
	 */
	private $home;

	/**
	 * Returns the internal address, or an empty string.
	 *
	 * @var callable
	 */
	private $internal;

	/**
	 * Makes the request: `fn( string $url, array $args ): array|WP_Error`, as `wp_remote_get`.
	 *
	 * @var callable
	 */
	private $get;

	/**
	 * Builds the fetcher.
	 *
	 * @param callable|null $permalink Default `get_permalink`.
	 * @param callable|null $home      Default `home_url`.
	 * @param callable|null $internal  Default the option `index_site_address`, then the filter of the same name.
	 * @param callable|null $get       Default `wp_remote_get`.
	 */
	public function __construct( $permalink = null, $home = null, $internal = null, $get = null ) {
		$this->permalink = $permalink ?? 'get_permalink';
		$this->home      = $home ?? static function () {
			return home_url( '/' );
		};
		$this->internal  = $internal ?? static function () {
			return (string) apply_filters( 'index_site_address', (string) get_option( 'index_site_address', '' ) );
		};
		$this->get       = $get ?? 'wp_remote_get';
	}

	/**
	 * Fetches the page of a post.
	 *
	 * @param int $post_id Post.
	 * @return array{html:string,error:string,url:string}
	 */
	public function fetch( $post_id ) {
		$url = (string) ( $this->permalink )( (int) $post_id );

		if ( '' === $url ) {
			return $this->failure( '', 'The post has no address.' );
		}

		$asked    = Address::rewrite( $url, (string) ( $this->home )(), (string) ( $this->internal )() );
		$response = ( $this->get )(
			$asked,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure( $asked, 'The request failed: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return $this->failure( $asked, 'The page answered with the status ' . $status . '.' );
		}

		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );

		if ( '' !== $type && false === strpos( $type, 'html' ) ) {
			return $this->failure( $asked, 'The page is not HTML (' . $type . ').' );
		}

		return array(
			'html'  => (string) wp_remote_retrieve_body( $response ),
			'error' => '',
			'url'   => $asked,
		);
	}

	/**
	 * A failure.
	 *
	 * @param string $url    Address asked.
	 * @param string $reason Reason.
	 * @return array{html:string,error:string,url:string}
	 */
	private function failure( $url, $reason ) {
		return array(
			'html'  => '',
			'error' => $reason,
			'url'   => $url,
		);
	}
}
