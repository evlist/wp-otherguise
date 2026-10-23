<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Sections: posts that contain other posts.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Section;

use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\Statement;

defined( 'ABSPATH' ) || exit;

/**
 * A section is a post (or page) that is marked as one (a statement `books/is-section`, found by the index of the objects, so the sections are never looked for by scanning the posts) and holds an ordered list of posts through `books/contains` (slice 223).
 *
 * The order is the date of the post, oldest first; a post that was moved has a position (`triples/position` on its statement) that
 * overrides it. Every change checks the capabilities and the rules here, not in the callers: the browser is never trusted.
 */
final class Sections {
	public const PREDICATE  = 'books/contains';
	public const MARK       = 'books/is-section';
	public const POSITION   = 'triples/position';
	public const MAX_SEARCH = 1000;
	public const MAX_BATCH  = 200;

	/**
	 * Gives the service of the statements.
	 *
	 * @var callable
	 */
	private $statements;

	/**
	 * The posts.
	 *
	 * @var PostStore
	 */
	private $posts;

	/**
	 * Builds the service.
	 *
	 * @param callable  $statements Gives the `Statements` service (it is looked for when it is needed).
	 * @param PostStore $posts      The posts.
	 */
	public function __construct( $statements, PostStore $posts ) {
		$this->statements = $statements;
		$this->posts      = $posts;
	}

	/**
	 * Tells whether a post is a section.
	 *
	 * @param int $id Id of the post.
	 * @return bool
	 */
	public function is_section( $id ) {
		return null !== $this->statements()->find_by_triple( Ref::post( (int) $id ), self::MARK, $this->mark_value() );
	}

	/**
	 * Marks a post as a section.
	 *
	 * @param int $id Id of the post.
	 * @return void
	 * @throws SectionException When the post does not exist, is of another type, or the user may not edit it.
	 */
	public function mark( $id ) {
		$this->editable( (int) $id );
		$this->statements()->triple( Ref::post( (int) $id ), self::MARK, true );
	}

	/**
	 * Unmarks a section: refused while it contains posts.
	 *
	 * @param int $id Id of the section.
	 * @return void
	 * @throws SectionException When the user may not edit it or it is not empty.
	 */
	public function unmark( $id ) {
		$id = (int) $id;

		$this->editable( $id );

		if ( array() !== $this->statements()->objects_of( Ref::post( $id ), self::PREDICATE ) ) {
			SectionException::refuse( SectionException::NOT_EMPTY );
		}

		$this->statements()->remove( Ref::post( $id ), self::MARK, $this->mark_value() );
	}

	/**
	 * The ids of the posts of a section, in order.
	 *
	 * @param int $id Id of the section.
	 * @return int[]
	 */
	public function order( $id ) {
		return array_map( static fn( array $entry ) => $entry['id'], $this->entries( (int) $id ) );
	}

	/**
	 * The posts of a section, in order, with their statement and their pinned rank (null when none).
	 *
	 * @param int $id Id of the section.
	 * @return array<int, array{id:int,statement:Statement,pinned:?int}>
	 */
	public function entries( $id ) {
		$statements = $this->statements();
		$subject    = Ref::post( (int) $id );
		$all        = $statements->objects_of( $subject, self::PREDICATE );

		if ( array() === $all ) {
			return array();
		}

		$ids = array_map( static fn( Statement $statement ) => (int) $statement->object()->id(), $all );
		$this->posts->prime( $ids );

		$dates = array();

		foreach ( $ids as $post_id ) {
			$info              = $this->posts->info( $post_id );
			$dates[ $post_id ] = null === $info ? '' : $info['date'];
		}

		$compare = static function ( Statement $a, Statement $b ) use ( $dates ) {
			$left  = (int) $a->object()->id();
			$right = (int) $b->object()->id();

			return array( $dates[ $left ], $left ) <=> array( $dates[ $right ], $right );
		};

		$ordered = $statements->listing( $subject, self::PREDICATE, array( 'natural_order' => $compare ) );
		$pins    = $this->pins( $ordered );
		$entries = array();

		foreach ( $ordered as $statement ) {
			$entries[] = array(
				'id'        => (int) $statement->object()->id(),
				'statement' => $statement,
				'pinned'    => $pins[ $statement->id() ] ?? null,
			);
		}

		return $entries;
	}

