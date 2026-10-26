<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the project service reads from WordPress.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Project;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the posts of the two types (slice 230) for `Projects`, the meta boxes and the commands. A post in the trash is ignored.
 */
final class WordPressProjects {
	/**
	 * The most posts of a type that are listed.
	 */
	public const LIMIT = 200;

	/**
	 * The stored projects: `id`, `title` and `meta`, oldest first.
	 *
	 * @return array<int, array{id:int, title:string, meta:mixed}>
	 */
	public function projects() {
		$rows = array();

		foreach ( $this->posts( PostTypes::PROJECT ) as $post ) {
			$rows[] = array(
				'id'    => (int) $post->ID,
				'title' => (string) $post->post_title,
				'meta'  => get_post_meta( (int) $post->ID, PostTypes::PROJECT_META, true ),
			);
		}

		return $rows;
	}

	/**
	 * The profiles: id and title, oldest first.
	 *
	 * @return array<int, string>
	 */
	public function profiles() {
		$titles = array();

		foreach ( $this->posts( PostTypes::PROFILE ) as $post ) {
			$titles[ (int) $post->ID ] = (string) $post->post_title;
		}

		return $titles;
	}

	/**
	 * What a profile post holds (an empty array for a profile with nothing saved yet), or null when the post is not a profile.
	 *
	 * @param int $id Id of the post.
	 * @return array|null
	 */
	public function profile_meta( $id ) {
		$post = get_post( (int) $id );

		if ( ! $post instanceof \WP_Post || PostTypes::PROFILE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}

		$meta = get_post_meta( (int) $id, PostTypes::PROFILE_META, true );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Creates a published post of one of the two types with its meta.
	 *
	 * @param string $type  `PostTypes::PROFILE` or `PostTypes::PROJECT`.
	 * @param string $title Title.
	 * @param array  $meta  What the meta holds (already normalized).
	 * @return int The id, 0 when the post could not be made.
	 */
	public function create( $type, $title, array $meta ) {
		$id = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		if ( ! is_int( $id ) || $id <= 0 ) {
			return 0;
		}

		update_post_meta( $id, PostTypes::PROFILE === $type ? PostTypes::PROFILE_META : PostTypes::PROJECT_META, $meta );

		return $id;
	}

	/**
	 * Replaces the meta of a project.
	 *
	 * @param int   $id   Id of the project.
	 * @param array $meta What the meta holds (already normalized).
	 * @return void
	 */
	public function update_project( $id, array $meta ) {
		update_post_meta( (int) $id, PostTypes::PROJECT_META, $meta );
	}

	/**
	 * Deletes every printer profile and book project, for good (uninstall).
	 *
	 * @return void
	 */
	public function delete_all() {
		foreach ( array( PostTypes::PROFILE, PostTypes::PROJECT ) as $type ) {
			$ids = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'any',
					'numberposts'      => -1,
					'fields'           => 'ids',
				)
			);

			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				wp_delete_post( (int) $id, true );
			}
		}
	}

	/**
	 * Posts of a type, oldest first.
	 *
	 * @param string $type Type.
	 * @return \WP_Post[]
	 */
	private function posts( $type ) {
		$posts = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => self::LIMIT,
				'orderby'          => 'ID',
				'order'            => 'ASC',
			)
		);

		return is_array( $posts ) ? $posts : array();
	}
}
