<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The relations of the book projects.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Project;

use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;

defined( 'ABSPATH' ) || exit;

/**
 * A book project is tied to its root section and to its printer profile by two relations of the Triples module (slice 230), like a section to
 * its posts: `books/root` (project → section) and `books/profile` (project → profile), one object each. They are found by the index of the
 * statements, never by scanning the posts, and Triples takes them away when a post is deleted.
 */
final class ProjectLinks {
	/**
	 * Predicate: the root section of a project.
	 */
	public const ROOT = 'books/root';

	/**
	 * Predicate: the printer profile of a project.
	 */
	public const PROFILE = 'books/profile';

	/**
	 * Gives the service of the statements.
	 *
	 * @var callable
	 */
	private $statements;

	/**
	 * Receives a post id; returns the sections above it (`Sections::ancestors()`).
	 *
	 * @var callable
	 */
	private $ancestors;

	/**
	 * Builds the service.
	 *
	 * @param callable $statements Returns the `Statements` service (resolved when needed: the Triples module may not be ready at boot).
	 * @param callable $ancestors  Receives a post id; returns the ids of the sections that contain it, directly or not.
	 */
	public function __construct( $statements, $ancestors ) {
		$this->statements = $statements;
		$this->ancestors  = $ancestors;
	}

	/**
	 * The root section of a project, 0 when it has none.
	 *
	 * @param int $project Id of the project.
	 * @return int
	 */
	public function root( $project ) {
		return $this->object( $project, self::ROOT );
	}

	/**
	 * The printer profile of a project, 0 when it has none.
	 *
	 * @param int $project Id of the project.
	 * @return int
	 */
	public function profile( $project ) {
		return $this->object( $project, self::PROFILE );
	}

	/**
	 * Sets the root section of a project; 0 takes it away.
	 *
	 * @param int $project Id of the project.
	 * @param int $section Id of the section.
	 * @return bool False when the relation was refused (a post that does not exist).
	 */
	public function set_root( $project, $section ) {
		return $this->set( $project, self::ROOT, $section );
	}

	/**
	 * Sets the printer profile of a project; 0 takes it away.
	 *
	 * @param int $project Id of the project.
	 * @param int $profile Id of the profile.
	 * @return bool False when the relation was refused (a post that does not exist).
	 */
	public function set_profile( $project, $profile ) {
		return $this->set( $project, self::PROFILE, $profile );
	}

	/**
	 * The projects whose root is a section (one lookup of the index of the statements).
	 *
	 * @param int $section Id of the section.
	 * @return int[] Ids, lowest first.
	 */
	public function projects_of( $section ) {
		$ids = array();

		foreach ( $this->statements()->subjects_of( Ref::post( (int) $section ), self::ROOT ) as $statement ) {
			$ids[] = (int) $statement->subject()->id();
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * The projects that cover a post: those whose root is the post or a section that contains it, directly or not.
	 *
	 * @param int $post Id of the post.
	 * @return int[] Ids, lowest first.
	 */
	public function covering( $post ) {
		$ids = array();

		foreach ( array_merge( array( (int) $post ), (array) ( $this->ancestors )( (int) $post ) ) as $section ) {
			$ids = array_merge( $ids, $this->projects_of( (int) $section ) );
		}

		$ids = array_values( array_unique( $ids ) );

		sort( $ids );

		return $ids;
	}

	/**
	 * The object of a relation of a project.
	 *
	 * @param int    $project   Id of the project.
	 * @param string $predicate Predicate.
	 * @return int
	 */
	private function object( $project, $predicate ) {
		foreach ( $this->statements()->objects_of( Ref::post( (int) $project ), $predicate ) as $statement ) {
			return (int) $statement->object()->id();
		}

		return 0;
	}

	/**
	 * Makes a post the only object of a relation of a project, or takes the relation away.
	 *
	 * @param int    $project   Id of the project.
	 * @param string $predicate Predicate.
	 * @param int    $target    Id of the post, 0 for none.
	 * @return bool
	 */
	private function set( $project, $predicate, $target ) {
		$subject = Ref::post( (int) $project );

		if ( (int) $target <= 0 ) {
			foreach ( $this->statements()->objects_of( $subject, $predicate ) as $statement ) {
				$this->statements()->delete( $statement );
			}

			return true;
		}

		try {
			$this->statements()->replace( $subject, $predicate, Ref::post( (int) $target ) );
		} catch ( \Exception $exception ) {
			return false;
		}

		return true;
	}

	/**
	 * The service of the statements.
	 *
	 * @return Statements
	 */
	private function statements() {
		return ( $this->statements )();
	}
}
