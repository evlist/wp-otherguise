<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The links to the pages of the tree.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * Ordinary links are links by name (slice 505, hierarchical model): the address is the chain of the titles, so a link to it needs no syntax
 * and no stored address. At render, for every `<a href>` that stands for a path of the tree:
 *
 * - a page the reader may see at its **current address**: the class `wiki-link`;
 * - an **alias** (the page was renamed or moved): the `href` is **replaced by the current address**, so there is no redirect to follow, and the class `wiki-link` is added;
 * - a path that **enters the tree but stops** (or a page the reader may not see, which is not told apart from one that does not exist): the
 *   classes `wiki-link wiki-missing` and a `title`; the address stays, and leads to the offer of slice 504 (a red link).
 *
 * A link that is not an address of the tree is not touched. A path whose **first title** is unknown is an ordinary link (a new page of the
 * first level cannot be told from a broken link of the site), unless it is a first-level title written another way (an accent, a case).
 * Nothing is stored; a path is looked up once per request.
 */
final class Links {
	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * The router, for the address of a chain of titles.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Returns the address of the site (`home_url()`).
	 *
	 * @var callable
	 */
	private $home;

	/**
	 * Tells whether the current user may see a page.
	 *
	 * @var callable
	 */
	private $visible;

	/**
	 * What was found for a path of the request: path => result.
	 *
	 * @var array<string, array{state: string, url: string}>
	 */
	private $seen = array();

	/**
	 * Builds the service.
	 *
	 * @param Keys          $keys    The index of the tree.
	 * @param Router        $router  The router.
	 * @param callable|null $home    Default `home_url`.
	 * @param callable|null $visible Receives a page id; default: published, or the user may read it.
	 */
	public function __construct( Keys $keys, Router $router, $home = null, $visible = null ) {
		$this->keys    = $keys;
		$this->router  = $router;
		$this->home    = $home ?? 'home_url';
		$this->visible = $visible ?? static function ( $id ) {
			return 'publish' === get_post_status( (int) $id ) || current_user_can( 'read_post', (int) $id );
		};
	}

	/**
	 * The titles that an address stands for, or null when it is not an address of the site.
	 *
	 * @param string $href The `href` of a link, relative or absolute.
	 * @return string[]|null
	 */
	public function titles_of( $href ) {
		$href  = trim( (string) $href );
		$parts = wp_parse_url( $href );
		$home  = wp_parse_url( (string) ( $this->home )() );

		if ( ! is_array( $parts ) || ! is_array( $home ) || isset( $parts['user'] ) || ! isset( $parts['path'] ) ) {
			return null;
		}

		if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		if ( isset( $parts['host'] ) && 0 !== strcasecmp( $parts['host'], (string) ( $home['host'] ?? '' ) ) ) {
			return null;
		}

		$path   = $parts['path'];
		$prefix = rtrim( (string) ( $home['path'] ?? '' ), '/' );

		if ( '' !== $prefix ) {
			if ( 0 !== strpos( $path, $prefix . '/' ) ) {
				return null;
			}

			$path = substr( $path, strlen( $prefix ) );
		}

		return '/' === ( $path[0] ?? '' ) ? Address::titles( $path ) : null;
	}

	/**
	 * What a link is, for a path of titles: `page` (exact), `alias` (to rewrite, with the current address), `missing` or `other`.
	 *
	 * @param string[] $titles The titles.
	 * @return array{state: string, url: string}
	 */
	public function state( array $titles ) {
		$key = implode( '/', $titles );

		if ( isset( $this->seen[ $key ] ) ) {
			return $this->seen[ $key ];
		}

		$walk   = $this->keys->resolve_path( $titles );
		$result = array(
			'state' => 'other',
			'url'   => '',
		);

		if ( null !== $walk['post'] ) {
			$current = $this->keys->path_of( (int) $walk['post'] );

			if ( null !== $current && (bool) ( $this->visible )( (int) $walk['post'] ) ) {
				$result = array(
					'state' => $walk['exact'] && Address::path( $current ) === Address::path( $titles ) ? 'page' : 'alias',
					'url'   => $this->router->canonical( $current ),
				);
			} else {
				$result['state'] = 'missing';
			}
		} elseif ( $walk['deepest'] > 0 || array() !== $this->keys->similar( 0, $titles[0] ) ) {
			$result['state'] = 'missing';
		}

		$this->seen[ $key ] = $result;

		return $result;
	}

	/**
	 * Marks the links of a piece of HTML. Filter `the_content`.
	 *
	 * @param mixed $html The content.
	 * @return mixed
	 */
	public function filter( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<a' ) || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$tags = new \WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( 'a' ) ) {
			$href   = $tags->get_attribute( 'href' );
			$titles = is_string( $href ) ? $this->titles_of( $href ) : null;

			if ( null === $titles ) {
				continue;
			}

			$state = $this->state( $titles );

			if ( 'other' === $state['state'] ) {
				continue;
			}

			$tags->add_class( 'wiki-link' );

			if ( 'missing' === $state['state'] ) {
				$tags->add_class( 'wiki-missing' );
				$tags->set_attribute( 'title', __( 'This page does not exist', 'otherguise' ) );
			} elseif ( 'alias' === $state['state'] ) {
				$tags->set_attribute( 'href', $state['url'] . $this->tail( (string) $href ) );
			}
		}

		return $tags->get_updated_html();
	}

	/**
	 * The query string and the fragment of an address, with their signs.
	 *
	 * @param string $href The `href`.
	 * @return string
	 */
	private function tail( $href ) {
		$cut = strcspn( $href, '?#' );

		return substr( $href, $cut );
	}
}
