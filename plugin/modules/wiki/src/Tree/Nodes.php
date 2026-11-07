<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The pages of WordPress, as the wiki reads and writes them.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * What the wiki needs from WordPress to read and write its pages (slice 502): a thin layer over the type `page`, so that the service `Pages`
 * can be tried with a double.
 */
class Nodes {
	/**
	 * Type of the pages.
	 */
	public const TYPE = 'page';

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
	 * Changes the parent and the title of a page.
	 *
	 * @param int    $id     Page.
	 * @param int    $parent The new parent (0 for the first level).
	 * @param string $title  The new title.
	 * @return bool False when WordPress could not save it.
	 */
	public function update( $id, $parent, $title ) {
		$done = wp_update_post(
			array(
				'ID'          => (int) $id,
				'post_parent' => (int) $parent,
				'post_title'  => (string) $title,
			),
			true
		);

		return is_int( $done ) && $done > 0;
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
	 * @return array{id: int, parent: int, title: string, status: string}|null Null when it is not a page.
	 */
	public function get( $id ) {
		$post = get_post( (int) $id );

		if ( ! is_object( $post ) || self::TYPE !== ( $post->post_type ?? '' ) ) {
			return null;
		}

		return array(
			'id'     => (int) $post->ID,
			'parent' => (int) $post->post_parent,
			'title'  => (string) $post->post_title,
			'status' => (string) $post->post_status,
		);
	}

	/**
	 * Reads a meta of a page.
	 *
	 * @param int    $id  Page.
	 * @param string $key Key.
	 * @return string
	 */
	public function meta( $id, $key ) {
		return (string) get_post_meta( (int) $id, (string) $key, true );
	}

	/**
	 * Writes a meta of a page.
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
	 * Every page, trashed ones included (a page in the trash keeps its keys), without the auto-drafts: the list that `Keys::rebuild()` takes.
	 *
	 * @return array<int, array{post: int, parent: int, title: string}>
	 */
	public function all() {
		$ids  = get_posts(
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
			$page = $this->get( (int) $id );

			if ( null !== $page ) {
				$list[] = array(
					'post'   => $page['id'],
					'parent' => $page['parent'],
					'title'  => $page['title'],
				);
			}
		}

		return $list;
	}
}
