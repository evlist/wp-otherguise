<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The mentions: which post speaks of which topic.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;

defined( 'ABSPATH' ) || exit;

/**
 * A mention is the statement `(post, index/mentions, topic term)`, one per pair, qualified by the statements `index/source` (one per source
 * that says it, so that two sources agree on one mention) and `index/strength` (`main` or `passing`; `main` wins).
 *
 * A source brings **its own** mentions of a post in line (`sync`): it adds the missing ones and withdraws only its own qualification; a
 * mention with no source left is deleted. The mentions of another source, and the ones made by hand (source `hand`), are never touched.
 */
final class Mentions {
	use MentionsHelpers;
	use MentionsDropping;
	use MentionsDeriving;

	public const MENTIONS = 'index/mentions';
	public const SOURCE   = 'index/source';
	public const STRENGTH = 'index/strength';
	public const ROLE     = 'index/role';
	public const ANCHOR   = 'index/anchor';
	public const VIA      = 'index/via';
	public const VISIBLE  = 'index/visible';
	public const HAND     = 'hand';

	/**
	 * Returns the service of the statements; called when needed.
	 *
	 * @var callable
	 */
	private $statements;

	/**
	 * The observations, when the model of slice 625 is on: what is dropped, moved or made by hand is done to them too.
	 *
	 * @var Observations|null
	 */
	private $observations;

	/**
	 * The motifs, when the model of slice 625 is on.
	 *
	 * @var Designations|null
	 */
	private $designations;

	/**
	 * Tells whether a topic makes no mention (slice 641).
	 *
	 * @var callable|null
	 */
	private $skip;

	/**
	 * Builds the service.
	 *
	 * @param callable          $statements   Returns the `Statements` service.
	 * @param Observations|null $observations The observations.
	 * @param Designations|null $designations The motifs.
	 * @param callable|null     $skip         Tells whether a topic makes no mention: `fn( int $topic ): bool` (default: none).
	 */
	public function __construct( $statements, ?Observations $observations = null, ?Designations $designations = null, $skip = null ) {
		$this->statements   = $statements;
		$this->observations = $observations;
		$this->designations = $designations;
		$this->skip         = $skip;
	}

	/**
	 * Whether a topic makes no mention (slice 641): the topic `_None_`, that stands for "not in the index".
	 *
	 * @param int $topic A topic.
	 * @return bool
	 */
	public function skips( $topic ) {
		return null !== $this->skip && (bool) ( $this->skip )( (int) $topic );
	}

	/**
	 * Brings the mentions of one source for one post in line with what it says.
	 *
	 * @param int                                                                        $post_id Post.
	 * @param string                                                                     $source  The source (a path of slice 605: `post/tag`).
	 * @param array<int, string|array{strength?:string,via?:string[],visible?:string[]}> $wanted  Topic id => strength (`main` or `passing`), or an
	 *        array with the strength, what produced the mention (`via`) and the modes in which it is shown (`visible`, empty for every mode).
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
				$by_topic = array();
				$added    = 0;
				$removed  = 0;

				foreach ( $existing as $statement ) {
					$by_topic[ (int) $statement->object()->id() ] = $statement;
				}

				foreach ( $wanted as $topic => $what ) {
					$topic    = (int) $topic;
					$what     = is_array( $what ) ? $what : array( 'strength' => $what );
					$strength = Candidate::MAIN === ( $what['strength'] ?? '' ) ? Candidate::MAIN : Candidate::PASSING;
					$mention  = $by_topic[ $topic ] ?? null;
					$known    = null === $mention ? array() : $about[ $mention->id() ] ?? array();

					if ( null === $mention ) {
						$mention = $statements->triple( $post, self::MENTIONS, Ref::term( $topic ) );
						++$added;
					}

					if ( ! $this->has( $known, self::SOURCE, $source ) ) {
						$statements->triple( $mention->as_entity(), self::SOURCE, new Literal( 'string', $source ) );
					}

					$this->strengthen( $mention, $strength );
					$this->keep_values( $mention, $known, self::VIA, $source, (array) ( $what['via'] ?? array() ) );
					$this->keep_values( $mention, $known, self::VISIBLE, $source, (array) ( $what['visible'] ?? array() ) );
				}

				foreach ( $by_topic as $topic => $mention ) {
					if ( isset( $wanted[ $topic ] ) || ! $this->has( $about[ $mention->id() ] ?? array(), self::SOURCE, $source ) ) {
						continue;
					}

					$this->withdraw( $mention, $source );

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
	 * Withdraws a source from a mention: its qualification and the values (`via`, `visible`) that were its own.
	 *
	 * @param \Otherguise\Triples\Storage\Statement $mention The mention.
	 * @param string                                $source  The source.
	 * @return void
	 */
	private function withdraw( $mention, $source ) {
		$statements = $this->statements();

		$statements->remove( $mention->as_entity(), self::SOURCE, new Literal( 'string', $source ) );

		foreach ( array( self::VIA, self::VISIBLE ) as $predicate ) {
			foreach ( $statements->match( $mention->as_entity(), $predicate ) as $statement ) {
				if ( 0 === strpos( (string) $statement->object()->key(), $source . '|' ) ) {
					$statements->delete( $statement );
				}
			}
		}
	}

