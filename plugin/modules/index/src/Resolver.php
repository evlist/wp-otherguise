<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ties candidates to topics.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The resolver (slice 600). A candidate is looked up **by its keys** among the keys of the topics:
 *
 * - the keys are tried most reliable first, the label key last: the **first key that matches** decides;
 * - one topic: it is that topic (the other keys of the candidate are added to it, so that a later source finds it by any of them);
 * - none: a topic is created, `approved` when the source is sure, `candidate` otherwise;
 * - several topics: the candidate is **ambiguous**, recorded as a `candidate` topic that carries its keys, and not mentioned until a
 *   person links it (adding a key to a topic is what linking is).
 *
 * The label key (`label:<folded label>`) is a key of every topic, scoped by the parent (`label:<parent id>/<folded label>`), so that two
 * homonyms with two parents stay two topics. A candidate with no key at all is looked up by its label key only.
 */
final class Resolver {
	public const RESOLVED  = 'resolved';
	public const CREATED   = 'created';
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * The `type` of an ambiguity that is not one: a single topic of this label, of a kind that the source cannot mean (slice 632).
	 */
	public const INCOMPATIBLE = 'incompatible';

	/**
	 * The store.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * Builds the resolver.
	 *
	 * @param Topics $topics The store.
	 */
	public function __construct( Topics $topics ) {
		$this->topics = $topics;
	}

	/**
	 * Whether a topic exists (a decision of a person may name a topic that was deleted since).
	 *
	 * @param int $id Topic.
	 * @return bool
	 */
	public function exists( $id ) {
		return null !== $this->topics->get( (int) $id );
	}

	/**
	 * Resolves a candidate.
	 *
	 * @param Candidate $candidate The candidate.
	 * @param bool      $sure      Whether its source is sure (a new topic is then approved).
	 * @param bool      $dry       Write nothing: say what would happen (a topic that would be created has the id 0).
	 * @param bool      $record    Whether an ambiguous candidate leaves a placeholder topic (`candidate`, key `ambiguous:…`): **not when the designations hold the doubt** (slice 629).
	 * @return array{result:string,topic:int,new:int,ambiguity?:array{level:string,key:string,candidates:int[]}} `topic` is 0 when ambiguous, and `ambiguity` then says
	 *         **which level** hesitated (its label, its key) and **between which topics** (slice 631); `new` counts the topics created, the broader ones included.
	 */
	public function resolve( Candidate $candidate, $sure = true, $dry = false, $record = true ) {
		$parent = 0;
		$above  = array( 'new' => 0 );

		if ( null !== $candidate->parent ) {
			$above  = $this->resolve( $candidate->parent, $sure, $dry, $record );
			$parent = $above['topic'];

			// An ambiguous parent makes the child ambiguous; a parent that would be created makes the child new.
			if ( self::AMBIGUOUS === $above['result'] || ( $dry && 0 === $parent ) ) {
				return array_merge(
					array(
						'result' => self::AMBIGUOUS === $above['result'] ? self::AMBIGUOUS : self::CREATED,
						'topic'  => 0,
						'new'    => $above['new'] + ( self::AMBIGUOUS === $above['result'] ? 0 : 1 ),
					),
					isset( $above['ambiguity'] ) ? array( 'ambiguity' => $above['ambiguity'] ) : array()
				);
			}
		}

		$label = self::label_key( $parent, $candidate->label );
		$keys  = array_merge( $candidate->keys, array( $label ) );
		$found = array();
		$clash = false;

		// The first key that matches decides: the keys come most reliable first and the label comes last.
		foreach ( $keys as $key ) {
			$found = $this->without_refused( $this->topics->with_key( $key ) );

			if ( array() !== $found ) {
				// Same label, a kind that cannot be the same (slice 632): not an ambiguity, the topic and the source disagree. A person decides.
				$clash = $key === $label && 1 === count( $found ) && $this->conflicts( (int) reset( $found ), $candidate );

				break;
			}
		}

		// A person chose, among the topics that bear this level, the one that it is: the doubt is over (slice 631).
		if ( count( $found ) > 1 || $clash ) {
			$pinned = $this->topics->pinned( $label );

			if ( $pinned > 0 && null !== $this->topics->get( $pinned ) ) {
				$found = array( $pinned );
				$clash = false;
			}
		}

		if ( $clash ) {
			return $this->incompatible( (int) reset( $found ), $candidate, $label, $above['new'] );
		}

		if ( count( $found ) > 1 ) {
			if ( ! $dry && $record ) {
				$this->record_ambiguous( $candidate, $parent );
			}

			return array(
				'result'    => self::AMBIGUOUS,
				'topic'     => 0,
				'new'       => $above['new'],
				'ambiguity' => array(
					'level'      => $candidate->label,
					'key'        => $label,
					'candidates' => array_values( array_filter( array_map( 'intval', $found ) ) ),
				),
			);
		}

		if ( 1 === count( $found ) ) {
			$id = (int) reset( $found );

			if ( ! $dry ) {
				$this->topics->add_keys( $id, $keys );
				$this->refine_kind( $id, $candidate );
			}

			return array(
				'result' => self::RESOLVED,
				'topic'  => $id,
				'new'    => $above['new'],
			);
		}

		if ( $dry ) {
			return array(
				'result' => self::CREATED,
				'topic'  => 0,
				'new'    => $above['new'] + 1,
			);
		}

		$id = $this->topics->create( $candidate->label, $candidate->kind, $parent, $sure ? Topics::APPROVED : Topics::CANDIDATE, $keys );

		// WordPress refuses a term whose name is already one of its siblings' (accents and case ignored): the topic is there, under other keys. Take it (slice 631),
		// instead of leaving the designation waiting for ever; of another kind, a person decides.
		if ( $id <= 0 ) {
			return $this->sibling( $candidate, $parent, $keys, $above['new'] );
		}

		return array(
			'result' => self::CREATED,
			'topic'  => $id,
			'new'    => $above['new'] + 1,
		);
	}

