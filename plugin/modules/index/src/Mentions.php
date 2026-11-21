<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The mentions: which post speaks of which entry.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;

defined( 'ABSPATH' ) || exit;

/**
 * A mention is the statement `(post, index/mentions, entry term)`, one per pair, qualified by the statements `index/source` (one per source
 * that says it, so that two sources agree on one mention) and `index/strength` (`main` or `passing`; `main` wins).
 *
 * A source brings **its own** mentions of a post in line (`sync`): it adds the missing ones and withdraws only its own qualification; a
 * mention with no source left is deleted. The mentions of another source, and the ones made by hand (source `hand`), are never touched.
 */
final class Mentions {
	public const MENTIONS = 'index/mentions';
	public const SOURCE   = 'index/source';
	public const STRENGTH = 'index/strength';
	public const ROLE     = 'index/role';
	public const ANCHOR   = 'index/anchor';
	public const HAND     = 'hand';

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
	 * Brings the mentions of one source for one post in line with what it says.
	 *
	 * @param int                $post_id Post.
	 * @param string             $source  Source slug.
	 * @param array<int, string> $wanted  Entry id => strength (`main` or `passing`).
	 * @return array{added:int,removed:int} Nothing is written when both are 0.
	 */
	public function sync( $post_id, $source, array $wanted ) {
		$statements = $this->statements();
		$post       = Ref::post( (int) $post_id );
		$source     = (string) $source;

		return $statements->transaction(
			function () use ( $statements, $post, $source, $wanted ) {
				$existing = $statements->match( $post, self::MENTIONS );
				$about    = $statements->qualifications_of( $existing );
				$by_entry = array();
				$added    = 0;
				$removed  = 0;

				foreach ( $existing as $statement ) {
					$by_entry[ (int) $statement->object()->id() ] = $statement;
				}

				foreach ( $wanted as $entry => $strength ) {
					$entry    = (int) $entry;
					$strength = Candidate::MAIN === $strength ? Candidate::MAIN : Candidate::PASSING;
					$mention  = $by_entry[ $entry ] ?? null;
					$known    = null === $mention ? array() : $about[ $mention->id() ] ?? array();

					if ( null === $mention ) {
						$mention = $statements->triple( $post, self::MENTIONS, Ref::term( $entry ) );
						++$added;
					}

					if ( ! $this->has( $known, self::SOURCE, $source ) ) {
						$statements->triple( $mention->as_entity(), self::SOURCE, new Literal( 'string', $source ) );
					}

					$this->strengthen( $mention, $strength );
				}

				foreach ( $by_entry as $entry => $mention ) {
					if ( isset( $wanted[ $entry ] ) || ! $this->has( $about[ $mention->id() ] ?? array(), self::SOURCE, $source ) ) {
						continue;
					}

					$statements->remove( $mention->as_entity(), self::SOURCE, new Literal( 'string', $source ) );

					if ( array() === $this->sources( $mention ) ) {
						$statements->delete( $mention );
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
	 * The entries a post mentions, with their sources and strength.
	 *
	 * @param int $post_id Post.
	 * @return array<int, array{sources:string[],strength:string}> By entry id.
	 */
	public function of_post( $post_id ) {
		$mentions = $this->statements()->match( Ref::post( (int) $post_id ), self::MENTIONS );

		return $this->describe( $mentions, 'object' );
	}

	/**
	 * The posts that mention an entry, and with `$rolled_up` those that mention an entry below it.
	 *
	 * @param int   $entry     Entry.
	 * @param int[] $below     Ids of the descendants of the entry, when rolled up.
	 * @return array<int, array{sources:string[],strength:string}> By post id.
	 */
	public function of_entry( $entry, array $below = array() ) {
		$found = array();

		foreach ( array_merge( array( (int) $entry ), $below ) as $id ) {
			$mentions = $this->statements()->match( null, self::MENTIONS, Ref::term( (int) $id ) );

			foreach ( $this->describe( $mentions, 'subject' ) as $post => $what ) {
				$found[ $post ] = isset( $found[ $post ] ) ? $this->merged( $found[ $post ], $what ) : $what;
			}
		}

		ksort( $found );

		return $found;
	}

	/**
	 * Adds a mention made by a person.
	 *
	 * @param int $post_id Post.
	 * @param int $entry   Entry.
	 * @return void
	 */
	public function add_by_hand( $post_id, $entry ) {
		$this->sync_hand( (int) $post_id, (int) $entry, true );
	}

	/**
	 * Removes a mention made by a person.
	 *
	 * @param int $post_id Post.
	 * @param int $entry   Entry.
	 * @return void
	 */
	public function remove_by_hand( $post_id, $entry ) {
		$this->sync_hand( (int) $post_id, (int) $entry, false );
	}

	/**
	 * Adds or withdraws the `hand` source on a mention.
	 *
	 * @param int  $post_id Post.
	 * @param int  $entry   Entry.
	 * @param bool $add     Add or withdraw.
	 * @return void
	 */
	private function sync_hand( $post_id, $entry, $add ) {
		$statements = $this->statements();
		$hand       = new Literal( 'string', self::HAND );

		if ( $add ) {
			$mention = $statements->triple( Ref::post( $post_id ), self::MENTIONS, Ref::term( $entry ) );
			$statements->triple( $mention->as_entity(), self::SOURCE, $hand );
			$this->strengthen( $mention, Candidate::MAIN );

			return;
		}

		$mention = $statements->find_by_triple( Ref::post( $post_id ), self::MENTIONS, Ref::term( $entry ) );

		if ( null === $mention ) {
			return;
		}

		$statements->remove( $mention->as_entity(), self::SOURCE, $hand );

		if ( array() === $this->sources( $mention ) ) {
			$statements->delete( $mention );
		}
	}

	/**
	 * Makes the strength of a mention the strongest one said so far.
	 *
	 * @param \Otherguise\Triples\Storage\Statement $mention  The mention.
	 * @param string                                $strength What the source says.
	 * @return void
	 */
	private function strengthen( $mention, $strength ) {
		$statements = $this->statements();
		$current    = $statements->match( $mention->as_entity(), self::STRENGTH );
		$have       = array() === $current ? null : $current[0]->object()->key();

		if ( $have === $strength || ( Candidate::MAIN === $have && Candidate::PASSING === $strength ) ) {
			return;
		}

		$statements->replace( $mention->as_entity(), self::STRENGTH, new Literal( 'string', $strength ) );
	}

	/**
	 * The sources of a mention.
	 *
	 * @param \Otherguise\Triples\Storage\Statement $mention The mention.
	 * @return string[]
	 */
	private function sources( $mention ) {
		$sources = array();

		foreach ( $this->statements()->match( $mention->as_entity(), self::SOURCE ) as $statement ) {
			$sources[] = (string) $statement->object()->key();
		}

		sort( $sources );

		return $sources;
	}

	/**
	 * Describes mentions with their sources and strength, keyed by the id of the end that is asked.
	 *
	 * @param \Otherguise\Triples\Storage\Statement[] $mentions Mentions.
	 * @param string                                  $end      `object` (the entry) or `subject` (the post).
	 * @return array<int, array{sources:string[],strength:string}>
	 */
	private function describe( array $mentions, $end ) {
		$about = $this->statements()->qualifications_of( $mentions );
		$found = array();

		foreach ( $mentions as $mention ) {
			$qualified = $about[ $mention->id() ] ?? array();
			$sources   = array();

			foreach ( $qualified[ self::SOURCE ] ?? array() as $statement ) {
				$sources[] = (string) $statement->object()->key();
			}

			sort( $sources );

			$strength = isset( $qualified[ self::STRENGTH ] ) ? (string) $qualified[ self::STRENGTH ][0]->object()->key() : Candidate::PASSING;
			$id       = 'object' === $end ? (int) $mention->object()->id() : (int) $mention->subject()->id();

			$found[ $id ] = array(
				'sources'  => $sources,
				'strength' => $strength,
			);
		}

		return $found;
	}

	/**
	 * Two descriptions of one mention found through two entries.
	 *
	 * @param array $a First.
	 * @param array $b Second.
	 * @return array{sources:string[],strength:string}
	 */
	private function merged( array $a, array $b ) {
		$sources = array_values( array_unique( array_merge( $a['sources'], $b['sources'] ) ) );

		sort( $sources );

		return array(
			'sources'  => $sources,
			'strength' => ( Candidate::MAIN === $a['strength'] || Candidate::MAIN === $b['strength'] ) ? Candidate::MAIN : Candidate::PASSING,
		);
	}

	/**
	 * Whether a list of qualifications holds a value.
	 *
	 * @param array<string, array> $known     Qualifications by predicate.
	 * @param string               $predicate Predicate.
	 * @param string               $value     Value.
	 * @return bool
	 */
	private function has( array $known, $predicate, $value ) {
		foreach ( $known[ $predicate ] ?? array() as $statement ) {
			if ( (string) $statement->object()->key() === $value ) {
				return true;
			}
		}

		return false;
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
