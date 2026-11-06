<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The names of the pages: which page has which name.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;
use Otherguise\Wiki\Space\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * The index of the names (slice 501), kept as **statements of Triples**, not in a table of its own: a literal is stored in a binary column
 * (compared byte for byte, whatever the collation of the database), indexed, and removed with the post. The identity of a page is the pair
 * (space, exact title), written `Space:Title`.
 *
 * - `wiki/name`: the current qualified name, one per page, **one page per name**;
 * - `wiki/former-name`: an alias (a name the page had, or that an import brings), **one page per name**: its address redirects (slice 503);
 * - `wiki/folded-name`: the name without accents or case, for the suggestions: many pages may share it;
 * - `wiki/space`: the name of the space of the page.
 *
 * Triples checks a limit **by counting before it inserts**: two saves of the same name at the very same moment could both pass. The
 * transaction narrows it and does not close it; a named lock around `claim()` would, if a wiki ever has several authors.
 */
final class Names {
	public const NAME   = 'wiki/name';
	public const FORMER = 'wiki/former-name';
	public const FOLDED = 'wiki/folded-name';
	public const SPACE  = 'wiki/space';

	/**
	 * Returns the service of the statements; called when needed.
	 *
	 * @var callable
	 */
	private $statements;

	/**
	 * Returns the registered form of a space, or null.
	 *
	 * @var callable
	 */
	private $space;

	/**
	 * Builds the service.
	 *
	 * @param callable      $statements Returns the `Statements` service (the module Triples).
	 * @param callable|null $space      Receives a name of space and returns its registered form or null; defaults to `Spaces::canonical()`.
	 */
	public function __construct( $statements, $space = null ) {
		$this->statements = $statements;
		$this->space      = $space ?? array( Spaces::class, 'canonical' );
	}

	/**
	 * Puts a space and a title through the rules.
	 *
	 * @param mixed $space The space, in any case.
	 * @param mixed $title The title as typed.
	 * @return array{space: string, title: string, qualified: string}
	 * @throws NameException When the space does not exist or the title is refused.
	 */
	public function qualify( $space, $title ) {
		$canonical = ( $this->space )( $space );

		if ( ! is_string( $canonical ) || '' === $canonical ) {
			NameException::refuse( NameException::UNKNOWN_SPACE );
		}

		return Name::split( Name::qualified( $canonical, $title ) );
	}

	/**
	 * Gives a name to a page: the page's former current name becomes an alias, a name that the page had as an alias comes back as its
	 * current one, and the space and the folded name are kept up to date. All or nothing (a transaction).
	 *
	 * @param int   $post_id The page.
	 * @param mixed $space   The space.
	 * @param mixed $title   The title.
	 * @return array{name: string, space: string, title: string, former: string|null} The name, and the former name when it changed.
	 * @throws NameException When the name is refused, or another page holds it as its current name **or as an alias** (the two predicates
	 *                       are checked here, because `max_subjects_per_object` only sees one at a time).
	 */
	public function claim( $post_id, $space, $title ) {
		$post_id = (int) $post_id;
		$parts   = $this->qualify( $space, $title );

		return $this->statements()->transaction(
			function () use ( $post_id, $parts ) {
				$holder = $this->holder( $parts['qualified'] );

				if ( null !== $holder && $holder['post'] !== $post_id ) {
					NameException::refuse( NameException::TAKEN, $holder['post'], $holder['current'] );
				}

				$statements = $this->statements();
				$page       = Ref::post( $post_id );
				$current    = $this->current_of( $post_id );
				$former     = null;

				if ( null !== $current && $current['qualified'] !== $parts['qualified'] ) {
					$statements->triple( $page, self::FORMER, new Literal( 'string', $current['qualified'] ) );
					$former = $current['qualified'];
				}

				$statements->remove( $page, self::FORMER, new Literal( 'string', $parts['qualified'] ) );
				$statements->replace( $page, self::NAME, new Literal( 'string', $parts['qualified'] ) );
				$statements->replace( $page, self::SPACE, new Literal( 'string', $parts['space'] ) );
				$this->fold( $page, $parts['qualified'] );

				return array(
					'name'   => $parts['qualified'],
					'space'  => $parts['space'],
					'title'  => $parts['title'],
					'former' => $former,
				);
			}
		);
	}

	/**
	 * Finds the page that has a name, as a current name or as an alias. One indexed query, then the aliases.
	 *
	 * @param mixed $space The space.
	 * @param mixed $title The title.
	 * @return array{post: int, current: bool}|null Null when no page has it, or when the title is not a name.
	 */
	public function resolve( $space, $title ) {
		try {
			$parts = $this->qualify( $space, $title );
		} catch ( NameException $refusal ) {
			return null;
		}

		return $this->holder( $parts['qualified'] );
	}

	/**
	 * Adds a name that the page had (or that an import brings) as an alias.
	 *
	 * @param int   $post_id The page.
	 * @param mixed $space   The space.
	 * @param mixed $title   The title.
	 * @return bool True when the alias was added, false when the page already has the name (current or alias): nothing to add.
	 * @throws NameException When the name is refused or another page holds it.
	 */
	public function add_alias( $post_id, $space, $title ) {
		$post_id = (int) $post_id;
		$parts   = $this->qualify( $space, $title );

		return $this->statements()->transaction(
			function () use ( $post_id, $parts ) {
				$holder = $this->holder( $parts['qualified'] );

				if ( null !== $holder ) {
					if ( $holder['post'] !== $post_id ) {
						NameException::refuse( NameException::TAKEN, $holder['post'], $holder['current'] );
					}

					return false;
				}

				$this->statements()->triple( Ref::post( $post_id ), self::FORMER, new Literal( 'string', $parts['qualified'] ) );

				return true;
			}
		);
	}