	/**
	 * The ids of the sections that contain a post (an indexed lookup of the statements, never a scan of the posts).
	 *
	 * @param int $id Id of the post.
	 * @return int[]
	 */
	public function containing( $id ) {
		$ids = array();

		foreach ( $this->statements()->subjects_of( Ref::post( (int) $id ), self::PREDICATE ) as $statement ) {
			$ids[] = (int) $statement->subject()->id();
		}

		return $ids;
	}

	/**
	 * Adds posts at the end of a section (they appear at their date unless moved). Marks the section when it is not.
	 *
	 * @param int   $section Id of the section.
	 * @param array $ids     Ids of the posts, at most `MAX_BATCH`.
	 * @return array{added:int[],already:int[],skipped:array<int,string>} The skipped ones with the reason: `missing`, `type`,
	 *               `forbidden` or `cycle`.
	 * @throws SectionException When the section is refused or the batch is too large.
	 */
	public function add( $section, array $ids ) {
		$section = (int) $section;
		$this->editable( $section );

		$wanted = array();

		foreach ( $ids as $id ) {
			if ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) {
				if ( (int) $id > 0 ) {
					$wanted[ (int) $id ] = (int) $id;
				}
			}
		}

		$wanted = array_values( $wanted );

		if ( count( $wanted ) > self::MAX_BATCH ) {
			SectionException::refuse( SectionException::TOO_MANY );
		}

		$report = array(
			'added'   => array(),
			'already' => array(),
			'skipped' => array(),
		);

		if ( array() === $wanted ) {
			return $report;
		}

		$this->posts->prime( $wanted );
		$statements = $this->statements();
		$types      = $this->posts->types();
		$subject    = Ref::post( $section );

		$statements->transaction(
			function () use ( $wanted, $section, $statements, $types, $subject, &$report ) {
				foreach ( $wanted as $id ) {
					$info = $this->posts->info( $id );

					if ( null === $info ) {
						$report['skipped'][ $id ] = 'missing';
					} elseif ( ! in_array( $info['type'], $types, true ) ) {
						$report['skipped'][ $id ] = 'type';
					} elseif ( ! $this->posts->can_read( $id ) ) {
						$report['skipped'][ $id ] = 'forbidden';
					} elseif ( $id === $section || $this->reaches( $id, $section ) ) {
						$report['skipped'][ $id ] = 'cycle';
					} elseif ( null !== $statements->find_by_triple( $subject, self::PREDICATE, Ref::post( $id ) ) ) {
						$report['already'][] = $id;
					} else {
						$statements->triple( $subject, self::PREDICATE, Ref::post( $id ) );
						$report['added'][] = $id;
					}
				}
			}
		);

		if ( array() !== $report['added'] ) {
			$statements->triple( $subject, self::MARK, true );
		}

