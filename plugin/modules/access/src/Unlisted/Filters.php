<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What leaves the unlisted posts out of the lists.
 *
 * @package Otherguise
 */

namespace Otherguise\Access\Unlisted;

defined( 'ABSPATH' ) || exit;

/**
 * The filters of the unlisted state (slice 402). They are registered only when the setting is on.
 *
 * - **The queries that are not the lookup of one post** (`pre_get_posts`): the home page, the archives, the search, the feeds, the Query
 *   Loop blocks and widgets of the theme, the public REST listing. A query is left alone when it asks for a post by id, name or path
 *   (`p`, `page_id`, `name`, `pagename`, `attachment_id`), when it gives `post__in`, in the administration, for a user of the editor in a
 *   REST request (the panels of the editor must find them), and when it carries `access_include_unlisted` (for the code that needs them).
 * - **The sitemaps**, **the previous and next links**, **the robots** of the page itself, and a mark in the list of the administration.
 *
 * Not covered: a plugin that reads the database without the filters of WordPress, and the feed of the comments.
 */
final class Filters {
	/**
	 * Query variable that keeps a query from leaving the unlisted posts out.
	 */
	public const INCLUDE = 'access_include_unlisted';

	/**
	 * The state.
	 *
	 * @var Unlisted
	 */
	private $unlisted;

	/**
	 * Tells whether the request is one of the administration or of the editor: `is_admin() || (REST and can edit)`.
	 *
	 * @var callable
	 */
	private $privileged;

	/**
	 * Returns the id of the post of the page shown, 0 when the page is not a single post.
	 *
	 * @var callable
	 */
	private $shown;

	/**
	 * Builds the filters.
	 *
	 * @param Unlisted      $unlisted   The state.
	 * @param callable|null $privileged Default: the administration, or a REST request of a user who may edit posts.
	 * @param callable|null $shown      Default: the queried object when the page is a single post.
	 */
	public function __construct( Unlisted $unlisted, $privileged = null, $shown = null ) {
		$this->unlisted   = $unlisted;
		$this->shown      = $shown ?? static function () {
			return is_singular() ? (int) get_queried_object_id() : 0;
		};
		$this->privileged = $privileged ?? static function () {
			return is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' ) );
		};
	}

	/**
	 * Leaves the unlisted posts out of a query. Action `pre_get_posts`.
	 *
	 * @param object $query The query.
	 * @return void
	 */
	public function pre_get_posts( $query ) {
		if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) || ! method_exists( $query, 'set' ) || $this->is_lookup( $query ) || (bool) ( $this->privileged )() ) {
			return;
		}

		$ids = $this->unlisted->ids();

		if ( array() === $ids ) {
			return;
		}

		$query->set( 'post__not_in', $this->merge( $query->get( 'post__not_in' ), $ids ) );
	}

	/**
	 * Leaves them out of the sitemaps. Filter `wp_sitemaps_posts_query_args`.
	 *
	 * @param mixed $arguments Arguments of the query.
	 * @return mixed
	 */
	public function sitemap_arguments( $arguments ) {
		if ( ! is_array( $arguments ) ) {
			return $arguments;
		}

		$arguments['post__not_in'] = $this->merge( $arguments['post__not_in'] ?? array(), $this->unlisted->ids() );

		return $arguments;
	}

	/**
	 * Leaves them out of the previous and next links. Filters `get_previous_post_where` and `get_next_post_where`.
	 *
	 * @param mixed $where The clause WHERE.
	 * @return mixed
	 */
	public function adjacent_where( $where ) {
		$ids = $this->unlisted->ids();

		if ( ! is_string( $where ) || array() === $ids ) {
			return $where;
		}

		return $where . ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
	}

	/**
	 * Asks the search engines not to index an unlisted post. Filter `wp_robots`.
	 *
	 * @param mixed $robots Directives.
	 * @return mixed
	 */
	public function robots( $robots ) {
		if ( ! is_array( $robots ) || ! $this->unlisted->is_unlisted( (int) ( $this->shown )() ) ) {
			return $robots;
		}

		$robots['noindex'] = true;

		return $robots;
	}

	/**
	 * Marks the unlisted posts in the list of the administration. Filter `display_post_states`.
	 *
	 * @param mixed $states States.
	 * @param mixed $post   The post.
	 * @return mixed
	 */
	public function post_states( $states, $post = null ) {
		if ( is_array( $states ) && is_object( $post ) && isset( $post->ID ) && $this->unlisted->is_unlisted( (int) $post->ID ) ) {
			$states['access_unlisted'] = __( 'Unlisted', 'otherguise' );
		}

		return $states;
	}

	/**
	 * Tells whether a query asks for one post by its identity.
	 *
	 * @param object $query The query.
	 * @return bool
	 */
	private function is_lookup( $query ) {
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $key ) {
			if ( (int) $query->get( $key ) > 0 ) {
				return true;
			}
		}

		foreach ( array( 'name', 'pagename', 'attachment', self::INCLUDE ) as $key ) {
			if ( '' !== (string) $query->get( $key ) ) {
				return true;
			}
		}

		return array() !== array_filter( (array) $query->get( 'post__in' ) );
	}

	/**
	 * Adds the ids to a list of excluded ids.
	 *
	 * @param mixed $current Ids already excluded.
	 * @param int[] $ids     Ids to add.
	 * @return int[]
	 */
	private function merge( $current, array $ids ) {
		return array_values( array_unique( array_filter( array_merge( array_map( 'intval', (array) $current ), $ids ) ) ) );
	}
}
