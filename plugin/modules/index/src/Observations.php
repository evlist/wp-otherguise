<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The observations and the assignments of the designations.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;

defined( 'ABSPATH' ) || exit;

/**
 * The two statements of the model of slice 625 (coded in slice 626):
 *
 * - **an observation**: `(post, index/observes, designation term)`, one per pair, qualified by `index/source` (the leaf of the source), `index/strength`,
 *   `index/via` (one value per file: the number of photographs of a place in a post is the number of its values) and `index/visible`. Triples keeps one
 *   statement per triple: **the occurrences of a designation in one post are the values of its qualifiers**;
 * - **an assignment**: `(designation term, index/designates, topic term)`, qualified by `index/by` (`system` or `hand`). A designation has at most one.
 *
 * The mentions (`Mentions`) are derived from these: the observations of a post, grouped by the topic of their designation.
 */
final class Observations {
	public const OBSERVES   = 'index/observes';
	public const DESIGNATES = 'index/designates';
	public const BY         = 'index/by';
	public const SYSTEM     = 'system';
	public const HAND       = 'hand';

	/**
	 * Returns the service of the statements; called when needed.
	 *
	 * @var callable
	 */
	private $statements;

	/**
	 * Builds the service.
	 *
	 * @param callable $statements Returns the `Statements` service.
	 */
	public function __construct( $statements ) {
		$this->statements = $statements;
	}

	/**
	 * Brings the observations of one source in one post in line with what it saw.
	 *
	 * @param int                                                               $post_id Post.
	 * @param string                                                            $leaf    The leaf of the source (`post/link`).
	 * @param array<int, array{strength?:string,via?:string[],visible?:?array}> $wanted Designation id => what was seen.
	 * @return array{added:int,removed:int}
	 */
	public function sync( $post_id, $leaf, array $wanted ) {
		$statements = $this->statements();
		$post       = Ref::post( (int) $post_id );

		return $statements->transaction(
			function () use ( $statements, $post, $leaf, $wanted ) {
				$existing = $statements->match( $post, self::OBSERVES );
				$about    = $statements->qualifications_of( $existing );
				$mine     = array();
				$added    = 0;
				$removed  = 0;

				foreach ( $existing as $statement ) {
					$leaves = $this->values( $about[ $statement->id() ][ Mentions::SOURCE ] ?? array() );

					if ( in_array( $leaf, $leaves, true ) ) {
						$mine[ (int) $statement->object()->id() ] = $statement;
					}
				}

				foreach ( $wanted as $designation => $what ) {
					$designation = (int) $designation;
					$statement   = $mine[ $designation ] ?? $statements->find_by_triple( $post, self::OBSERVES, Ref::term( $designation ) );
					$known       = array();

					if ( null === $statement ) {
						$statement = $statements->triple( $post, self::OBSERVES, Ref::term( $designation ) );
						$statements->triple( $statement->as_entity(), Mentions::SOURCE, new Literal( 'string', $leaf ) );
						++$added;
					} else {
						$known = $statements->qualifications_of( array( $statement ) )[ $statement->id() ] ?? array();
					}

					$this->keep( $statement, $known, Mentions::STRENGTH, array( Candidate::MAIN === ( $what['strength'] ?? '' ) ? Candidate::MAIN : Candidate::PASSING ) );
					$this->keep( $statement, $known, Mentions::VIA, self::packed( $leaf, (array) ( $what['via'] ?? array() ) ) );
					$this->keep( $statement, $known, Mentions::VISIBLE, self::packed( $leaf, (array) ( $what['visible'] ?? array() ) ) );
				}

				foreach ( $mine as $designation => $statement ) {
					if ( ! isset( $wanted[ $designation ] ) ) {
						$statements->delete( $statement );
						++$removed;
					}
				}

				return array(
					'added'   => $added,
					'removed' => $removed,
				);
			}
		);
	}

	/**
	 * What the observations of a post say, by designation.
	 *
	 * @param int         $post_id Post.
	 * @param string|null $leaf    A source (with its sub-sources), or null for all.
	 * @return array<int, array{source:string,strength:string,via:string[],visible:?string[]}>
	 */
	public function of_post( $post_id, $leaf = null ) {
		$statements = $this->statements();
		$existing   = $statements->match( Ref::post( (int) $post_id ), self::OBSERVES );
		$about      = $statements->qualifications_of( $existing );
		$found      = array();

		foreach ( $existing as $statement ) {
			$qualifiers = $about[ $statement->id() ] ?? array();
			$source     = (string) ( $this->values( $qualifiers[ Mentions::SOURCE ] ?? array() )[0] ?? '' );

			if ( null !== $leaf && ! Sources::is_a( $source, (string) $leaf ) ) {
				continue;
			}

			$strength = $this->values( $qualifiers[ Mentions::STRENGTH ] ?? array() );
			$visible  = $this->unpacked( $qualifiers[ Mentions::VISIBLE ] ?? array() );

			$found[ (int) $statement->object()->id() ] = array(
				'source'   => $source,
				'strength' => Candidate::MAIN === ( $strength[0] ?? '' ) ? Candidate::MAIN : Candidate::PASSING,
				'via'      => $this->unpacked( $qualifiers[ Mentions::VIA ] ?? array() ),
				'visible'  => array() === $visible ? null : $visible,
			);
		}

		return $found;
	}