	/**
	 * Brings the values of one source in a qualifier (`via` or `visible`) in line with what it says: the missing ones are added, its stale ones
	 * removed, the ones of another source left alone. A value is stored as `<source>|<value>`.
	 *
	 * @param \Otherguise\Triples\Storage\Statement $mention   The mention.
	 * @param array<string, array>                  $known     The qualifications read before.
	 * @param string                                $predicate `index/via` or `index/visible`.
	 * @param string                                $source    The source.
	 * @param string[]                              $values    What the source says now.
	 * @return void
	 */
	private function keep_values( $mention, array $known, $predicate, $source, array $values ) {
		$statements = $this->statements();
		$wanted     = array();

		foreach ( $values as $value ) {
			if ( '' !== (string) $value ) {
				$wanted[ self::pack( $source, (string) $value ) ] = true;
			}
		}

		$have = array();

		foreach ( $known[ $predicate ] ?? array() as $statement ) {
			$stored = (string) $statement->object()->key();

			if ( 0 === strpos( $stored, $source . '|' ) ) {
				$have[ $stored ] = $statement;
			}
		}

		foreach ( $wanted as $stored => $unused ) {
			if ( ! isset( $have[ $stored ] ) ) {
				$statements->triple( $mention->as_entity(), $predicate, new Literal( 'string', $stored ) );
			}
		}

		foreach ( $have as $stored => $statement ) {
			if ( ! isset( $wanted[ $stored ] ) ) {
				$statements->delete( $statement );
			}
		}
	}

	/**
	 * A value tied to its source, in at most 191 bytes (a longer value keeps its beginning and a short hash of the whole).
	 *
	 * @param string $source The source.
	 * @param string $value  The value.
	 * @return string
	 */
	public static function pack( $source, $value ) {
		$packed = $source . '|' . $value;

		return strlen( $packed ) <= Literal::MAX_BYTES ? $packed : substr( $packed, 0, Literal::MAX_BYTES - 9 ) . '#' . substr( sha1( $packed ), 0, 8 );
	}

	/**
	 * The sources that mentions have, with how many mentions each: what `sources check` compares with the registry.
	 *
	 * @return array<string, int>
	 */
	public function sources_in_use() {
		$count = array();

		foreach ( $this->statements()->match( null, self::SOURCE ) as $statement ) {
			$source           = (string) $statement->object()->key();
			$count[ $source ] = ( $count[ $source ] ?? 0 ) + 1;
		}

		ksort( $count );

		return $count;
	}

