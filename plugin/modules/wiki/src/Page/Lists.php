<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The list of the wiki pages in the administration.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Page;

defined( 'ABSPATH' ) || exit;

/**
 * The list of the pages (slice 502): the space and the name instead of the author and the number of comments, and no quick edit (it would
 * change the title, which is a rename, without the checks).
 */
final class Lists {
	/**
	 * Returns the current name of a page, as an array with `qualified`, or null.
	 *
	 * @var callable
	 */
	private $name_of;

	/**
	 * Builds the list.
	 *
	 * @param callable $name_of Receives a page id; returns its current name (`Names::current_of()`) or null.
	 */
	public function __construct( $name_of ) {
		$this->name_of = $name_of;
	}

	/**
	 * The columns. Filter `manage_wiki_page_posts_columns`.
	 *
	 * @param mixed $columns The columns.
	 * @return array<string, string>
	 */
	public function columns( $columns ) {
		return array(
			'cb'         => is_array( $columns ) && isset( $columns['cb'] ) ? (string) $columns['cb'] : '<input type="checkbox" />',
			'title'      => __( 'Title', 'otherguise' ),
			'wiki_space' => __( 'Space', 'otherguise' ),
			'wiki_name'  => __( 'Name', 'otherguise' ),
			'date'       => is_array( $columns ) && isset( $columns['date'] ) ? (string) $columns['date'] : __( 'Date', 'otherguise' ),
		);
	}

	/**
	 * Prints a cell. Action `manage_wiki_page_posts_custom_column`.
	 *
	 * @param string $column Column.
	 * @param int    $id     Page.
	 * @return void
	 */
	public function column( $column, $id ) {
		if ( 'wiki_space' === $column ) {
			echo esc_html( (string) get_post_meta( (int) $id, PageStore::SPACE_META, true ) );
		} elseif ( 'wiki_name' === $column ) {
			$name = ( $this->name_of )( (int) $id );

			echo null === $name ? '&mdash;' : esc_html( $name['qualified'] );
		}
	}

	/**
	 * Takes the quick edit away from a row. Filter `post_row_actions`.
	 *
	 * @param mixed $actions The actions of the row.
	 * @param mixed $post    The post.
	 * @return mixed
	 */
	public function row_actions( $actions, $post ) {
		if ( is_array( $actions ) && is_object( $post ) && PageStore::TYPE === ( $post->post_type ?? '' ) ) {
			unset( $actions['inline hide-if-no-js'] );
		}

		return $actions;
	}
}