		return $report;
	}

	/**
	 * Takes a post out of a section.
	 *
	 * @param int $section Id of the section.
	 * @param int $post    Id of the post.
	 * @return bool Whether it was in the section.
	 * @throws SectionException When the user may not edit the section.
	 */
	public function remove( $section, $post ) {
		$this->editable( (int) $section );

		return $this->detach( (int) $section, (int) $post );
	}

	/**
	 * Takes a post that is about to be deleted out of every section that contains it, as the user who deletes it (no capability is asked),
	 * so that the positions of the other posts stay right. Action `before_delete_post`.
	 *
	 * @param int $post Id of the post.
	 * @return void
	 */
	public function forget_post( $post ) {
		foreach ( $this->containing( (int) $post ) as $section ) {
			$this->detach( $section, (int) $post );
		}
	}

	/**
	 * Takes a post out of a section and repairs the positions: a position is a rank, so that when a post leaves, the posts that had one
	 * are given the rank they now have, and the order that the user sees is the old order without the post.
	 *
	 * @param int $section Id of the section.
	 * @param int $post    Id of the post.
	 * @return bool Whether it was in the section.
	 */
	private function detach( $section, $post ) {
		$kept  = array();
		$found = false;

		foreach ( $this->entries( $section ) as $entry ) {
			if ( $entry['id'] === $post ) {
				$found = true;
			} else {
				$kept[] = $entry;
			}
		}

		if ( ! $found ) {
			return false;
		}

		$statements = $this->statements();

		$statements->transaction(
			function () use ( $statements, $section, $post, $kept ) {
				$statements->remove( Ref::post( $section ), self::PREDICATE, Ref::post( $post ) );

				foreach ( $kept as $index => $entry ) {
					if ( null !== $entry['pinned'] && $entry['pinned'] !== $index + 1 ) {
						$statements->replace( $entry['statement'], self::POSITION, $index + 1 );
					}
				}
			}
		);

		return true;
	}

	/**
	 * Moves a post in the order of its section by giving positions.
	 *
	 * `up` and `down` exchange the post with its neighbour, an integer is a rank from 1. Every post whose rank changes gets a position (the
	 * posts of the range between the old and the new rank): the result is exact whatever the other positions are, and the posts outside
	 * the range keep their place; a post added later has no position and goes to the end of the free ranks.
	 *
	 * @param int        $section Id of the section.
	 * @param int        $post    Id of the post.
	 * @param string|int $how     `up`, `down` or a rank.
	 * @return int[] The new order.
	 * @throws SectionException When the post is not in the section or the move is impossible.
	 */
	public function move( $section, $post, $how ) {
		$section = (int) $section;
		$post    = (int) $post;

		$this->editable( $section );

		$entries = $this->entries( $section );
		$index   = null;

		foreach ( $entries as $position => $entry ) {
			if ( $entry['id'] === $post ) {
				$index = $position;
			}
		}

		if ( null === $index ) {
			SectionException::refuse( SectionException::NOT_IN );
		}

		$count = count( $entries );

		if ( 'up' === $how ) {
			$target = $index - 1;
		} elseif ( 'down' === $how ) {
			$target = $index + 1;
		} elseif ( is_int( $how ) || ( is_string( $how ) && ctype_digit( $how ) ) ) {
			$target = (int) $how - 1;
		} else {
			$target = -1;
		}

		if ( $target < 0 || $target >= $count ) {
			SectionException::refuse( SectionException::BAD_MOVE );
		}

		if ( $target === $index ) {
			return $this->order( $section );
		}

		$moved = $entries[ $index ];
		$rest  = $entries;

		array_splice( $rest, $index, 1 );
		array_splice( $rest, $target, 0, array( $moved ) );

		$statements = $this->statements();
		$low        = min( $index, $target );
		$high       = max( $index, $target );

		$statements->transaction(
			function () use ( $statements, $rest, $low, $high ) {
				for ( $position = $low; $position <= $high; $position++ ) {
					$statements->replace( $rest[ $position ]['statement'], self::POSITION, $position + 1 );
				}
			}
		);

		return $this->order( $section );
	}

	/**
	 * Removes every position of a section: the order is the date again.
	 *
	 * @param int $section Id of the section.
	 * @return int Number of positions removed.
	 * @throws SectionException When the user may not edit the section.
	 */
	public function reset_order( $section ) {
		$section = (int) $section;

		$this->editable( $section );

		$statements = $this->statements();
		$removed    = 0;

		$statements->transaction(
			function () use ( $statements, $section, &$removed ) {
				foreach ( $this->entries( $section ) as $entry ) {
					if ( null !== $entry['pinned'] ) {
						foreach ( $statements->objects_of( $entry['statement'], self::POSITION ) as $position ) {
							$removed += $statements->delete( $position );
						}
					}
				}
			}
		);

		return $removed;
	}

	/**
	 * Describes a post for a screen or a command.
	 *
	 * @param int $id Id of the post.
	 * @return array{id:int,title:string,status:string,type:string,date:string,edit:string}|null
	 */
	public function describe( $id ) {
		return $this->posts->info( (int) $id );
	}

	/**
	 * Whether a post can be reached by following `contains` from another one (to refuse a cycle).
	 *
	 * @param int $from   Starting post.
	 * @param int $target Post looked for.
	 * @return bool
	 */
	public function reaches( $from, $target ) {
		$statements = $this->statements();
		$seen       = array( (int) $from => true );
		$queue      = array( (int) $from );

		$visited = 1;

		while ( array() !== $queue && $visited < 5000 ) {
			$current = array_shift( $queue );

			foreach ( $statements->objects_of( Ref::post( $current ), self::PREDICATE ) as $statement ) {
				$child = (int) $statement->object()->id();

				if ( $child === (int) $target ) {
					return true;
				}

				if ( ! isset( $seen[ $child ] ) ) {
					$seen[ $child ] = true;
					$queue[]        = $child;
					++$visited;
				}
			}
		}

		return false;
	}

	/**
	 * The state of a post for the panel: whether it is a section, what it contains in order, and the sections that contain it.
	 *
	 * @param int $id Id of the post.
	 * @return array
	 */
	public function state( $id ) {
		$id       = (int) $id;
		$contents = array();

		foreach ( $this->entries( $id ) as $entry ) {
			$info = $this->posts->info( $entry['id'] );

			if ( null !== $info ) {
				$contents[] = $info + array( 'pinned' => $entry['pinned'] );
			}
		}

		$containing = array();

		foreach ( $this->containing( $id ) as $section ) {
			$info = $this->posts->info( $section );

			if ( null !== $info && $this->posts->can_read( $section ) ) {
				$containing[] = $info + array( 'can_edit' => $this->posts->can_edit( $section ) );
			}
		}

		return array(
			'id'         => $id,
			'section'    => $this->is_section( $id ) || array() !== $contents,
			'contents'   => $contents,
			'containing' => $containing,
		);
	}

	/**
	 * Searches among the sections the user may edit.
	 *
	 * @param string $text     Text, possibly empty.
	 * @param int    $page     Page from 1.
	 * @param int    $per_page Results per page, at most 50.
	 * @return array{items:array,total:int}
	 */
	public function search( $text, $page = 1, $per_page = 20 ) {
		$ids = array();

		foreach ( $this->statements()->subjects_of( $this->mark_value(), self::MARK ) as $statement ) {
			$ids[] = (int) $statement->subject()->id();
		}

		$found = $this->posts->search_among( array_slice( $ids, 0, self::MAX_SEARCH ), (string) $text, max( 1, (int) $page ), min( 50, max( 1, (int) $per_page ) ) );
		$items = array();

		foreach ( $found['ids'] as $id ) {
			$info = $this->posts->info( $id );

			if ( null !== $info && $this->posts->can_edit( $id ) ) {
				$items[] = $info;
			}
		}

		return array(
			'items' => $items,
			'total' => $found['total'],
		);
	}

	/**
	 * The posts that can be added to a section, with whether they are in it already.
	 *
	 * @param int   $section   Id of the section.
	 * @param array $arguments Criteria of `PostStore::candidates()`.
	 * @return array{items:array,total:int}
	 * @throws SectionException When the user may not edit the section.
	 */
	public function candidates( $section, array $arguments ) {
		$section = (int) $section;

		$this->editable( $section );

		$found = $this->posts->candidates( $arguments );
		$in    = array();

		foreach ( $this->statements()->objects_of( Ref::post( $section ), self::PREDICATE ) as $statement ) {
			$in[ (int) $statement->object()->id() ] = true;
		}

		$this->posts->prime( $found['ids'] );
		$items = array();

		foreach ( $found['ids'] as $id ) {
			$info = $this->posts->info( $id );

			if ( null !== $info && $id !== $section && $this->posts->can_read( $id ) ) {
				$items[] = $info + array( 'in_section' => isset( $in[ $id ] ) );
			}
		}

		return array(
			'items' => $items,
			'total' => $found['total'],
		);
	}

	/**
	 * Checks that a post exists, may be a section and may be edited by the user.
	 *
	 * @param int $id Id of the post.
	 * @return void
	 * @throws SectionException When it cannot.
	 */
	private function editable( $id ) {
		$info = $id > 0 ? $this->posts->info( $id ) : null;

		if ( null === $info ) {
			SectionException::refuse( SectionException::NOT_FOUND );
		}

		if ( ! in_array( $info['type'], $this->posts->types(), true ) ) {
			SectionException::refuse( SectionException::BAD_TYPE );
		}

		if ( ! $this->posts->can_edit( $id ) ) {
			SectionException::refuse( SectionException::FORBIDDEN );
		}
	}

	/**
	 * The pinned ranks of statements, by statement id.
	 *
	 * @param Statement[] $statements Statements.
	 * @return array<int,int>
	 */
	private function pins( array $statements ) {
		$pins = array();

		foreach ( $this->statements()->qualifications_of( $statements ) as $id => $qualifications ) {
			if ( isset( $qualifications[ self::POSITION ][0] ) ) {
				$pins[ $id ] = (int) $qualifications[ self::POSITION ][0]->object()->key();
			}
		}

		return $pins;
	}

	/**
	 * The value of the mark.
	 *
	 * @return Literal
	 */
	private function mark_value() {
		return new Literal( 'boolean', '1' );
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