	/**
	 * The modes named by the visibilities of the mentions, with how many values each.
	 *
	 * @return array<string, int>
	 */
	public function modes_in_use() {
		$count = array();

		foreach ( $this->statements()->match( null, self::VISIBLE ) as $statement ) {
			$parts = explode( '|', (string) $statement->object()->key(), 2 );

			if ( 2 === count( $parts ) ) {
				$count[ $parts[1] ] = ( $count[ $parts[1] ] ?? 0 ) + 1;
			}
		}

		ksort( $count );

		return $count;
	}

	/**
	 * Gives every mention of a source to another source (`categories` became `post/category`): run twice, it finds nothing more to do.
	 *
	 * @param string $old The old source.
	 * @param string $new The new source; empty to drop the old one (a mention with no source left is deleted).
	 * @return int The number of qualifications changed.
	 */
	public function rename_source( $old, $new ) {
		$statements = $this->statements();
		$changed    = 0;

		foreach ( $statements->match( null, self::SOURCE, new Literal( 'string', (string) $old ) ) as $statement ) {
			$mention = $statements->find( (int) $statement->subject()->id() );

			if ( '' !== (string) $new ) {
				$statements->triple( $statement->subject(), self::SOURCE, new Literal( 'string', (string) $new ) );
			}

			$statements->delete( $statement );
			++$changed;

			if ( null !== $mention && array() === $this->sources( $mention ) ) {
				$statements->delete( $mention );
			}
		}

		return $changed;
	}

	/**
	 * The topics a post mentions, with their sources and strength.
	 *
	 * @param int $post_id Post.
	 * @return array<int, array{sources:string[],strength:string,details:array<string,array{via:string[],visible:?array}>}> By topic id.
	 */
	public function of_post( $post_id ) {
		$mentions = $this->statements()->match( Ref::post( (int) $post_id ), self::MENTIONS );

		return $this->describe( $mentions, 'object' );
	}

	/**
	 * Moves the mentions of a topic to another (slice 616, the merge): the post that mentioned the first now mentions the second, with its sources, its
	 * `via`, its visibility and its strength. A post that mentioned both has one mention with the union of what they said; **a source that showed a
	 * mention in every mode keeps showing it in every mode** (a union of modes with "every" is "every"). The first topic has no mention left.
	 *
	 * @param int  $from The topic that goes away.
	 * @param int  $to   The topic that stays.
	 * @param bool $dry  Count only.
	 * @return array{moved:int,merged:int} Mentions that became one of the second topic, and those that joined one it already had.
	 */
	public function move_topic( $from, $to, $dry = false ) {
		$statements = $this->statements();
		$from       = (int) $from;
		$to         = (int) $to;

		return $statements->transaction(
			function () use ( $statements, $from, $to, $dry ) {
				$moved  = 0;
				$merged = 0;

				// The motifs of the first topic are assigned to the second, by a person (slice 626): a new reading follows the decision.
				if ( ! $dry && null !== $this->observations ) {
					foreach ( array_keys( $this->observations->designations_of_topic( $from ) ) as $designation ) {
						$this->observations->assign( (int) $designation, $to, Observations::HAND );
					}
				}

				foreach ( $statements->match( null, self::MENTIONS, Ref::term( $from ) ) as $old ) {
					$post = Ref::post( (int) $old->subject()->id() );
					$new  = $statements->find_by_triple( $post, self::MENTIONS, Ref::term( $to ) );

					if ( null === $new ) {
						++$moved;
					} else {
						++$merged;
					}

					if ( $dry ) {
						continue;
					}

					$about = $statements->qualifications_of( array( $old ) )[ $old->id() ] ?? array();
					$new   = $new ?? $statements->triple( $post, self::MENTIONS, Ref::term( $to ) );
					$known = $statements->qualifications_of( array( $new ) )[ $new->id() ] ?? array();

					$this->join_qualifiers( $new, $known, $about );
					$statements->delete( $old );
				}

				return array(
					'moved'  => $moved,
					'merged' => $merged,
				);
			}
		);
	}

