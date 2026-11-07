<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The routing of the addresses of the tree.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves an address as a chain of exact titles, **with no rewrite rule of its own** (slice 503, hierarchical model).
 *
 * - On `parse_request`, after WordPress chose its rule, the module acts **only on the shapes that can be a page**: `pagename`, `name`,
 *   `attachment` or the error 404 (with none of the variables of a specific route: feeds, search, embeds...). The specific routes of
 *   WordPress therefore always win.
 * - The path is walked one level at a time through the index (`Keys`). A page found at its canonical address is **served as it is**
 *   (`page_id`); an alias, a lower-case encoding or a trailing slash is a **301** to the canonical address.
 * - A path that does not resolve is left to WordPress **unless** it enters the tree (its first title exists), WordPress would find a page of
 *   the tree by its slug, its first title is a first-level title written another way (an accent, a case), **or the user may create pages and
 *   WordPress finds nothing at that address**: it is then a **404** that remembers where the path stopped (`missing()`, for slice 504), and the
 *   offer of creation (a visitor keeps the plain 404). **There is no slug door.**
 * - The `redirect_canonical` of WordPress is turned off for the requests that are ours: it adds a trailing slash, which our own redirect
 *   removes (a loop), and it guesses slugs; its guess for a 404 that is not ours never lands on a page of the tree (`guess()`).
 * - `page_link` gives the address; the page that is the static front page keeps `/` (it is served at its own address too).
 */
final class Router {
	/**
	 * Option that holds the version of the rules that were flushed: a flush is made once so that the rule of the first model goes.
	 */
	public const OPTION = 'wiki_rules';

	/**
	 * Version of the routing: no rule of its own.
	 */
	public const VERSION = '2';

	/**
	 * Query variables of the routes that are not ours: when one is set the request is left alone.
	 */
	private const OTHER_ROUTES = array( 'feed', 'embed', 'trackback', 's', 'p', 'page_id', 'attachment_id', 'preview' );

	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * Returns the current request, path and query string, as the server received it.
	 *
	 * @var callable
	 */
	private $request_uri;

	/**
	 * Redirects (url, status) and ends the request.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Returns an address of the site for a path.
	 *
	 * @var callable
	 */
	private $home;

	/**
	 * Tells whether the site has pretty permalinks.
	 *
	 * @var callable
	 */
	private $pretty;

	/**
	 * Returns the id of the page that WordPress would find for a path of slugs, or 0.
	 *
	 * @var callable
	 */
	private $by_slug;

	/**
	 * Returns the id of the static front page, or 0.
	 *
	 * @var callable
	 */
	private $front;

	/**
	 * Tells whether the current user may create a page (a path that nothing has is then offered for creation).
	 *
	 * @var callable
	 */
	private $may_create;

	/**
	 * Tells whether WordPress would find something for the request (a page, a post, a attachment) by its slug.
	 *
	 * @var callable
	 */
	private $exists;

	/**
	 * Whether the request being answered is one of ours.
	 *
	 * @var bool
	 */
	private $ours = false;

	/**
	 * Where the path stopped, for the page that offers to create it.
	 *
	 * @var array{deepest: int, rest: string[]}|null
	 */
	private $missing = null;

