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
 * projects, their relations and the profiles come from callables and from `ProjectLinks`, so that it is tested without a site.
 */
final class Projects {
	/**
	 * Returns the stored projects: a list of `id`, `title` and `meta` (what the post meta holds: the author and the folios).
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
	 * The relations of the projects.
	 *
	 * @var object
	 */
	private $links;

	/**
	 * Builds the service.
	 *
	 * @param callable $list         Returns the stored projects.
	 * @param callable $profile_meta Receives a profile id; returns its meta or null.
	 * @param object   $links        The relations (`ProjectLinks`): `root()`, `profile()` and `covering()`.
	 */
	public function __construct( $list, $profile_meta, $links ) {
		$this->list         = $list;
		$this->profile_meta = $profile_meta;
		$this->links        = $links;
	}

	/**
	 * Keeps the valid values of what a project holds in its meta: the author and the switch of the folios (on by default). The root section
	 * and the profile are relations, not meta.
	 *
	 * @param mixed $input What the post meta holds.
	 * @return array{author:string, folios:bool}
	 */
	public static function normalize( $input ) {
		$in = is_array( $input ) ? $input : array();

		return array(
			'author' => isset( $in['author'] ) && is_string( $in['author'] ) ? mb_substr( trim( $in['author'] ), 0, 200 ) : '',
			'folios' => ! array_key_exists( 'folios', $in ) || ! in_array( $in['folios'], array( false, 0, '0', '', 'off' ), true ),
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
					'id'      => $id,
					'title'   => (string) ( $row['title'] ?? '' ),
					'root'    => (int) $this->links->root( $id ),
					'profile' => (int) $this->links->profile( $id ),
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
	 * The projects that cover a post, with their profile and the CSS they give to the page, lowest id first: those whose root is the post or
	 * a section that contains it, directly or not. A project whose profile is missing gives no rules and is left out.
	 *
	 * @param int $post_id The post.
	 * @return array<int, array{project:array, profile:array<string,mixed>, css:string}> By project id.
	 */
	public function covering( $post_id ) {
		$all    = $this->all();
		$covers = array();

		foreach ( (array) $this->links->covering( (int) $post_id ) as $id ) {
			if ( ! isset( $all[ $id ] ) || $all[ $id ]['profile'] <= 0 ) {
				continue;
			}

			$profile = $this->profile( $all[ $id ]['profile'] );

			if ( null !== $profile ) {
				$covers[ (int) $id ] = array(
					'project' => $all[ $id ],
					'profile' => $profile,
					'css'     => PageRules::css( $profile, $all[ $id ]['folios'] ),
				);
			}
		}

		ksort( $covers );

		return $covers;
	}

	/**
	 * The project that applies to a post and its profile: the one asked for when it covers the post, else the first one (the lowest id).
	 * The filter `books_project_for_post` can choose another one among those that cover it.
	 *
	 * @param int $post_id   The post.
	 * @param int $requested Id of a project asked for (0 for none).
	 * @return array{project:array, profile:array<string,mixed>, css:string}|null Null when no project covers the post.
	 */
	public function for_post( $post_id, $requested = 0 ) {
		$covers = $this->covering( (int) $post_id );

		if ( array() === $covers ) {
			return null;
		}

		$chosen = isset( $covers[ (int) $requested ] ) ? (int) $requested : (int) array_key_first( $covers );
		$chosen = (int) apply_filters( 'books_project_for_post', $chosen, (int) $post_id, array_keys( $covers ) );

		return $covers[ isset( $covers[ $chosen ] ) ? $chosen : (int) array_key_first( $covers ) ];
	}
}
