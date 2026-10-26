<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The book projects and the profile that applies to a post.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Project;

defined( 'ABSPATH' ) || exit;

/**
 * Ties a book project (a top-level section and a printer profile) to the posts it covers (slice 230). It knows nothing of WordPress: the
 * projects, the profiles and the sections come from three callables, so that it is tested without a site.
 */
final class Projects {
	/**
	 * Returns the stored projects: a list of `id`, `title` and `meta` (what the post meta holds).
	 *
	 * @var callable
	 */
	private $list;

	/**
	 * Receives the id of a printer profile; returns what its meta holds, or null when it is not a profile.
	 *
	 * @var callable
	 */
	private $profile_meta;

	/**
	 * Receives a section and a post; tells whether the post can be reached from the section.
	 *
	 * @var callable
	 */
	private $reaches;

	/**
	 * Builds the service.
	 *
	 * @param callable $list         Returns the stored projects.
	 * @param callable $profile_meta Receives a profile id; returns its meta or null.
	 * @param callable $reaches      Receives a section id and a post id; tells whether the section reaches the post.
	 */
	public function __construct( $list, $profile_meta, $reaches ) {
		$this->list         = $list;
		$this->profile_meta = $profile_meta;
		$this->reaches      = $reaches;
	}

	/**
	 * Keeps the valid values of a project: the root section, the profile, the author and the switch of the folios (on by default).
	 *
	 * @param mixed $input What the post meta holds.
	 * @return array{root:int, profile:int, author:string, folios:bool}
	 */
	public static function normalize( $input ) {
		$in = is_array( $input ) ? $input : array();

		return array(
			'root'    => isset( $in['root'] ) && is_numeric( $in['root'] ) ? max( 0, (int) $in['root'] ) : 0,
			'profile' => isset( $in['profile'] ) && is_numeric( $in['profile'] ) ? max( 0, (int) $in['profile'] ) : 0,
			'author'  => isset( $in['author'] ) && is_string( $in['author'] ) ? mb_substr( trim( $in['author'] ), 0, 200 ) : '',
			'folios'  => ! array_key_exists( 'folios', $in ) || ! in_array( $in['folios'], array( false, 0, '0', '', 'off' ), true ),
		);
	}

	/**
	 * The normalized profile of a printer profile post, or null.
	 *
	 * @param int $id Id of the profile.
	 * @return array<string, mixed>|null
	 */
	public function profile( $id ) {
		$meta = $id > 0 ? ( $this->profile_meta )( (int) $id ) : null;

		return null === $meta ? null : PrinterProfile::normalize( $meta );
	}

	/**
	 * Every project, by id.
	 *
	 * @return array<int, array{id:int, title:string, root:int, profile:int, author:string, folios:bool}>
	 */
	public function all() {
		$projects = array();

		foreach ( (array) ( $this->list )() as $row ) {
			$id              = (int) ( $row['id'] ?? 0 );
			$projects[ $id ] = array_merge(
				array(
					'id'    => $id,
					'title' => (string) ( $row['title'] ?? '' ),
				),
				self::normalize( $row['meta'] ?? array() )
			);
		}

		ksort( $projects );

		return $projects;
	}

	/**
	 * A project by id, or null.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( $id ) {
		$all = $this->all();

		return $all[ (int) $id ] ?? null;
	}

	/**
	 * The project that applies to a post and its profile: the one asked for when it covers the post, else the first one (the lowest id) whose
	 * root is the post or reaches it. The filter `books_project_for_post` can choose another one.
	 *
	 * @param int $post_id   The post.
	 * @param int $requested Id of a project asked for (0 for none).
	 * @return array{project:array, profile:array<string,mixed>, css:string}|null Null when no project covers the post, or when its profile is missing.
	 */
	public function for_post( $post_id, $requested = 0 ) {
		$post_id  = (int) $post_id;
		$projects = $this->all();
		$covers   = array();

		foreach ( $projects as $id => $project ) {
			if ( $project['root'] > 0 && $project['profile'] > 0 && ( $project['root'] === $post_id || ( $this->reaches )( $project['root'], $post_id ) ) ) {
				$profile = $this->profile( $project['profile'] );

				// A project whose profile is missing gives no rules: another project that covers the post is used.
				if ( null !== $profile ) {
					$covers[ $id ] = array(
						'project' => $project,
						'profile' => $profile,
					);
				}
			}
		}

		if ( array() === $covers ) {
			return null;
		}

		$chosen = isset( $covers[ (int) $requested ] ) ? (int) $requested : (int) array_key_first( $covers );
		$chosen = (int) apply_filters( 'books_project_for_post', $chosen, $post_id, array_keys( $covers ) );
		$chosen = isset( $covers[ $chosen ] ) ? $chosen : (int) array_key_first( $covers );

		return array(
			'project' => $covers[ $chosen ]['project'],
			'profile' => $covers[ $chosen ]['profile'],
			'css'     => PageRules::css( $covers[ $chosen ]['profile'], $covers[ $chosen ]['project']['folios'] ),
		);
	}
}