	/**
	 * Builds the router.
	 *
	 * @param Keys          $keys        The index of the tree.
	 * @param callable|null $request_uri Default: the request as `add_query_arg( array() )` reads it.
	 * @param callable|null $redirect    Receives an address and a status; default `wp_safe_redirect()` and `exit`.
	 * @param callable|null $home        Receives a path; default `home_url()`.
	 * @param callable|null $pretty      Default: the site has a permalink structure.
	 * @param callable|null $by_slug     Receives a path of slugs; default `get_page_by_path()`.
	 * @param callable|null $front       Default: the option `page_on_front` when the front page is a page.
	 * @param callable|null $may_create  Default: the user may create pages (`create_posts` of the type `page`).
	 * @param callable|null $exists      Receives the query variables; default: `get_page_by_path()` finds a public post for one of the slugs.
	 */
	public function __construct( Keys $keys, $request_uri = null, $redirect = null, $home = null, $pretty = null, $by_slug = null, $front = null, $may_create = null, $exists = null ) {
		$this->keys        = $keys;
		$this->request_uri = $request_uri ?? static function () {
			return add_query_arg( array() );
		};
		$this->redirect    = $redirect ?? static function ( $url, $status ) {
			wp_safe_redirect( $url, $status );
			exit;
		};
		$this->home        = $home ?? 'home_url';
		$this->pretty      = $pretty ?? static function () {
			return '' !== (string) get_option( 'permalink_structure' );
		};
		$this->by_slug     = $by_slug ?? static function ( $path ) {
			$page = get_page_by_path( $path );

			return is_object( $page ) ? (int) $page->ID : 0;
		};
		$this->may_create  = $may_create ?? static function () {
			$type = get_post_type_object( Nodes::TYPE );

			return is_object( $type ) && isset( $type->cap->create_posts ) && current_user_can( $type->cap->create_posts );
		};
		$this->exists      = $exists ?? static function ( $vars ) {
			foreach ( array( 'pagename', 'name', 'attachment' ) as $name ) {
				if ( isset( $vars[ $name ] ) && '' !== (string) $vars[ $name ] && is_object( get_page_by_path( (string) $vars[ $name ], OBJECT, get_post_types( array( 'public' => true ) ) ) ) ) {
					return true;
				}
			}

			return false;
		};
		$this->front       = $front ?? static function () {
			return 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		};
	}

	/**
	 * Flushes the rewrite rules once, so that the rule of the first model (`Space:Title`) goes. Action `init`, late.
	 *
	 * @return void
	 */
	public function maybe_flush() {
		if ( self::VERSION === get_option( self::OPTION ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::OPTION, self::VERSION );
	}

	/**
	 * Resolves the request. Action `parse_request`.
	 *
	 * @param object $wp The `WP` object: `query_vars` is changed.
	 * @return void
	 */
	public function parse_request( $wp ) {
		$this->ours    = false;
		$this->missing = null;

		if ( ! is_object( $wp ) || ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) || ! $this->can_be_a_page( $wp->query_vars ) ) {
			return;
		}

		$titles = Address::titles( (string) ( $wp->request ?? '' ) );

		if ( null === $titles ) {
			return;
		}

		$walk = $this->keys->resolve_path( $titles );

		if ( null !== $walk['post'] ) {
			$this->serve( $wp, (int) $walk['post'], (bool) $walk['exact'] );

			return;
		}

		if ( $walk['deepest'] > 0 || $this->slug_is_ours( $wp->query_vars ) || array() !== $this->keys->similar( 0, $titles[0] ) || $this->offer_creation( $wp->query_vars, $titles ) ) {
			$this->ours    = true;
			$this->missing = array(
				'deepest' => (int) $walk['deepest'],
				'rest'    => $walk['rest'],
			);

			foreach ( array( 'pagename', 'name', 'attachment', 'page' ) as $name ) {
				unset( $wp->query_vars[ $name ] );
			}

			$wp->query_vars['error'] = '404';
		}
	}

	/**
	 * Where the path of the request stopped, when it is a 404 of the tree.
	 *
	 * @return array{deepest: int, rest: string[]}|null The last page that exists (0 for none) and the titles that were not found.
	 */
	public function missing() {
		return $this->missing;
	}

	/**
	 * Turns off the redirect of WordPress for the requests that are ours, and its guesses that land on a page of the tree. Filter
	 * `redirect_canonical`.
	 *
	 * @param mixed $redirect_url  The address WordPress would redirect to.
	 * @param mixed $requested_url The address requested.
	 * @return mixed
	 */
	public function redirect_canonical( $redirect_url, $requested_url = '' ) {
		unset( $requested_url );

		return $this->ours ? false : $this->guess( $redirect_url );
	}

