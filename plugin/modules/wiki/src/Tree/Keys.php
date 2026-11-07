<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The index of the tree: which page has which title under which parent.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;
use Otherguise\Wiki\NameException;

defined( 'ABSPATH' ) || exit;

/**
 * The index of the tree (slice 501), kept as **statements of Triples**: a literal is stored in a binary column (compared byte for byte,
 * whatever the collation of the database), indexed, and removed with the post. The identity of a page is the **key** `<id of the parent>/<title>`.
 *
 * - `wiki/key`: the current key, one per page, **one page per key**;
 * - `wiki/former-key`: an alias (the old key of a renamed or moved page), **one page per key**;
 * - `wiki/folded-key`: the key with the title in lower case and without accents, for the suggestions: many pages may share it;
 * - `wiki/title`: the title alone, **many pages for one title** (the same title elsewhere in the tree).
 *
 * A path is **walked one level at a time**: moving or renaming a page changes only its own key, the descendants follow, and the old path of a
 * descendant keeps resolving through the alias of its ancestor. Triples checks a limit **by counting before it inserts**: two saves of the
 * same key at the very same moment could both pass; the transaction narrows it and does not close it.
 */
final class Keys {
	public const KEY    = 'wiki/key';
	public const FORMER = 'wiki/former-key';
	public const FOLDED = 'wiki/folded-key';
	public const TITLE  = 'wiki/title';

	/**
	 * Returns the service of the statements; called when needed.
	 *
	 * @var callable
	 */
	private $statements;

	/**
	 * Tells whether a title of the first level would be served by something else first (slice 503); null for none.
	 *
	 * @var callable|null
	 */
	private $reserved;

	/**
	 * Builds the service.
	 *
	 * @param callable      $statements Returns the `Statements` service (the module Triples).
	 * @param callable|null $reserved   Receives a title of the first level, after the rules; returns true when a route serves it first.
	 */
	public function __construct( $statements, $reserved = null ) {
		$this->statements = $statements;
		$this->reserved   = $reserved;
	}

	/**
	 * Gives a title to a page under a parent: the former current key becomes an alias, a key that the page had as an alias comes back as its
	 * current one, and the title and the folded key are kept up to date. All or nothing (a transaction). A move or a rename is a claim with
	 * another parent or title: only the key of the page changes.
	 *
	 * @param int   $post_id   The page.
	 * @param int   $parent_id The parent (0 for the first level).
	 * @param mixed $title     The title as typed.
	 * @return array{key: string, parent: int, title: string, former: string|null}
	 * @throws NameException When the title is refused, a page is its own parent, a route serves the title first, or another page has the key
	 *                       **as a current key or as an alias** (the two predicates are checked here: `max_subjects_per_object` sees one at a time).
	 */
	public function claim( $post_id, $parent_id, $title ) {
		$post_id   = (int) $post_id;
		$parent_id = max( 0, (int) $parent_id );

		if ( $post_id > 0 && $parent_id === $post_id ) {
			NameException::refuse( NameException::BAD_PARENT );
		}

		$key   = Title::key( $parent_id, $title );
		$parts = Title::split( $key );

		if ( 0 === $parent_id && null !== $this->reserved && (bool) ( $this->reserved )( $parts['title'] ) ) {
			NameException::refuse( NameException::RESERVED );
		}

		return $this->statements()->transaction(
			function () use ( $post_id, $key, $parts ) {
				$holder = $this->holder( $key );

				if ( null !== $holder && $holder['post'] !== $post_id ) {
					NameException::refuse( NameException::TAKEN, $holder['post'], $holder['current'] );
				}

				$statements = $this->statements();
				$page       = Ref::post( $post_id );
				$current    = $this->current_of( $post_id );
				$former     = null;

				if ( null !== $current && $current['key'] !== $key ) {
					$statements->triple( $page, self::FORMER, $this->literal( $current['key'] ) );
					$former = $current['key'];
				}

				$statements->remove( $page, self::FORMER, $this->literal( $key ) );
				$statements->replace( $page, self::KEY, $this->literal( $key ) );
				$statements->replace( $page, self::TITLE, $this->literal( $parts['title'] ) );
				$this->fold( $page, $parts['parent'], $parts['title'] );

				return array(
					'key'    => $key,
					'parent' => $parts['parent'],
					'title'  => $parts['title'],
					'former' => $former,
				);
			}
		);
	}

	/**
	 * Finds the page that has a title under a parent, as a current key or as an alias. One indexed query, then the aliases.
	 *
	 * @param int   $parent_id The parent (0 for the first level).
	 * @param mixed $title     The title as typed.
	 * @return array{post: int, current: bool}|null Null when no page has it, or when the title is not a name.
	 */
	public function resolve( $parent_id, $title ) {
		try {
			$key = Title::key( $parent_id, $title );
		} catch ( NameException $refusal ) {
			return null;
		}

		return $this->holder( $key );
	}

