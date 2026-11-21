<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Answers the module of the index: which posts a section holds.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Section;

defined( 'ABSPATH' ) || exit;

/**
 * The filter `index_section_posts` (slice 610): the module of the index asks for the posts of a section and knows nothing else of Books.
 * An answer that is already there wins.
 */
final class SectionPostsFilter {
	/**
	 * Returns the sections: `fn( bool $trusted ): Sections`.
	 *
	 * @var callable
	 */
	private $sections;

	/**
	 * Builds the filter.
	 *
	 * @param callable $sections Returns the sections; `true` for a command line, which has no logged-in user.
	 */
	public function __construct( $sections ) {
		$this->sections = $sections;
	}

	/**
	 * The posts of a section, flattened, in the order of the book.
	 *
	 * @param mixed $ids     The posts found so far.
	 * @param int   $section The section.
	 * @return mixed
	 */
	public function filter( $ids, $section ) {
		if ( is_array( $ids ) && array() !== $ids ) {
			return $ids;
		}

		$sections = ( $this->sections )( defined( 'WP_CLI' ) && WP_CLI );

		return ( new SectionPosts( array( $sections, 'order' ) ) )->ids( (int) $section );
	}
}
