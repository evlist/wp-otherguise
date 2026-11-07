<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * An in-memory store of pages, for the tests of the hierarchical model.
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Tree\Nodes;

/**
 * The pages kept in an array; a page that is inserted also exists for the stubs of WordPress (`get_post()`), so that Triples accepts it as
 * the subject of a statement.
 */
class Otherguise_Test_Node_Store extends Nodes {
	/**
	 * The pages: id, parent, title, status, content.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $pages = array();

	/**
	 * Ids of the pages deleted.
	 *
	 * @var int[]
	 */
	public $deleted = array();

	/**
	 * Next id.
	 *
	 * @var int
	 */
	private $next = 100;

	/**
	 * Inserts a page.
	 *
	 * @param array<string, mixed> $post Arguments of `wp_insert_post()`.
	 * @return int
	 */
	public function insert( array $post ) {
		$id = $this->next++;

		$this->add( $id, (string) ( $post['post_title'] ?? '' ), (int) ( $post['post_parent'] ?? 0 ), (string) ( $post['post_status'] ?? 'draft' ) );
		$this->pages[ $id ]['content'] = (string) ( $post['post_content'] ?? '' );

		return $id;
	}

	/**
	 * Adds a page as WordPress would have it after a save.
	 *
	 * @param int    $id     Id.
	 * @param string $title  Title.
	 * @param int    $parent Parent.
	 * @param string $status Status.
	 * @return void
	 */
	public function add( $id, $title, $parent = 0, $status = 'publish' ) {
		$this->pages[ $id ]                                   = array(
			'id'      => $id,
			'parent'  => $parent,
			'title'   => $title,
			'status'  => $status,
			'content' => '',
		);
		$GLOBALS['otherguise_test_objects']['WP_Post'][ $id ] = new WP_Post( $id, Nodes::TYPE, $title );
	}

	/**
	 * Deletes a page.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public function delete( $id ) {
		$this->deleted[] = (int) $id;
		unset( $this->pages[ $id ], $GLOBALS['otherguise_test_objects']['WP_Post'][ $id ] );
	}

	/**
	 * Reads a page.
	 *
	 * @param int $id Id.
	 * @return array{id: int, parent: int, title: string, status: string}|null
	 */
	public function get( $id ) {
		if ( ! isset( $this->pages[ $id ] ) ) {
			return null;
		}

		return array(
			'id'     => (int) $id,
			'parent' => (int) $this->pages[ $id ]['parent'],
			'title'  => (string) $this->pages[ $id ]['title'],
			'status' => (string) $this->pages[ $id ]['status'],
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
		return (string) ( $this->pages[ $id ]['meta'][ $key ] ?? '' );
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
		$this->pages[ $id ]['meta'][ $key ] = $value;
	}

	/**
	 * Changes the status of a page.
	 *
	 * @param int    $id     Page.
	 * @param string $status Status.
	 * @return void
	 */
	public function set_status( $id, $status ) {
		$this->pages[ $id ]['status'] = $status;
	}

	/**
	 * Every page, as the list that `Keys::rebuild()` takes.
	 *
	 * @return array<int, array{post: int, parent: int, title: string}>
	 */
	public function all() {
		$list = array();

		foreach ( $this->pages as $id => $page ) {
			$list[] = array(
				'post'   => $id,
				'parent' => (int) $page['parent'],
				'title'  => (string) $page['title'],
			);
		}

		return $list;
	}
}