	/**
	 * Walks a path of titles one level at a time from the first level.
	 *
	 * `exact` is false when an alias was used at some level: the address has to be redirected to the current one. When a level is missing
	 * `post` is null, `deepest` is the last page that exists (0 when even the first title is missing) and `rest` the titles that were not found.
	 *
	 * @param string[] $titles The titles as typed, from the first level.
	 * @return array{post: int|null, exact: bool, deepest: int, rest: string[]}
	 */
	public function resolve_path( array $titles ) {
		$parent = 0;
		$exact  = true;
		$titles = array_values( $titles );

		foreach ( $titles as $index => $title ) {
			$found = $this->resolve( $parent, $title );

			if ( null === $found ) {
				return array(
					'post'    => null,
					'exact'   => $exact,
					'deepest' => $parent,
					'rest'    => array_slice( $titles, $index ),
				);
			}

			$exact  = $exact && $found['current'];
			$parent = $found['post'];
		}

		return array(
			'post'    => array() === $titles ? null : $parent,
			'exact'   => $exact,
			'deepest' => $parent,
			'rest'    => array(),
		);
	}

	/**
	 * Adds a key that the page had (or that an import brings) as an alias.
	 *
	 * @param int   $post_id   The page.
	 * @param int   $parent_id The parent the page had.
	 * @param mixed $title     The title the page had.
	 * @return bool True when the alias was added, false when the page already has the key (current or alias).
	 * @throws NameException When the title is refused or another page holds the key.
	 */
	public function add_alias( $post_id, $parent_id, $title ) {
		$post_id = (int) $post_id;
		$key     = Title::key( $parent_id, $title );

		return $this->statements()->transaction(
			function () use ( $post_id, $key ) {
				$holder = $this->holder( $key );

				if ( null !== $holder ) {
					if ( $holder['post'] !== $post_id ) {
						NameException::refuse( NameException::TAKEN, $holder['post'], $holder['current'] );
					}

					return false;
				}

				$this->statements()->triple( Ref::post( $post_id ), self::FORMER, $this->literal( $key ) );

				return true;
			}
		);
	}

	/**
	 * The current key of a page, cut into the parent and the title.
	 *
	 * @param int $post_id The page.
	 * @return array{parent: int, title: string, key: string}|null
	 */
	public function current_of( $post_id ) {
		foreach ( $this->statements()->objects_of( Ref::post( (int) $post_id ), self::KEY ) as $statement ) {
			return Title::split( $statement->object()->key() );
		}

		return null;
	}

	/**
	 * The titles from the first level to a page, read from the keys alone (no call to WordPress).
	 *
	 * @param int $post_id The page.
	 * @return string[]|null Null when the page or one of its ancestors has no key yet.
	 */
	public function path_of( $post_id ) {
		$titles = array();
		$id     = (int) $post_id;

		for ( $depth = 0; $depth < 64; $depth++ ) {
			$current = $this->current_of( $id );

			if ( null === $current ) {
				return null;
			}

			array_unshift( $titles, $current['title'] );

			if ( 0 === $current['parent'] ) {
				return $titles;
			}

			$id = $current['parent'];
		}

		return null;
	}

	/**
	 * The aliases of a page.
	 *
	 * @param int $post_id The page.
	 * @return string[] Keys.
	 */
	public function aliases_of( $post_id ) {
		$keys = array();

		foreach ( $this->statements()->objects_of( Ref::post( (int) $post_id ), self::FORMER ) as $statement ) {
			$keys[] = $statement->object()->key();
		}

		sort( $keys );

		return $keys;
	}

	/**
	 * The siblings whose folded key is the one of this title, **except the page that has exactly the key**: the suggestions of the page that
	 * does not exist, such as the same title without its accents or in another case.
	 *
	 * @param int   $parent_id The parent.
	 * @param mixed $title     The title as typed.
	 * @return array<int, array{post: int, title: string}> By title.
	 */
	public function similar( $parent_id, $title ) {
		try {
			$key    = Title::key( $parent_id, $title );
			$parts  = Title::split( $key );
			$folded = Title::join( $parts['parent'], Title::folded( $parts['title'] ) );
		} catch ( NameException $refusal ) {
			return array();
		}

		$found = array();

		foreach ( $this->statements()->subjects_of( $this->literal( $folded ), self::FOLDED ) as $statement ) {
			$post    = (int) $statement->subject()->id();
			$current = $this->current_of( $post );

			if ( null !== $current && $current['key'] !== $key ) {
				$found[] = array(
					'post'  => $post,
					'title' => $current['title'],
				);
			}
		}

		usort( $found, static fn( $a, $b ) => $a['title'] <=> $b['title'] );

		return $found;
	}

