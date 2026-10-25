<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The post metas of the links, in WordPress.
 *
 * @package Otherguise
 */

namespace Otherguise\Access\Link;

defined( 'ABSPATH' ) || exit;

/**
 * Where the tokens are kept: protected post metas (a key that starts with `_` is not shown among the custom fields and is not in the REST
 * API unless it is registered, which it is not). The tests use another object with the same methods.
 */
final class PostMeta {
	/**
	 * Reads a meta.
	 *
	 * @param int    $id  Id of the post.
	 * @param string $key Key.
	 * @return string An empty string when there is none.
	 */
	public function get( $id, $key ) {
		$value = get_post_meta( (int) $id, $key, true );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Writes a meta.
	 *
	 * @param int    $id    Id of the post.
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @return void
	 */
	public function set( $id, $key, $value ) {
		update_post_meta( (int) $id, $key, $value );
	}

	/**
	 * Deletes a meta.
	 *
	 * @param int    $id  Id of the post.
	 * @param string $key Key.
	 * @return void
	 */
	public function delete( $id, $key ) {
		delete_post_meta( (int) $id, $key );
	}

	/**
	 * The ids of the posts that have a meta, with a value or not (trash and automatic drafts left out).
	 *
	 * @param string      $key   Key.
	 * @param string|null $value Value, or null for any.
	 * @return int[]
	 */
	public function find( $key, $value = null ) {
		$arguments = array(
			'post_type'        => 'any',
			'post_status'      => 'any',
			'meta_key'         => $key,
			'fields'           => 'ids',
			'posts_per_page'   => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
		);

		if ( null !== $value ) {
			$arguments['meta_value'] = $value;
		}

		return array_map( 'intval', get_posts( $arguments ) );
	}
}
