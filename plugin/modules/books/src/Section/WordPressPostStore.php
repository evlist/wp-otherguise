<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The posts of WordPress, for the sections.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the posts and searches them with `WP_Query` (slice 223). The sections are given by id (the statements of Triples know which
 * posts are sections), so no query on the meta table is needed.
 *
 * With `$trusted` (WP-CLI, which has no logged-in user) the capabilities are not asked.
 */
final class WordPressPostStore implements PostStore {
	/**
	 * Whether the capabilities are skipped (a command line).
	 *
	 * @var bool
	 */
	private $trusted;

	/**
	 * Builds the store.
	 *
	 * @param bool $trusted True for WP-CLI.
	 */
	public function __construct( $trusted = false ) {
		$this->trusted = (bool) $trusted;
	}

	/**
	 * The post types that can be a section (filter `books_section_post_types`, `post` and `page` by default).
	 *
	 * @return string[]
	 */
	public function types() {
		$types = apply_filters( 'books_section_post_types', array( 'post', 'page' ) );

		return is_array( $types ) ? array_values( array_filter( $types, 'is_string' ) ) : array( 'post', 'page' );
	}

	/**
	 * Describes a post.
	 *
	 * @param int  $id       Id of the post.
	 * @param bool $detailed Whether to add `view`.
	 * @return array{id:int,title:string,status:string,type:string,date:string,edit:string,view?:string}|null
	 */
	public function info( $id, $detailed = false ) {
		$post = get_post( (int) $id );

		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			return null;
		}

		$title = trim( wp_strip_all_tags( (string) $post->post_title ) );
		$edit  = get_edit_post_link( (int) $post->ID, 'raw' );

		$info = array(
			'id'     => (int) $post->ID,
			'title'  => '' === $title ? __( '(no title)', 'otherguise' ) : $title,
			'status' => (string) $post->post_status,
			'type'   => (string) $post->post_type,
			'date'   => (string) $post->post_date,
			'edit'   => is_string( $edit ) ? $edit : '',
		);

		if ( $detailed ) {
			$view = 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post );

			$info['view'] = is_string( $view ) ? $view : '';
		}

		return $info;
	}

	/**
	 * Whether the user may edit a post.
	 *
	 * @param int $id Id of the post.
	 * @return bool
	 */
	public function can_edit( $id ) {
		return $this->trusted || current_user_can( 'edit_post', (int) $id );
	}

	/**
	 * Whether the user may read a post.
	 *
	 * @param int $id Id of the post.
	 * @return bool
	 */
	public function can_read( $id ) {
		return $this->trusted || current_user_can( 'read_post', (int) $id );
	}

	/**
	 * Loads several posts in one query.
	 *
	 * @param int[] $ids Ids.
	 * @return void
	 */
	public function prime( array $ids ) {
		if ( array() !== $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, false );
		}
	}

	/**
	 * The addresses of the featured images. Another plugin that makes the sizes on demand (Media Helper does) makes the size `medium` of
	 * a photo here when it does not exist yet, so this is asked a few posts at a time and never in the request that lists the section.
	 *
	 * @param int[] $ids Ids of the posts.
	 * @return array<int,string>
	 */
	public function thumbs( array $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( array() === $ids ) {
			return array();
		}

		// The metas of the posts (the id of the featured image), then the attachments with theirs (the sizes): two queries, not one per post.
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, true );
		}

		$by_post     = array();
		$attachments = array();

		foreach ( $ids as $id ) {
			$thumb_id = (int) get_post_thumbnail_id( $id );

			$by_post[ $id ] = $thumb_id;

			if ( $thumb_id > 0 ) {
				$attachments[ $thumb_id ] = $thumb_id;
			}
		}

		if ( array() !== $attachments && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( $attachments ), false, true );
		}

		$urls = array();

		foreach ( $by_post as $id => $thumb_id ) {
			$url        = $thumb_id > 0 ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : false;
			$urls[ $id ] = is_string( $url ) ? $url : '';
		}

		return $urls;
	}

	/**
	 * Searches among given posts, the most recently modified first.
	 *
	 * @param int[]  $ids      Ids of the posts to search among.
	 * @param string $text     Text.
	 * @param int    $page     Page.
	 * @param int    $per_page Results per page.
	 * @return array{ids:int[],total:int}
	 */
	public function search_among( array $ids, $text, $page, $per_page ) {
		if ( array() === $ids ) {
			return array(
				'ids'   => array(),
				'total' => 0,
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'           => $this->types(),
				'post_status'         => 'any',
				'post__in'            => array_map( 'intval', $ids ),
				's'                   => $text,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
			)
		);

		return array(
			'ids'   => array_map( 'intval', (array) $query->posts ),
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Searches the posts that can be added to a section, the oldest first.
	 *
	 * @param array $arguments Criteria: `search`, `after`, `before`, `statuses`, `page`, `per_page`.
	 * @return array{ids:int[],total:int}
	 */
	public function candidates( array $arguments ) {
		$allowed  = array( 'publish', 'draft', 'pending', 'private', 'future' );
		$statuses = array_values( array_intersect( (array) ( $arguments['statuses'] ?? array( 'publish' ) ), $allowed ) );
		$query    = array(
			'post_type'           => $this->types(),
			'post_status'         => array() === $statuses ? array( 'publish' ) : $statuses,
			's'                   => (string) ( $arguments['search'] ?? '' ),
			'orderby'             => 'date',
			'order'               => 'ASC',
			'posts_per_page'      => min( 50, max( 1, (int) ( $arguments['per_page'] ?? 50 ) ) ),
			'paged'               => max( 1, (int) ( $arguments['page'] ?? 1 ) ),
			'fields'              => 'ids',
			'ignore_sticky_posts' => true,
		);

		$range = array();

		if ( ! empty( $arguments['after'] ) ) {
			$range['after'] = (string) $arguments['after'];
		}

		if ( ! empty( $arguments['before'] ) ) {
			$range['before'] = (string) $arguments['before'];
		}

		if ( array() !== $range ) {
			$query['date_query'] = array( $range + array( 'inclusive' => true ) );
		}

		$found = new \WP_Query( $query );

		return array(
			'ids'   => array_map( 'intval', (array) $found->posts ),
			'total' => (int) $found->found_posts,
		);
	}
}