	/**
	 * The pages that have the same title **anywhere** in the tree (the angles), bounded.
	 *
	 * @param mixed $title       The title as typed.
	 * @param int   $except_post A page not to list (the one that is shown), 0 for none.
	 * @param int   $limit       At most this many pages.
	 * @return array<int, array{post: int, parent: int, title: string}> By page id.
	 */
	public function elsewhere( $title, $except_post = 0, $limit = 50 ) {
		try {
			$title = Title::title( $title );
		} catch ( NameException $refusal ) {
			return array();
		}

		$found = array();

		foreach ( $this->statements()->subjects_of( $this->literal( $title ), self::TITLE ) as $statement ) {
			$post = (int) $statement->subject()->id();

			if ( $post === (int) $except_post ) {
				continue;
			}

			$current = $this->current_of( $post );

			if ( null !== $current ) {
				$found[ $post ] = array(
					'post'   => $post,
					'parent' => $current['parent'],
					'title'  => $current['title'],
				);
			}
		}

		ksort( $found );

		return array_slice( array_values( $found ), 0, max( 0, (int) $limit ) );
	}

	/**
	 * Makes the current keys again from a list of pages: the statements `wiki/key`, `wiki/title` and `wiki/folded-key` are deleted and made
	 * again, and **the aliases are kept** (they exist nowhere else). The list is read from the posts by the caller (slice 502): the service
	 * does not read posts.
	 *
	 * @param iterable<int, array{post: int, parent: int, title: mixed}> $pages The pages.
	 * @return array{done: int, refused: array<int, array{post: int, code: string, holder: int}>} The number of keys made, and the pages that
	 *         were refused with the reason (a bad title, a key that an alias of another page already holds).
	 */
	public function rebuild( $pages ) {
		return $this->statements()->transaction(
			function () use ( $pages ) {
				$statements = $this->statements();

				foreach ( array( self::KEY, self::TITLE, self::FOLDED ) as $predicate ) {
					foreach ( $statements->match( null, $predicate ) as $statement ) {
						$statements->delete( $statement );
					}
				}

				$report = array(
					'done'    => 0,
					'refused' => array(),
				);

				foreach ( $pages as $page ) {
					try {
						$this->claim( $page['post'], $page['parent'], $page['title'] );
						++$report['done'];
					} catch ( NameException $refusal ) {
						$report['refused'][] = array(
							'post'   => (int) $page['post'],
							'code'   => $refusal->error_code(),
							'holder' => $refusal->holder(),
						);
					}
				}

				return $report;
			}
		);
	}

	/**
	 * The statements of Triples.
	 *
	 * @return Statements
	 */
	private function statements() {
		return ( $this->statements )();
	}

	/**
	 * A literal of the datatype `string`.
	 *
	 * @param string $value Value.
	 * @return Literal
	 */
	private function literal( $value ) {
		return new Literal( 'string', $value );
	}

	/**
	 * Finds the page that holds a key, as a current key first and then as an alias.
	 *
	 * @param string $key Key, already put through the rules.
	 * @return array{post: int, current: bool}|null
	 */
	private function holder( $key ) {
		$literal = $this->literal( $key );

		foreach ( $this->statements()->subjects_of( $literal, self::KEY ) as $statement ) {
			return array(
				'post'    => (int) $statement->subject()->id(),
				'current' => true,
			);
		}

		foreach ( $this->statements()->subjects_of( $literal, self::FORMER ) as $statement ) {
			return array(
				'post'    => (int) $statement->subject()->id(),
				'current' => false,
			);
		}

		return null;
	}

	/**
	 * Keeps the folded key of a page up to date. A folded key that does not fit a literal (more than 191 bytes: a few letters get longer in
	 * lower case) is not stored, and the page has no suggestions.
	 *
	 * @param EntityRef $page      The page.
	 * @param int       $parent_id Its parent.
	 * @param string    $title     Its title, put through the rules.
	 * @return void
	 */
	private function fold( $page, $parent_id, $title ) {
		$statements = $this->statements();
		$folded     = (int) $parent_id . '/' . Title::folded( $title );

		if ( strlen( $folded ) > Title::MAX_BYTES ) {
			foreach ( $statements->objects_of( $page, self::FOLDED ) as $statement ) {
				$statements->delete( $statement );
			}

			return;
		}

		$statements->replace( $page, self::FOLDED, $this->literal( $folded ) );
	}
}
