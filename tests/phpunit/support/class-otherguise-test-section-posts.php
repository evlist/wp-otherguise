<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A double of the posts, for the tests of the sections.
 *
 * @package Otherguise
 */

use Otherguise\Books\Section\PostStore;

/**
 * Posts in memory: id => title, type, status, date.
 */
final class Otherguise_Test_Section_Posts implements PostStore {

	/**
	 * Posts by id.
	 *
	 * @var array<int, array{id:int,title:string,status:string,type:string,date:string,edit:string}>
	 */
	public $posts = array();

	/**
	 * Ids the user may edit; null means all.
	 *
	 * @var int[]|null
	 */
	public $editable = null;

	/**
	 * Ids the user may not read.
	 *
	 * @var int[]
	 */
	public $hidden = array();

	/**
	 * Calls of `prime()`.
	 *
	 * @var array
	 */
	public $primed = array();

	/**
	 * Adds a post and declares it to the stubs of WordPress.
	 *
	 * @param int    $id     Id.
	 * @param string $title  Title.
	 * @param string $date   Date.
	 * @param string $status Status.
	 * @param string $type   Type.
	 * @return void
	 */
	public function add( $id, $title, $date = '2026-05-01 10:00:00', $status = 'publish', $type = 'post' ) {
		$this->posts[ $id ] = array(
			'id'     => $id,
			'title'  => $title,
			'status' => $status,
			'type'   => $type,
			'date'   => $date,
			'edit'   => 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit',
		);

		$objects = $GLOBALS['otherguise_test_objects']['WP_Post'] ?? array();

		$objects[ $id ] = new WP_Post( $id, $type, $title );
		otherguise_test_wp_objects( array_values( $objects ) );
	}

	/**
	 * The post types.
	 *
	 * @return string[]
	 */
	public function types() {
		return array( 'post', 'page' );
	}

	/**
	 * Describes a post.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function info( $id ) {
		return $this->posts[ $id ] ?? null;
	}

	/**
	 * Whether the user may edit a post.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public function can_edit( $id ) {
		return null === $this->editable || in_array( $id, $this->editable, true );
	}

	/**
	 * Whether the user may read a post.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public function can_read( $id ) {
		return ! in_array( $id, $this->hidden, true );
	}

	/**
	 * Records the call.
	 *
	 * @param int[] $ids Ids.
	 * @return void
	 */
	public function prime( array $ids ) {
		$this->primed[] = $ids;
	}

	/**
	 * Searches among given posts by title, the most recently modified (here: the highest id) first.
	 *
	 * @param int[]  $ids      Ids.
	 * @param string $text     Text.
	 * @param int    $page     Page.
	 * @param int    $per_page Per page.
	 * @return array
	 */
	public function search_among( array $ids, $text, $page, $per_page ) {
		$ids = array_values(
			array_filter(
				$ids,
				fn( $id ) => isset( $this->posts[ $id ] ) && ( '' === $text || false !== stripos( $this->posts[ $id ]['title'], $text ) )
			)
		);
		rsort( $ids );

		return array(
			'ids'   => array_slice( $ids, ( $page - 1 ) * $per_page, $per_page ),
			'total' => count( $ids ),
		);
	}

	/**
	 * Searches the posts by title, dates and statuses, the oldest first.
	 *
	 * @param array $arguments Criteria.
	 * @return array
	 */
	public function candidates( array $arguments ) {
		$statuses = $arguments['statuses'] ?? array( 'publish' );
		$found    = array_values(
			array_filter(
				$this->posts,
				static function ( $post ) use ( $arguments, $statuses ) {
					return in_array( $post['status'], $statuses, true )
						&& ( '' === ( $arguments['search'] ?? '' ) || false !== stripos( $post['title'], $arguments['search'] ) )
						&& ( empty( $arguments['after'] ) || substr( $post['date'], 0, 10 ) >= $arguments['after'] )
						&& ( empty( $arguments['before'] ) || substr( $post['date'], 0, 10 ) <= $arguments['before'] );
				}
			)
		);
		usort( $found, static fn( $a, $b ) => array( $a['date'], $a['id'] ) <=> array( $b['date'], $b['id'] ) );
		$per_page = $arguments['per_page'] ?? 50;
		$page     = $arguments['page'] ?? 1;

		return array(
			'ids'   => array_column( array_slice( $found, ( $page - 1 ) * $per_page, $per_page ), 'id' ),
			'total' => count( $found ),
		);
	}
}