	/**
	 * The posts where a designation is observed.
	 *
	 * @param int $designation Designation.
	 * @return int[]
	 */
	public function posts_of( $designation ) {
		$posts = array();

		foreach ( $this->statements()->match( null, self::OBSERVES, Ref::term( (int) $designation ) ) as $statement ) {
			$posts[] = (int) $statement->subject()->id();
		}

		return $posts;
	}

	/**
	 * Makes an assignment: the designation goes to the topic, by the system or by a person.
	 *
	 * @param int    $designation Designation.
	 * @param int    $topic       Topic.
	 * @param string $by          `system` or `hand`.
	 * @return bool Whether something changed.
	 */
	public function assign( $designation, $topic, $by = self::SYSTEM ) {
		$by   = self::HAND === $by ? self::HAND : self::SYSTEM;
		$have = $this->assignment( (int) $designation );

		if ( null !== $have && (int) $have['topic'] === (int) $topic && $have['by'] === $by ) {
			return false;
		}

		$statements = $this->statements();

		return (bool) $statements->transaction(
			function () use ( $statements, $designation, $topic, $by ) {
				$this->unassign( (int) $designation );

				$statement = $statements->triple( Ref::term( (int) $designation ), self::DESIGNATES, Ref::term( (int) $topic ) );
				$statements->triple( $statement->as_entity(), self::BY, new Literal( 'string', $by ) );

				return true;
			}
		);
	}

	/**
	 * Takes an assignment away.
	 *
	 * @param int $designation Designation.
	 * @return void
	 */
	public function unassign( $designation ) {
		$statements = $this->statements();

		foreach ( $statements->match( Ref::term( (int) $designation ), self::DESIGNATES ) as $statement ) {
			$statements->delete( $statement );
		}
	}

	/**
	 * The assignment of a designation.
	 *
	 * @param int $designation Designation.
	 * @return array{topic:int,by:string}|null
	 */
	public function assignment( $designation ) {
		$statements = $this->statements();
		$found      = $statements->match( Ref::term( (int) $designation ), self::DESIGNATES );

		if ( array() === $found ) {
			return null;
		}

		$statement = reset( $found );
		$by        = $this->values( $statements->qualifications_of( array( $statement ) )[ $statement->id() ][ self::BY ] ?? array() );

		return array(
			'topic' => (int) $statement->object()->id(),
			'by'    => self::HAND === ( $by[0] ?? '' ) ? self::HAND : self::SYSTEM,
		);
	}

	/**
	 * Every assignment, in two queries (the screen of the assignments lists them all).
	 *
	 * @return array<int, array{topic:int,by:string}> By designation.
	 */
	public function assignments() {
		$statements = $this->statements();
		$found      = $statements->match( null, self::DESIGNATES );
		$about      = $statements->qualifications_of( $found );
		$all        = array();

		foreach ( $found as $statement ) {
			$who = $this->values( $about[ $statement->id() ][ self::BY ] ?? array() );

			$all[ (int) $statement->subject()->id() ] = array(
				'topic' => (int) $statement->object()->id(),
				'by'    => self::HAND === ( $who[0] ?? '' ) ? self::HAND : self::SYSTEM,
			);
		}

		return $all;
	}

	/**
	 * How many posts observe designations. **Without a list, one pass over every observation** (heavy: tens of thousands of statements; the screen asks for it
	 * only to sort by the number of posts or to list the designations that nothing observes); with a list, one question each.
	 *
	 * @param int[]|null $ids The designations, or null for all those that have an observation.
	 * @return array<int, int> By designation.
	 */
	public function post_counts( ?array $ids = null ) {
		$statements = $this->statements();
		$counts     = array();

		if ( null === $ids ) {
			foreach ( $statements->match( null, self::OBSERVES ) as $statement ) {
				$id            = (int) $statement->object()->id();
				$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
			}

			return $counts;
		}

		foreach ( $ids as $id ) {
			$counts[ (int) $id ] = count( $statements->match( null, self::OBSERVES, Ref::term( (int) $id ) ) );
		}

		return $counts;
	}

	/**
	 * The designations assigned to a topic.
	 *
	 * @param int $topic Topic.
	 * @return array<int, string> Designation id => `system` or `hand`.
	 */
	public function designations_of_topic( $topic ) {
		$statements = $this->statements();
		$found      = $statements->match( null, self::DESIGNATES, Ref::term( (int) $topic ) );
		$about      = $statements->qualifications_of( $found );
		$by         = array();

		foreach ( $found as $statement ) {
			$who                                      = $this->values( $about[ $statement->id() ][ self::BY ] ?? array() );
			$by[ (int) $statement->subject()->id() ] = self::HAND === ( $who[0] ?? '' ) ? self::HAND : self::SYSTEM;
		}

		return $by;
	}