	/**
	 * Adds to a mention what another said: the sources, the `via`, the visibility of each source, the strength, the role and the anchor.
	 *
	 * @param \Otherguise\Triples\Storage\Statement $mention The mention that stays.
	 * @param array<string, array>                  $known   Its qualifications.
	 * @param array<string, array>                  $about   Those of the mention that goes away.
	 * @return void
	 */
	private function join_qualifiers( $mention, array $known, array $about ) {
		$statements = $this->statements();
		$sources    = array();

		foreach ( $about[ self::SOURCE ] ?? array() as $statement ) {
			$sources[] = (string) $statement->object()->key();
		}

		foreach ( $sources as $source ) {
			$already = $this->has( $known, self::SOURCE, $source );

			if ( ! $already ) {
				$statements->triple( $mention->as_entity(), self::SOURCE, new Literal( 'string', $source ) );
			}

			foreach ( array( self::VIA, self::VISIBLE ) as $predicate ) {
				$incoming = array();
				$have     = array();

				foreach ( $about[ $predicate ] ?? array() as $statement ) {
					if ( 0 === strpos( (string) $statement->object()->key(), $source . '|' ) ) {
						$incoming[] = (string) $statement->object()->key();
					}
				}

				foreach ( $known[ $predicate ] ?? array() as $statement ) {
					if ( 0 === strpos( (string) $statement->object()->key(), $source . '|' ) ) {
						$have[ (string) $statement->object()->key() ] = $statement;
					}
				}

				// No row of visibility means every mode: it stays so when either side said so.
				if ( self::VISIBLE === $predicate && $already && ( array() === $incoming || array() === $have ) ) {
					foreach ( $have as $statement ) {
						$statements->delete( $statement );
					}

					continue;
				}

				foreach ( $incoming as $stored ) {
					if ( ! isset( $have[ $stored ] ) ) {
						$statements->triple( $mention->as_entity(), $predicate, new Literal( 'string', $stored ) );
					}
				}
			}
		}

		foreach ( array( self::ROLE, self::ANCHOR ) as $predicate ) {
			foreach ( $about[ $predicate ] ?? array() as $statement ) {
				if ( ! $this->has( $known, $predicate, (string) $statement->object()->key() ) ) {
					$statements->triple( $mention->as_entity(), $predicate, new Literal( 'string', (string) $statement->object()->key() ) );
				}
			}
		}

		$strength = isset( $about[ self::STRENGTH ][0] ) ? (string) $about[ self::STRENGTH ][0]->object()->key() : Candidate::PASSING;

		$this->strengthen( $mention, $strength );
	}