	/**
	 * The current name of a page, cut at its first colon.
	 *
	 * @param int $post_id The page.
	 * @return array{space: string, title: string, qualified: string}|null
	 */
	public function current_of( $post_id ) {
		foreach ( $this->statements()->objects_of( Ref::post( (int) $post_id ), self::NAME ) as $statement ) {
			return Name::split( $statement->object()->key() );
		}

		return null;
	}

	/**
	 * The aliases of a page.
	 *
	 * @param int $post_id The page.
	 * @return string[] Qualified names.
	 */
	public function aliases_of( $post_id ) {
		$names = array();

		foreach ( $this->statements()->objects_of( Ref::post( (int) $post_id ), self::FORMER ) as $statement ) {
			$names[] = $statement->object()->key();
		}

		sort( $names );

		return $names;
	}

	/**
	 * The pages of the same space whose folded name is the one of this name, **except the page that has exactly this name**: the suggestions
	 * of the page that does not exist (slice 504), such as the same title without its accents or in another case.
	 *
	 * @param mixed $space The space.
	 * @param mixed $title The title.
	 * @return array<int, array{post: int, name: string}> By page, with its current name.
	 */
	public function similar( $space, $title ) {
		try {
			$parts = $this->qualify( $space, $title );
		} catch ( NameException $refusal ) {
			return array();
		}

		$found = array();

		foreach ( $this->statements()->subjects_of( $this->literal( Name::folded( $parts['qualified'] ) ), self::FOLDED ) as $statement ) {
			$post    = (int) $statement->subject()->id();
			$current = $this->current_of( $post );

			if ( null !== $current && $current['qualified'] !== $parts['qualified'] ) {
				$found[] = array(
					'post' => $post,
					'name' => $current['qualified'],
				);
			}
		}

		usort( $found, static fn( $a, $b ) => $a['name'] <=> $b['name'] );

		return $found;
	}

	/**
	 * The pages that have the same title in the other spaces (the angles of slice 507): there are few spaces, one lookup each.
	 *
	 * @param mixed  $title        The title.
	 * @param string $except_space The space not to look in, in any case; empty to look in all.
	 * @return array<int, array{post: int, name: string, current: bool}> By space, in the order of the spaces.
	 */
	public function elsewhere( $title, $except_space = '' ) {
		$except = '' === $except_space ? null : ( $this->space )( $except_space );
		$found  = array();

		foreach ( Spaces::all() as $space ) {
			if ( $space['name'] === $except ) {
				continue;
			}

			$page = $this->resolve( $space['name'], $title );

			if ( null !== $page ) {
				$found[] = array(
					'post'    => $page['post'],
					'name'    => $this->qualify( $space['name'], $title )['qualified'],
					'current' => $page['current'],
				);
			}
		}

		return $found;
	}

	/**
	 * The pages of a space.
	 *
	 * @param mixed $space The space, in any case.
	 * @return int[] Page ids, by current name.
	 */
	public function pages_of( $space ) {
		$canonical = ( $this->space )( $space );

		if ( ! is_string( $canonical ) ) {
			return array();
		}

		$pages = array();

		foreach ( $this->statements()->subjects_of( $this->literal( $canonical ), self::SPACE ) as $statement ) {
			$post    = (int) $statement->subject()->id();
			$current = $this->current_of( $post );

			$pages[ $post ] = null === $current ? '' : $current['qualified'];
		}

		asort( $pages );

		return array_keys( $pages );
	}

	/**
	 * Makes the current names again from a list of pages: the statements `wiki/name`, `wiki/space` and `wiki/folded-name` are deleted and
	 * made again, and **the aliases are kept** (they exist nowhere else, and this is the one thing a rebuild could not give back). The list is
	 * given by the slice that knows where a title and a space are stored (slice 502): the service does not read posts.
	 *
	 * @param iterable<int, array{post: int, space: mixed, title: mixed}> $pages The pages.
	 * @return array{done: int, refused: array<int, array{post: int, code: string, holder: int}>} The number of names made, and the pages that
	 *         were refused with the reason (a bad title, an unknown space, a name that an alias of another page already holds).
	 */
	public function rebuild( $pages ) {
		return $this->statements()->transaction(
			function () use ( $pages ) {
				$statements = $this->statements();

				foreach ( array( self::NAME, self::SPACE, self::FOLDED ) as $predicate ) {
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
						$this->claim( $page['post'], $page['space'], $page['title'] );
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
	 * Finds the page that holds a qualified name, as a current name first and then as an alias.
	 *
	 * @param string $qualified Qualified name, already put through the rules.
	 * @return array{post: int, current: bool}|null
	 */
	private function holder( $qualified ) {
		$literal = $this->literal( $qualified );

		foreach ( $this->statements()->subjects_of( $literal, self::NAME ) as $statement ) {
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
	 * Keeps the folded name of a page up to date. A folded name that does not fit a literal (more than 191 bytes: a few letters get longer
	 * in lower case) is not stored, and the page has no suggestions.
	 *
	 * @param EntityRef $page      The page.
	 * @param string    $qualified Its qualified name.
	 * @return void
	 */
	private function fold( $page, $qualified ) {
		$folded     = Name::folded( $qualified );
		$statements = $this->statements();

		if ( strlen( $folded ) > Name::MAX_BYTES ) {
			foreach ( $statements->objects_of( $page, self::FOLDED ) as $statement ) {
				$statements->delete( $statement );
			}

			return;
		}

		$statements->replace( $page, self::FOLDED, $this->literal( $folded ) );
	}
}
