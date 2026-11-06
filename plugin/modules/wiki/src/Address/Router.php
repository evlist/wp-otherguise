<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The routing of the addresses of the wiki pages.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Address;

use Otherguise\Wiki\Name;
use Otherguise\Wiki\Names;
use Otherguise\Wiki\Page\PageStore;
use Otherguise\Wiki\Space\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves `/<Space>:<Title>` by itself (slice 503): WordPress is not asked to find a slug.
 *
 * - **One rewrite rule, at the top**, that reaches two query variables; on `parse_request` the request is **resolved**: an unknown space is
 *   an ordinary 404; a current name makes the main query ask for that page (so the templates, the modes and the variants work as for any
 *   post); an alias is a **301** to the current address; a name that nobody has is a 404 that keeps what was asked.
 * - **The canonical form is ours**: the `redirect_canonical` of WordPress is turned off for these requests (it adds a trailing slash and
 *   writes the percent-encoding in lower case), and a **301** is sent when the path asked for is not the canonical one of the page.
 * - **`get_permalink()`** gives the address, through `post_type_link`, for a page that has a name and when the site has pretty permalinks;
 *   otherwise the plain address (`?p=<id>`) stays.
 *
 * The module is at the root of the site: no slug of WordPress has a colon, so a path whose first segment has one is the module's or nobody's.
 */
final class Router {
	/**
	 * Query variable: the space asked for.
	 */
	public const SPACE = 'wiki_space';

	/**
	 * Query variable: the title asked for.
	 */
	public const TITLE = 'wiki_title';

	/**
	 * Version of the rewrite rule: when it changes, the rules are flushed once (an update, not only an activation).
	 */
	public const VERSION = '1';

	/**
	 * Option that holds the version of the rules that were flushed.
	 */
	public const OPTION = 'wiki_rules';

	/**
	 * The rewrite rule, as WordPress keeps it (its regular expression is its key).
	 */
	public const PATTERN = '^([^/:]+):(.+?)/?$';

	/**
	 * The names.
	 *
	 * @var Names
	 */
	private $names;

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
	 * Whether the request being answered is one of ours: the redirect of WordPress is then turned off.
	 *
	 * @var bool
	 */
	private $ours = false;

	/**
	 * Builds the router.
	 *
	 * @param Names         $names       The names.
	 * @param callable|null $request_uri Default: the request as `add_query_arg( array() )` reads it.
	 * @param callable|null $redirect    Receives an address and a status; default `wp_safe_redirect()` and `exit`.
	 * @param callable|null $home        Receives a path; default `home_url()`.
	 * @param callable|null $pretty      Default: the site has a permalink structure.
	 */
	public function __construct( Names $names, $request_uri = null, $redirect = null, $home = null, $pretty = null ) {
		$this->names       = $names;
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
	}

	/**
	 * Adds the rewrite rule, at the top. Action `init`.
	 *
	 * @return void
	 */
	public function add_rule() {
		add_rewrite_rule( self::PATTERN, 'index.php?' . self::SPACE . '=$matches[1]&' . self::TITLE . '=$matches[2]', 'top' );
	}

