<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The posts of the type of the wiki pages, as WordPress keeps them.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Page;

defined( 'ABSPATH' ) || exit;

/**
 * What the wiki needs from WordPress to read and write its pages: a thin layer, so that the service `Pages` can be tried with a double.
 */
class PageStore {
	/**
	 * Type of the pages.
	 */
	public const TYPE = 'wiki_page';

	/**
	 * Meta that holds the space of a page.
	 */
	public const SPACE_META = 'wiki_space';

	/**
	 * Meta that holds the template of a page (the one of the page editor of WordPress).
	 */
	public const TEMPLATE_META = '_wp_page_template';

	/**
	 * Inserts a page.
	 *
	 * @param array<string, mixed> $post Arguments of `wp_insert_post()`.
	 * @return int|\WP_Error The id, or the error.
	 */
	public function insert( array $post ) {
		return wp_insert_post( $post, true );
	}

	/**
	 * Deletes a page for good.
	 *
	 * @param int $id Page.
	 * @return void
	 */
	public function delete( $id ) {
		wp_delete_post( (int) $id, true );
	}

	/**
	 * Reads a page.
	 *
	 * @param int $id Id.
	 * @return array{id: int, title: string, status: string, space: string}|null Null when it is not a wiki page.
	 */
	public function get( $id ) {
		$post = get_post( (int) $id );

		if ( ! is_object( $post ) || self::TYPE !== ( $post->post_type ?? '' ) ) {
			return null;
		}

		return array(
			'id'     => (int) $post->ID,
			'title'  => (string) $post->post_title,
			'status' => (string) $post->post_status,
			'space'  => (string) get_post_meta( (int) $post->ID, self::SPACE_META, true ),
		);
	}

	/**
	 * Reads a meta.
	 *
	 * @param int    $id  Page.
	 * @param string $key Key.
	 * @return string
	 */
	public function meta( $id, $key ) {
		return (string) get_post_meta( (int) $id, (string) $key, true );
	}

	/**
	 * Writes a meta.
	 *
	 * @param int    $id    Page.
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @return void
	 */
	public function set_meta( $id, $key, $value ) {
		update_post_meta( (int) $id, (string) $key, (string) $value );
	}

	/**
	 * Changes the status of a page.
	 *
	 * @param int    $id     Page.
	 * @param string $status New status.
	 * @return void
	 */
	public function set_status( $id, $status ) {
		wp_update_post(
			array(
				'ID'          => (int) $id,
				'post_status' => (string) $status,
			)
		);
	}

	/**
	 * Every page, trashed ones included (a page in the trash keeps its names), without the auto-drafts: the list that `Names::rebuild()` takes.
	 *
	 * @return array<int, array{post: int, space: string, title: string}>
	 */
	public function all() {
		$ids = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$list = array();

		foreach ( $ids as $id ) {
			$list[] = array(
				'post'  => (int) $id,
				'space' => $this->meta( $id, self::SPACE_META ),
				'title' => (string) get_the_title( (int) $id ),
			);
		}

		return $list;
	}
}
