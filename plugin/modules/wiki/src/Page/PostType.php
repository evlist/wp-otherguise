<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The content type of the wiki pages.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Page;

use Otherguise\Wiki\Space\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * The type `wiki_page` (slice 502): not hierarchical, public, **no rewrite rule of its own** (the address is slice 503), in the block editor
 * and in REST, with the revisions and the custom fields that the Footnotes block of WordPress needs. Its capabilities are those of the pages,
 * unless the filter `wiki_capability_type` says otherwise. The meta `wiki_space` holds the space; it is **set once and cannot be changed**
 * afterwards (moving a page to another space changes its address: that is the tool of slice 505).
 */
final class PostType {
	/**
	 * Registers the type and the meta. Action `init`.
	 *
	 * @return void
	 */
	public function register() {
		$capability = apply_filters( 'wiki_capability_type', 'page' );

		register_post_type(
			PageStore::TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Wiki pages', 'otherguise' ),
					'singular_name'      => __( 'Wiki page', 'otherguise' ),
					'menu_name'          => __( 'Wiki', 'otherguise' ),
					'all_items'          => __( 'All pages', 'otherguise' ),
					'add_new'            => __( 'Add a page', 'otherguise' ),
					'add_new_item'       => __( 'Add a wiki page', 'otherguise' ),
					'edit_item'          => __( 'Edit the wiki page', 'otherguise' ),
					'new_item'           => __( 'New wiki page', 'otherguise' ),
					'view_item'          => __( 'View the wiki page', 'otherguise' ),
					'search_items'       => __( 'Search wiki pages', 'otherguise' ),
					'not_found'          => __( 'No wiki page found.', 'otherguise' ),
					'not_found_in_trash' => __( 'No wiki page in the trash.', 'otherguise' ),
				),
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_rest'        => true,
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capability_type'     => is_string( $capability ) && '' !== $capability ? $capability : 'page',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),
				'menu_position'       => 22,
				'menu_icon'           => 'dashicons-book-alt',
				'exclude_from_search' => false,
			)
		);

		register_post_meta(
			PageStore::TYPE,
			PageStore::SPACE_META,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_space' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					unset( $allowed, $meta_key );

					return current_user_can( 'edit_post', (int) $post_id );
				},
			)
		);
	}

	/**
	 * The value of the meta: the space as registered, or an empty string when there is no such space.
	 *
	 * @param mixed $value The value sent.
	 * @return string
	 */
	public function sanitize_space( $value ) {
		$canonical = Spaces::canonical( $value );

		return null === $canonical ? '' : $canonical;
	}

	/**
	 * Refuses to change the space of a page that has one, or to remove it. Filters `update_post_metadata` and `delete_post_metadata`.
	 *
	 * @param mixed  $check    Null to go on, anything else to stop.
	 * @param int    $id       Post.
	 * @param string $meta_key Key.
	 * @param mixed  $value    New value (the value to delete for `delete_post_metadata`).
	 * @return mixed
	 */
	public function lock_space( $check, $id, $meta_key = '', $value = '' ) {
		if ( PageStore::SPACE_META !== $meta_key || null !== $check ) {
			return $check;
		}

		$current = (string) get_post_meta( (int) $id, PageStore::SPACE_META, true );

		if ( '' === $current ) {
			return $check;
		}

		return is_string( $value ) && $this->sanitize_space( $value ) === $current ? $check : false;
	}
}