	/**
	 * The topics that a person assigned designations to (a topic that a decision holds is not cleaned away, observed or not).
	 *
	 * @return array<int, true>
	 */
	public function manual_topics() {
		$statements = $this->statements();
		$found      = $statements->match( null, self::DESIGNATES );
		$about      = $statements->qualifications_of( $found );
		$topics     = array();

		foreach ( $found as $statement ) {
			if ( in_array( self::HAND, $this->values( $about[ $statement->id() ][ self::BY ] ?? array() ), true ) ) {
				$topics[ (int) $statement->object()->id() ] = true;
			}
		}

		return $topics;
	}

	/**
	 * Deletes the observations of a source (with its sub-sources).
	 *
	 * @param string $source The source.
	 * @param bool   $dry    Count only.
	 * @return array{observations:int,designations:int[]} How many, and the designations they were of.
	 */
	public function drop_source( $source, $dry = false ) {
		$statements = $this->statements();
		$hit        = 0;
		$touched    = array();

		foreach ( $statements->match( null, self::OBSERVES ) as $statement ) {
			$leaf = (string) ( $this->values( $statements->qualifications_of( array( $statement ) )[ $statement->id() ][ Mentions::SOURCE ] ?? array() )[0] ?? '' );

			if ( '' !== $source && ! Sources::is_a( $leaf, (string) $source ) ) {
				continue;
			}

			++$hit;
			$touched[ (int) $statement->object()->id() ] = true;

			if ( ! $dry ) {
				$statements->delete( $statement );
			}
		}

		return array(
			'observations' => $hit,
			'designations' => array_keys( $touched ),
		);
	}

	/**
	 * How many observations and assignments there are (slice 626: the numbers that decide whether Triples is enough).
	 *
	 * @return array{observations:int,assignments:int,by_hand:int}
	 */
	public function counts() {
		$statements = $this->statements();

		return array(
			'observations' => count( $statements->match( null, self::OBSERVES ) ),
			'assignments'  => count( $statements->match( null, self::DESIGNATES ) ),
			'by_hand'      => count( $statements->match( null, self::BY, new Literal( 'string', self::HAND ) ) ),
		);
	}

	/**
	 * The designations that no post observes (read from the statements: the store does not know).
	 *
	 * @param int[] $candidates The designations to look at.
	 * @return int[] Those with no observation.
	 */
	public function unobserved( array $candidates ) {
		$statements = $this->statements();

		return array_values(
			array_filter(
				array_map( 'intval', $candidates ),
				static fn( $id ) => array() === $statements->match( null, self::OBSERVES, Ref::term( $id ) )
			)
		);
	}

	/**
	 * Brings a qualifier in line with exact values: the missing ones are added, the stale ones removed.
	 *
	 * @param \Otherguise\Triples\Storage\Statement $statement The observation.
	 * @param array<string, array>                  $known     Its qualifications read before.
	 * @param string                                $predicate The qualifier.
	 * @param string[]                              $values    What it says now.
	 * @return void
	 */
	private function keep( $statement, array $known, $predicate, array $values ) {
		$statements = $this->statements();
		$wanted     = array_fill_keys( array_filter( array_map( 'strval', $values ), 'strlen' ), true );
		$have       = array();

		foreach ( $known[ $predicate ] ?? array() as $qualifier ) {
			$have[ (string) $qualifier->object()->key() ] = $qualifier;
		}

		foreach ( $wanted as $value => $unused ) {
			if ( ! isset( $have[ $value ] ) ) {
				$statements->triple( $statement->as_entity(), $predicate, new Literal( 'string', $value ) );
			}
		}

		foreach ( $have as $value => $qualifier ) {
			if ( ! isset( $wanted[ $value ] ) ) {
				$statements->delete( $qualifier );
			}
		}
	}

	/**
	 * Values tied to their source, as the mentions store them.
	 *
	 * @param string   $leaf   The source.
	 * @param string[] $values The values.
	 * @return string[]
	 */
	private static function packed( $leaf, array $values ) {
		return array_map( static fn( $value ) => Mentions::pack( $leaf, (string) $value ), array_values( array_filter( array_map( 'strval', $values ), 'strlen' ) ) );
	}

	/**
	 * The string values of qualifier statements.
	 *
	 * @param array $qualifiers Statements.
	 * @return string[]
	 */
	private function values( array $qualifiers ) {
		return array_values( array_map( static fn( $statement ) => (string) $statement->object()->key(), $qualifiers ) );
	}

	/**
	 * The values of a qualifier written as `<leaf>|<value>`, without the leaf.
	 *
	 * @param array $qualifiers Statements.
	 * @return string[]
	 */
	private function unpacked( array $qualifiers ) {
		return array_values(
			array_map(
				static function ( $value ) {
					$cut = strpos( $value, '|' );

					return false === $cut ? $value : substr( $value, $cut + 1 );
				},
				$this->values( $qualifiers )
			)
		);
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