	/**
	 * Flushes the rewrite rules when they have to be: the version of the rule is not the one that was flushed (an update), or the rules that
	 * WordPress keeps **do not hold the rule** although the site has pretty permalinks (they were saved before the module was there, or
	 * with the permalinks switched off: a flush with plain permalinks keeps no rule at all). Action `init`, late.
	 *
	 * A flush costs a write of the option: after one, a second is not made for a minute, so that a rule that something else removes cannot
	 * make every request flush.
	 *
	 * @return void
	 */
	public function maybe_flush() {
		$stored  = get_option( 'rewrite_rules' );
		$missing = is_array( $stored ) && ! isset( $stored[ self::PATTERN ] );

		if ( self::VERSION === get_option( self::OPTION ) && ! $missing ) {
			return;
		}

		if ( $missing && false !== get_transient( self::OPTION . '_flushed' ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::OPTION, self::VERSION );
		set_transient( self::OPTION . '_flushed', '1', MINUTE_IN_SECONDS );
	}

	/**
	 * Registers the two query variables. Filter `query_vars`.
	 *
	 * @param mixed $vars The query variables.
	 * @return array<int, string>
	 */
	public function query_vars( $vars ) {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::SPACE;
		$vars[] = self::TITLE;

		return $vars;
	}

	/**
	 * Resolves a request that matched the rule. Action `parse_request`.
	 *
	 * @param object $wp The `WP` object: `query_vars` is changed.
	 * @return void
	 */
	public function parse_request( $wp ) {
		$this->ours = false;

		if ( ! is_object( $wp ) || ! isset( $wp->query_vars[ self::SPACE ], $wp->query_vars[ self::TITLE ] ) ) {
			return;
		}

		$space = Spaces::find( $this->decoded( (string) $wp->query_vars[ self::SPACE ] ) );
		$title = $this->decoded( (string) $wp->query_vars[ self::TITLE ] );

		unset( $wp->query_vars[ self::SPACE ], $wp->query_vars[ self::TITLE ] );

		if ( null === $space ) {
			// Not ours: an ordinary 404, the rule matches any `word:word`.
			$wp->query_vars['error'] = '404';

			return;
		}

		$this->ours = true;
		$found      = $this->names->resolve( $space['name'], $title );

		if ( null === $found ) {
			// Nobody has this name: a 404 that keeps what was asked, for the page that offers to create it (slice 504).
			$wp->query_vars['error']      = '404';
			$wp->query_vars[ self::SPACE ] = $space['name'];
			$wp->query_vars[ self::TITLE ] = $this->title_asked( $title );

			return;
		}

		$current = $this->names->current_of( $found['post'] );

		if ( null !== $current ) {
			$canonical = $this->canonical( $current['space'], $current['title'] );

			if ( ! $found['current'] || $this->path_asked() !== $this->path_of( $canonical ) ) {
				( $this->redirect )( $canonical . $this->query_string(), 301 );

				return;
			}
		}

		$wp->query_vars['post_type'] = PageStore::TYPE;
		$wp->query_vars['p']         = $found['post'];
	}

	/**
	 * Turns off the redirect of WordPress for the requests that are ours. Filter `redirect_canonical`.
	 *
	 * @param mixed $redirect_url  The address WordPress would redirect to.
	 * @param mixed $requested_url The address requested.
	 * @return mixed
	 */
	public function redirect_canonical( $redirect_url, $requested_url = '' ) {
		unset( $requested_url );

		return $this->ours ? false : $redirect_url;
	}

	/**
	 * The address of a page. Filter `post_type_link`.
	 *
	 * @param mixed $link The address WordPress made.
	 * @param mixed $post The page.
	 * @return mixed
	 */
	public function post_link( $link, $post ) {
		if ( ! is_object( $post ) || PageStore::TYPE !== ( $post->post_type ?? '' ) || ! (bool) ( $this->pretty )() ) {
			return $link;
		}

		$current = $this->names->current_of( (int) $post->ID );

		return null === $current ? $link : $this->canonical( $current['space'], $current['title'] );
	}

	/**
	 * The address of a name.
	 *
	 * @param string $space The space, as registered.
	 * @param string $title The title of the name.
	 * @return string
	 */
	public function canonical( $space, $title ) {
		return (string) ( $this->home )( '/' . Address::path( $space, $title ) );
	}

	/**
	 * Decodes a value of the address that is still percent-encoded. WordPress matches the rule against the path **as the server received it**
	 * (a server that gives `PATH_INFO`, such as the built-in server of PHP, gives it decoded, and nginx does not), and puts what the rule
	 * captured in the query as it is: with the raw path `Encyclop%C3%A9die` stays encoded. A name never holds a percent sign (slice 501), so a
	 * `%` followed by two digits is always an encoding to undo, and a value that is already decoded is left as it is.
	 *
	 * @param string $value The value of the query variable.
	 * @return string
	 */
	private function decoded( $value ) {
		return 1 === preg_match( '/%[0-9A-Fa-f]{2}/', $value ) ? rawurldecode( $value ) : $value;
	}

	/**
	 * The title as it was asked, as the rules make it when they accept it.
	 *
	 * @param string $title The title decoded.
	 * @return string
	 */
	private function title_asked( $title ) {
		try {
			return Name::title( $title );
		} catch ( \Otherguise\Wiki\NameException $refusal ) {
			return $title;
		}
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

	/**
	 * The path of an address.
	 *
	 * @param string $url An address of the site.
	 * @return string
	 */
	private function path_of( $url ) {
		return (string) wp_parse_url( $url, PHP_URL_PATH );
	}
}
