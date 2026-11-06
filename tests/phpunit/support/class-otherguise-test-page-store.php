<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * An in-memory store of wiki pages, for the tests.
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Page\PageStore;

/**
 * The posts of the wiki kept in an array; a page that is inserted also exists for the stubs of WordPress (`get_post()`), so that Triples
 * accepts it as the subject of a statement.
 */
class Otherguise_Test_Page_Store extends PageStore {
	/**
	 * The pages: id, title, status, space and the other metas.
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

		$this->pages[ $id ] = array(
			'id'      => $id,
			'title'   => (string) ( $post['post_title'] ?? '' ),
			'content' => (string) ( $post['post_content'] ?? '' ),
			'status'  => (string) ( $post['post_status'] ?? 'draft' ),
			'meta'    => (array) ( $post['meta_input'] ?? array() ),
		);

		$GLOBALS['otherguise_test_objects']['WP_Post'][ $id ] = new WP_Post( $id, PageStore::TYPE, $this->pages[ $id ]['title'] );

		return $id;
	}

	/**
	 * Adds a page as WordPress would have it after a save.
	 *
	 * @param int    $id     Id.
	 * @param string $title  Title.
	 * @param string $space  Space.
	 * @param string $status Status.
	 * @return void
	 */
	public function add( $id, $title, $space, $status = 'publish' ) {
		$this->pages[ $id ]                                   = array(
			'id'      => $id,
			'title'   => $title,
			'content' => '',
			'status'  => $status,
			'meta'    => array( PageStore::SPACE_META => $space ),
		);
		$GLOBALS['otherguise_test_objects']['WP_Post'][ $id ] = new WP_Post( $id, PageStore::TYPE, $title );
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
	 * @return array{id: int, title: string, status: string, space: string}|null
	 */
	public function get( $id ) {
		if ( ! isset( $this->pages[ $id ] ) ) {
			return null;
		}

		return array(
			'id'     => (int) $id,
			'title'  => $this->pages[ $id ]['title'],
			'status' => $this->pages[ $id ]['status'],
			'space'  => (string) ( $this->pages[ $id ]['meta'][ PageStore::SPACE_META ] ?? '' ),
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
	 * Every page, as the list that `Names::rebuild()` takes.
	 *
	 * @return array<int, array{post: int, space: string, title: string}>
	 */
	public function all() {
		$list = array();

		foreach ( $this->pages as $id => $page ) {
			$list[] = array(
				'post'  => $id,
				'space' => (string) ( $page['meta'][ PageStore::SPACE_META ] ?? '' ),
				'title' => $page['title'],
			);
		}

		return $list;
	}
}