	/**
	 * Takes a page of the tree out of what WordPress redirects to when the request is not ours: it guesses a 404 from the last slug
	 * (`/Nowhere/Paris` would be sent to the page `Paris`), which would be a slug door (there is no filter on that guess, only on the
	 * redirect). No other redirect of WordPress leads to a page of the tree.
	 *
	 * @param mixed $url The address guessed, or false.
	 * @return mixed
	 */
	private function guess( $url ) {
		if ( ! is_string( $url ) ) {
			return $url;
		}

		$titles = Address::titles( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		return null !== $titles && null !== $this->keys->resolve_path( $titles )['post'] ? false : $url;
	}

	/**
	 * The address of a page. Filter `page_link`.
	 *
	 * @param mixed $link    The address WordPress made.
	 * @param mixed $post_id The page.
	 * @return mixed
	 */
	public function page_link( $link, $post_id = 0 ) {
		$id = (int) $post_id;

		if ( $id <= 0 || ! (bool) ( $this->pretty )() || ( (int) ( $this->front )() === $id ) ) {
			return $link;
		}

		$titles = $this->keys->path_of( $id );

		return null === $titles ? $link : $this->canonical( $titles );
	}

	/**
	 * The address of a chain of titles.
	 *
	 * @param string[] $titles The titles from the first level.
	 * @return string
	 */
	public function canonical( array $titles ) {
		return (string) ( $this->home )( Address::path( $titles ) );
	}

	/**
	 * Serves a page found by its titles, or redirects to its canonical address.
	 *
	 * @param object $wp    The `WP` object.
	 * @param int    $post  The page.
	 * @param bool   $exact False when an alias was used on the way.
	 * @return void
	 */
	private function serve( $wp, $post, $exact ) {
		$this->ours = true;
		$titles     = $this->keys->path_of( $post );

		if ( null !== $titles ) {
			$canonical = $this->canonical( $titles );

			if ( ! $exact || $this->path_asked() !== (string) wp_parse_url( $canonical, PHP_URL_PATH ) ) {
				( $this->redirect )( $canonical . $this->query_string(), 301 );

				return;
			}
		}

		foreach ( array( 'pagename', 'name', 'attachment', 'error', 'page' ) as $name ) {
			unset( $wp->query_vars[ $name ] );
		}

		$wp->query_vars['page_id'] = $post;
	}

	/**
	 * Whether the variables of the request are the shapes of a page: `pagename`, `name`, `attachment` or the error 404, and no variable of
	 * a specific route.
	 *
	 * @param array<string, mixed> $vars The query variables.
	 * @return bool
	 */
	private function can_be_a_page( array $vars ) {
		foreach ( self::OTHER_ROUTES as $name ) {
			if ( isset( $vars[ $name ] ) ) {
				return false;
			}
		}

		return isset( $vars['pagename'] ) || isset( $vars['name'] ) || isset( $vars['attachment'] ) || ( isset( $vars['error'] ) && '404' === (string) $vars['error'] );
	}

	/**
	 * Whether a path whose first title nothing has is offered for creation: only to a user who may create pages, when WordPress would find
	 * nothing at that address, and when the last title does not look like a file (`/apple-touch-icon.png`). A visitor keeps the plain 404.
	 *
	 * @param array<string, mixed> $vars   The query variables.
	 * @param string[]             $titles The titles asked.
	 * @return bool
	 */
	private function offer_creation( array $vars, array $titles ) {
		return 1 !== preg_match( '/\.[A-Za-z0-9]{2,5}$/', (string) end( $titles ) ) && (bool) ( $this->may_create )() && ! (bool) ( $this->exists )( $vars );
	}

	/**
	 * Whether WordPress is about to show a page of the tree by its slug (the address of the slugs, or a title in another case): it is not an
	 * address of the wiki.
	 *
	 * @param array<string, mixed> $vars The query variables.
	 * @return bool
	 */
	private function slug_is_ours( array $vars ) {
		foreach ( array( 'pagename', 'name', 'attachment' ) as $name ) {
			if ( isset( $vars[ $name ] ) && '' !== (string) $vars[ $name ] ) {
				$id = (int) ( $this->by_slug )( (string) $vars[ $name ] );

				if ( $id > 0 && null !== $this->keys->current_of( $id ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The path of the request, as the server received it (no query string, nothing decoded).
	 *
	 * @return string
	 */
	private function path_asked() {
		return (string) strtok( (string) ( $this->request_uri )(), '?' );
	}

	/**
	 * The query string of the request, with its question mark, or nothing.
	 *
	 * @return string
	 */
	private function query_string() {
		$uri      = (string) ( $this->request_uri )();
		$position = strpos( $uri, '?' );

		return false === $position || strlen( $uri ) === $position + 1 ? '' : substr( $uri, $position );
	}
}