	/**
	 * The posts that mention a topic, and with `$rolled_up` those that mention a topic below it.
	 *
	 * @param int   $topic     Topic.
	 * @param int[] $below     Ids of the descendants of the topic, when rolled up.
	 * @return array<int, array{sources:string[],strength:string,details:array}> By post id.
	 */
	public function of_topic( $topic, array $below = array() ) {
		$found = array();

		foreach ( array_merge( array( (int) $topic ), $below ) as $id ) {
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
	 * @param int $topic   Topic.
	 * @return void
	 */
	public function add_by_hand( $post_id, $topic ) {
		$this->sync_hand( (int) $post_id, (int) $topic, true );
		$this->hand_observation( (int) $post_id, (int) $topic, true );
	}

	/**
	 * Removes a mention made by a person.
	 *
	 * @param int $post_id Post.
	 * @param int $topic   Topic.
	 * @return void
	 */
	public function remove_by_hand( $post_id, $topic ) {
		$this->sync_hand( (int) $post_id, (int) $topic, false );
		$this->hand_observation( (int) $post_id, (int) $topic, false );
	}

	/**
	 * Keeps the observation of a mention made by a person: a motif of the source `hand` for the topic, assigned to it by hand (slice 626).
	 *
	 * @param int  $post_id Post.
	 * @param int  $topic   Topic.
	 * @param bool $add     Add or withdraw.
	 * @return void
	 */
	private function hand_observation( $post_id, $topic, $add ) {
		if ( null === $this->observations || null === $this->designations ) {
			return;
		}

		$key = Designation\Keys::by_hand( $topic );
		$id  = $this->designations->find( $key );
		$id  = $id > 0 ? $id : $this->designations->create(
			$key,
			self::HAND,
			array(
				'label' => 'by hand',
				'topic' => $topic,
			)
		);

		if ( $id <= 0 ) {
			return;
		}

		$this->observations->assign( $id, $topic, Observations::HAND );
		$this->designations->set_state(
			$id,
			Designations::ASSIGNED,
			array(
				'topic' => $topic,
				'why'   => self::HAND,
			)
		);

		$wanted = $this->observations->of_post( $post_id, self::HAND );

		if ( $add ) {
			$wanted[ $id ] = array( 'strength' => Candidate::MAIN );
		} else {
			unset( $wanted[ $id ] );
		}

		$this->observations->sync( $post_id, self::HAND, $wanted );
	}

	/**
	 * Adds or withdraws the `hand` source on a mention.
	 *
	 * @param int  $post_id Post.
	 * @param int  $topic   Topic.
	 * @param bool $add     Add or withdraw.
	 * @return void
	 */
	private function sync_hand( $post_id, $topic, $add ) {
		$statements = $this->statements();
		$hand       = new Literal( 'string', self::HAND );

		if ( $add ) {
			$mention = $statements->triple( Ref::post( $post_id ), self::MENTIONS, Ref::term( $topic ) );
			$statements->triple( $mention->as_entity(), self::SOURCE, $hand );
			$this->strengthen( $mention, Candidate::MAIN );

			return;
		}

		$mention = $statements->find_by_triple( Ref::post( $post_id ), self::MENTIONS, Ref::term( $topic ) );

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
	 * @param string                                  $end      `object` (the topic) or `subject` (the post).
	 * @return array<int, array{sources:string[],strength:string}>
	 */
	private function describe( array $mentions, $end ) {
		$about = $this->statements()->qualifications_of( $mentions );
		$found = array();

		foreach ( $mentions as $mention ) {
			$qualified = $about[ $mention->id() ] ?? array();
			$sources   = array();
			$details   = array();

			foreach ( $qualified[ self::SOURCE ] ?? array() as $statement ) {
				$source             = (string) $statement->object()->key();
				$sources[]          = $source;
				$details[ $source ] = array(
					'via'     => array(),
					'visible' => null,
				);
			}

			sort( $sources );
			ksort( $details );

			foreach ( array(
				self::VIA => 'via',
				self::VISIBLE => 'visible',
			) as $predicate => $name ) {
				foreach ( $qualified[ $predicate ] ?? array() as $statement ) {
					$parts = explode( '|', (string) $statement->object()->key(), 2 );

					if ( 2 !== count( $parts ) || ! isset( $details[ $parts[0] ] ) ) {
						continue;
					}

					if ( 'via' === $name ) {
						$details[ $parts[0] ]['via'][] = $parts[1];
					} else {
						$details[ $parts[0] ]['visible'] = array_merge( (array) $details[ $parts[0] ]['visible'], array( $parts[1] ) );
					}
				}
			}

			$strength = isset( $qualified[ self::STRENGTH ] ) ? (string) $qualified[ self::STRENGTH ][0]->object()->key() : Candidate::PASSING;
			$id       = 'object' === $end ? (int) $mention->object()->id() : (int) $mention->subject()->id();

			$found[ $id ] = array(
				'sources'  => $sources,
				'strength' => $strength,
				'details'  => $details,
			);
		}

		return $found;
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