	/**
	 * The result for a level whose topic is of a kind that the source cannot mean (slice 632): the designation has no topic, and says **why**: the topic that is
	 * there, its kind and the kind that the source gives. It is not an ambiguity (there is one topic, not several): the person keeps the topic, or assigns the
	 * designation elsewhere.
	 *
	 * @param int       $topic     The topic that bears the label.
	 * @param Candidate $candidate The candidate.
	 * @param string    $key       The key of the label under its parent.
	 * @param int       $new       How many topics were made above.
	 * @return array<string, mixed>
	 */
	private function incompatible( $topic, Candidate $candidate, $key, $new ) {
		$have = $this->topics->get( $topic );

		return array(
			'result'    => self::AMBIGUOUS,
			'topic'     => 0,
			'new'       => $new,
			'ambiguity' => array(
				'type'       => self::INCOMPATIBLE,
				'level'      => $candidate->label,
				'key'        => $key,
				'candidates' => array( (int) $topic ),
				'found_kind' => null === $have ? '' : (string) $have['kind'],
				'asked_kind' => $candidate->kind,
			),
		);
	}

	/**
	 * The topic that bears the label under the parent when WordPress refused to make another one.
	 *
	 * @param Candidate $candidate The candidate.
	 * @param int       $parent    The parent topic.
	 * @param string[]  $keys      The keys of the candidate.
	 * @param int       $new       How many topics were made above.
	 * @return array<string, mixed>
	 */
	private function sibling( Candidate $candidate, $parent, array $keys, $new ) {
		$same = array();

		foreach ( $this->topics->all( array( 'parent' => (int) $parent ) ) as $id => $topic ) {
			if ( Folding::key( (string) $topic['label'] ) === Folding::key( $candidate->label ) ) {
				$same[] = (int) $id;
			}
		}

		if ( 1 === count( $same ) && ! $this->conflicts( $same[0], $candidate ) ) {
			$this->topics->add_keys( $same[0], $keys );
			$this->refine_kind( $same[0], $candidate );

			return array(
				'result' => self::RESOLVED,
				'topic'  => $same[0],
				'new'    => $new,
			);
		}

		if ( 1 === count( $same ) ) {
			return $this->incompatible( $same[0], $candidate, self::label_key( $parent, $candidate->label ), $new );
		}

		if ( array() === $same ) {
			return array(
				'result' => self::CREATED,
				'topic'  => 0,
				'new'    => $new,
			);
		}

		return array(
			'result'    => self::AMBIGUOUS,
			'topic'     => 0,
			'new'       => $new,
			'ambiguity' => array(
				'level'      => $candidate->label,
				'key'        => self::label_key( $parent, $candidate->label ),
				'candidates' => $same,
			),
		);
	}

	/**
	 * Gives a topic the more specific kind that a candidate says (a place that is now known to be a commune), unless a person chose its kind
	 * (slice 614): a topic is not made less precise, nor changed to a kind that is not below its own.
	 *
	 * @param int       $id        Topic.
	 * @param Candidate $candidate The candidate.
	 * @return void
	 */
	private function refine_kind( $id, Candidate $candidate ) {
		$topic = $this->topics->get( $id );

		if ( null !== $topic && empty( $topic['kind_by_hand'] ) && $topic['kind'] !== $candidate->kind && Kinds::is_a( $candidate->kind, (string) $topic['kind'] ) ) {
			$this->topics->set_kind( $id, $candidate->kind, false );
		}
	}

	/**
	 * Whether a topic found by the label alone has a kind that cannot be the candidate's (neither is a sort of the other; `other` conflicts with nothing).
	 *
	 * @param int       $id        Topic.
	 * @param Candidate $candidate Candidate.
	 * @return bool
	 */
	private function conflicts( $id, Candidate $candidate ) {
		$topic = $this->topics->get( $id );

		return null !== $topic && ! Kinds::compatible( (string) $topic['kind'], $candidate->kind );
	}

	/**
	 * The key of a label under a parent.
	 *
	 * @param int    $parent Parent topic.
	 * @param string $label  Label.
	 * @return string
	 */
	public static function label_key( $parent, $label ) {
		return 'label:' . (int) $parent . '/' . Folding::key( $label );
	}

	/**
	 * Drops the refused topics.
	 *
	 * @param int[] $ids Topics.
	 * @return int[]
	 */
	private function without_refused( array $ids ) {
		return array_values(
			array_filter(
				$ids,
				function ( $id ) {
					$topic = $this->topics->get( $id );

					return null !== $topic && Topics::REFUSED !== $topic['status'];
				}
			)
		);
	}

	/**
	 * Records an ambiguous candidate once: a `candidate` topic that holds the keys it was met with, found again by its first key.
	 *
	 * @param Candidate $candidate The candidate.
	 * @param int       $parent    Parent topic.
	 * @return void
	 */
	private function record_ambiguous( Candidate $candidate, $parent ) {
		$marker = 'ambiguous:' . ( $candidate->keys[0] ?? self::label_key( $parent, $candidate->label ) );

		if ( array() === $this->topics->with_key( $marker ) ) {
			$this->topics->create( $candidate->label, $candidate->kind, 0, Topics::CANDIDATE, array( $marker ) );
		}
	}
}
