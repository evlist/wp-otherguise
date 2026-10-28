<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The front request that carries a token: it shows the post of the token.
 *
 * @package Otherguise
 */

namespace Otherguise\Access\Link;

defined( 'ABSPATH' ) || exit;

/**
 * A request of the front end whose only argument is `shared=<token>` (slice 400) is the request of the post of that token, read only,
 * whatever its status: a draft, a pending, a private or a future post is shown to a visitor who is not logged in. Anything else about the
 * request makes the token count for nothing (a feed, the REST API, a search, a post given by its address): the token opens **one thing**,
 * the main request of that post. An unknown or malformed token gives a 404, never the home page.
 *
 * How it is done (tried on WordPress 7.1.3 before it was written): `parse_request` replaces the query by `p` or `page_id`, the type **and
 * the status** of the post (WordPress lets a status that was asked for pass through, otherwise it hides what is not public from a
 * visitor), `posts_pre_query` gives the post to the main query, the canonical redirection is turned off (it would lose the token).
 *
 * Coexistence: `parse_request` and `posts_pre_query` at priority 10. The filter `posts_pre_query` answers only for the main query of a
 * request that has a token, and leaves the value it receives otherwise.
 */
final class Visit {
	/**
	 * The links.
	 *
	 * @var Links
	 */
	private $links;

	/**
	 * Reads a post.
	 *
	 * @var callable
	 */
	private $get_post;

	/**
	 * Sends a header, makes the answer uncacheable and tells the page caches: three callables, for the tests.
	 *
	 * @var callable[]
	 */
	private $send;

	/**
	 * Id of the post that the token of this request opens, 0 when there is none.
	 *
	 * @var int
	 */
	private $post_id = 0;

	/**
	 * Builds the handler of the request.
	 *
	 * @param Links         $links    The links.
	 * @param callable|null $get_post Default `get_post`.
	 * @param callable[]    $send     `header` (receives a line), `no_cache` (the headers against the caches of the browser) and `define`
	 *                                (`DONOTCACHEPAGE`, for the page caches); the defaults are those of PHP and WordPress.
	 */
	public function __construct( Links $links, $get_post = null, array $send = array() ) {
		$this->links    = $links;
		$this->get_post = $get_post ?? 'get_post';
		$this->send     = $send + array(
			'header'   => 'header',
			'no_cache' => 'nocache_headers',
			'define'   => static function () {
				// The constant that the page caches look for (WP Super Cache, W3 Total Cache, WP Rocket...): a convention of theirs, so its name
				// cannot have the prefix of this plugin.
				$name = 'DONOTCACHEPAGE';

				if ( ! defined( $name ) ) {
					define( $name, true );
				}
			},
		);
	}

	/**
	 * Tells WordPress about the argument `shared`. Filter `query_vars`.
	 *
	 * @param mixed $vars Public query variables.
	 * @return mixed
	 */
	public function query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[] = Links::ARGUMENT;
		}

		return $vars;
	}

	/**
	 * Replaces the query of a request that carries a token. Action `parse_request`.
	 *
	 * @param object $wp The `WP` object.
	 * @return void
	 */
	public function parse_request( $wp ) {
		$this->post_id = 0;

		if ( ! is_object( $wp ) || ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) || ! array_key_exists( Links::ARGUMENT, $wp->query_vars ) ) {
			return;
		}

		// The token is the only thing in the request: with a feed, a search, a page of results, a post... it opens nothing.
		if ( array( Links::ARGUMENT ) !== array_keys( $wp->query_vars ) ) {
			return;
		}

		$id   = $this->links->find( $wp->query_vars[ Links::ARGUMENT ] );
		$post = $id > 0 ? ( $this->get_post )( $id ) : null;

		if ( ! $this->links->eligible( $post ) ) {
			$wp->query_vars = array( 'error' => '404' );

			return;
		}

		$this->post_id = $id;

		$wp->query_vars = 'page' === $post->post_type
			? array(
				'page_id'     => $id,
				'post_status' => $post->post_status,
			)
			: array(
				'p'           => $id,
				'post_type'   => $post->post_type,
				'post_status' => $post->post_status,
			);
	}

	/**
	 * Gives the post to the main query, so that WordPress does not look at its status. Filter `posts_pre_query`. Only for the main query that
	 * asks for that post (or for none in particular).
	 *
	 * @param mixed  $posts Posts given by another filter, or null.
	 * @param object $query The query.
	 * @return mixed
	 */
	public function posts_pre_query( $posts, $query ) {
		if ( $this->post_id <= 0 || ! is_object( $query ) || ! method_exists( $query, 'is_main_query' ) || ! $query->is_main_query() ) {
			return $posts;
		}

		// A main query that asks for another post (the merge of a section re-queries the main query once for each of its units) is not the
		// visit of the shared post: it is left to WordPress, which looks at the status of that post.
		$vars  = isset( $query->query_vars ) && is_array( $query->query_vars ) ? $query->query_vars : array();
		$asked = (int) ( $vars['p'] ?? 0 );
		$asked = $asked > 0 ? $asked : (int) ( $vars['page_id'] ?? 0 );

		if ( $asked > 0 && $asked !== $this->post_id ) {
			return $posts;
		}

		$post = ( $this->get_post )( $this->post_id );

		if ( ! is_object( $post ) ) {
			return $posts;
		}

		$query->found_posts   = 1;
		$query->max_num_pages = 1;

		return array( $post );
	}

	/**
	 * Keeps the token in the address: no redirection to the permalink. Filter `redirect_canonical`.
	 *
	 * @param mixed $url Address of the redirection.
	 * @return mixed
	 */
	public function redirect_canonical( $url ) {
		return $this->post_id > 0 ? false : $url;
	}

	/**
	 * Marks the answer: no cache, no search engine, no referrer. Action `send_headers`.
	 *
	 * @return void
	 */
	public function send_headers() {
		if ( $this->post_id <= 0 ) {
			return;
		}

		( $this->send['define'] )();
		( $this->send['no_cache'] )();
		( $this->send['header'] )( 'X-Robots-Tag: noindex, nofollow' );
		( $this->send['header'] )( 'Referrer-Policy: no-referrer' );
	}

	/**
	 * Asks the search engines to leave the page alone. Filter `wp_robots`.
	 *
	 * @param mixed $robots Directives.
	 * @return mixed
	 */
	public function robots( $robots ) {
		if ( $this->post_id > 0 && is_array( $robots ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['max-image-preview'] );
		}

		return $robots;
	}

	/**
	 * The post that the token of the request opens, 0 when there is none.
	 *
	 * @return int
	 */
	public function post_id() {
		return $this->post_id;
	}
}
