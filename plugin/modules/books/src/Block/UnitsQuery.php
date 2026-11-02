<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The Query Loop of WordPress that lists the units of a section.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Block;

use Otherguise\Books\Section\Sections;

defined( 'ABSPATH' ) || exit;

/**
 * A Query Loop (`core/query`) whose `query` attribute holds `books_units` lists the units of the section of the page, in the order of the
 * section, instead of what its other settings say (slice 235). The variation "Units of the section" of the editor (`edit.js` of the block
 * `books/section-units`) makes such a loop; the theme puts what it wants inside (title, image, excerpt, pagination...).
 *
 * The filter changes the query of the template of the loop (`core/post-template`), which receives the `query` attribute in its context: the
 * name of a variation does not reach it, a key inside the attribute does (tried on 2026-10-25, `docs/design/books.md`).
 *
 * Only the published posts without a password. A unit that is **unlisted** (slice 402) **is shown**: a query by `post__in` is left alone by the
 * filters of the unlisted state, and a section wants its unlisted units.
 *
 * Coexistence: the filter has the default priority 10 and only touches a loop that carries the key.
 */
final class UnitsQuery {
	/**
	 * Key of the `query` attribute that marks the loop.
	 */
	public const KEY = 'books_units';

	/**
	 * The sections.
	 *
	 * @var Sections
	 */
	private $sections;

	/**
	 * Returns the id of the post the page shows.
	 *
	 * @var callable
	 */
	private $queried;

	/**
	 * Builds the filter.
	 *
	 * @param Sections      $sections The sections.
	 * @param callable|null $queried  Id of the post the page shows; defaults to `get_queried_object_id()`.
	 */
	public function __construct( Sections $sections, $queried = null ) {
		$this->sections = $sections;
		$this->queried  = $queried ?? static fn() => (int) get_queried_object_id();
	}

	/**
	 * Replaces the query of a loop that carries the key. Filter `query_loop_block_query_vars`.
	 *
	 * @param array          $query Arguments of `WP_Query`.
	 * @param \WP_Block|null $block The block `core/post-template`.
	 * @return array
	 */
	public function filter( $query, $block = null ) {
		$loop = $block instanceof \WP_Block && is_array( $block->context['query'] ?? null ) ? $block->context['query'] : array();

		if ( ! is_array( $query ) || empty( $loop[ self::KEY ] ) ) {
			return $query;
		}

		$section = absint( $block->context['postId'] ?? 0 );
		$section = $section > 0 ? $section : (int) ( $this->queried )();
		$ids     = $section > 0 ? $this->sections->order( $section ) : array();

		// What the settings of the loop asked for (categories, author, search, sticky posts) does not apply to the units of a section.
		foreach ( array( 'tax_query', 'author', 'author__in', 's', 'post__not_in', 'category__in', 'tag__in', 'post_parent', 'meta_query', 'date_query' ) as $key ) {
			unset( $query[ $key ] );
		}

		$query['post__in']            = array() === $ids ? array( 0 ) : array_map( 'intval', $ids );
		$query['orderby']             = 'post__in';
		$query['post_type']           = 'any';
		$query['post_status']         = 'publish';
		$query['has_password']        = false;
		$query['ignore_sticky_posts'] = true;

		return $query;
	}
}
