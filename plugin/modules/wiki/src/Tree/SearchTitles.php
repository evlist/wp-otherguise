<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The titles of the search results of the link tool.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * The link tool of the block editor (`Ctrl+K`) lists the pages through `/wp/v2/search`: two pages of the same title in two branches both read
 * "Paris". While that search runs, the title of a page of the tree is given **with its ancestors** (`Paris — Biography`) (slice 505). Core
 * has no filter on the title of a result: `the_title` is the way, and it is active **between the query of the search and the end of the
 * request** (`rest_post_search_query`, then `rest_post_dispatch`), so that nothing else is changed.
 */
final class SearchTitles {
	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * Whether a search of the REST API is being answered.
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Builds the service.
	 *
	 * @param Keys $keys The index of the tree.
	 */
	public function __construct( Keys $keys ) {
		$this->keys = $keys;
	}

	/**
	 * Starts when the search of the REST API queries the posts. Filter `rest_post_search_query`.
	 *
	 * @param mixed $args The arguments of the query: returned as they are.
	 * @return mixed
	 */
	public function begin( $args ) {
		$this->active = true;

		return $args;
	}

	/**
	 * Stops at the end of the request. Filter `rest_post_dispatch`.
	 *
	 * @param mixed $response The response: returned as it is.
	 * @return mixed
	 */
	public function end( $response ) {
		$this->active = false;

		return $response;
	}

	/**
	 * Adds the ancestors to the title of a page of the tree. Filter `the_title`.
	 *
	 * @param mixed $title The title.
	 * @param mixed $id    The post.
	 * @return mixed
	 */
	public function title( $title, $id = 0 ) {
		if ( ! $this->active || ! is_string( $title ) || (int) $id <= 0 ) {
			return $title;
		}

		$titles = $this->keys->path_of( (int) $id );

		if ( null === $titles || count( $titles ) < 2 ) {
			return $title;
		}

		array_pop( $titles );

		return $title . ' — ' . implode( ' / ', array_reverse( $titles ) );
	}
}
