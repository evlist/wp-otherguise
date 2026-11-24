<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Reads a post through the sources and brings its mentions in line.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

use Otherguise\Index\Designation\Keys;

use Otherguise\Index\Source\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * For a post: each enabled source says what it speaks of, the resolver ties it to topics, `Mentions::sync()` writes. A post that is not
 * published has no mention (its mentions are withdrawn).
 */
final class Indexer {
	/**
	 * The resolver.
	 *
	 * @var Resolver
	 */
	private $resolver;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * The sources by slug.
	 *
	 * @var array<string, Source>
	 */
	private $sources = array();

	/**
	 * Tells whether a post is published.
	 *
	 * @var callable
	 */
	private $is_published;

	/**
	 * The designations, when the model of slice 625 is on.
	 *
	 * @var Designations|null
	 */
	private $designations;

	/**
	 * The observations and the assignments, when the model of slice 625 is on.
	 *
	 * @var Observations|null
	 */
	private $observations;

	/**
	 * Builds the indexer.
	 *
	 * @param Resolver          $resolver     The resolver.
	 * @param Mentions          $mentions     The mentions.
	 * @param Source[]          $sources      The sources.
	 * @param callable          $is_published Whether a post is published.
	 * @param Designations|null $designations The designations (with the observations, they make the model of slice 625).
	 * @param Observations|null $observations The observations and the assignments.
	 */
	public function __construct( Resolver $resolver, Mentions $mentions, array $sources, $is_published, ?Designations $designations = null, ?Observations $observations = null ) {
		$this->resolver     = $resolver;
		$this->mentions     = $mentions;
		$this->is_published = $is_published;
		$this->designations = $designations;
		$this->observations = $observations;

		foreach ( $sources as $source ) {
			$this->sources[ $source->id() ] = $source;
		}
	}

	/**
	 * The slugs of the sources.
	 *
	 * @return string[]
	 */
	public function source_ids() {
		return array_keys( $this->sources );
	}

	/**
	 * Indexes a post.
	 *
	 * @param int           $post_id Post.
	 * @param string[]|null $only    Source slugs, or null for all.
	 * @param bool          $dry     Write nothing.
	 * @return array{created:int,resolved:int,ambiguous:int,added:int,removed:int}
	 */
	public function index_post( $post_id, $only = null, $dry = false ) {
		$report    = array(
			'created'   => 0,
			'resolved'  => 0,
			'ambiguous' => 0,
			'added'     => 0,
			'removed'   => 0,
		);
		$published = (bool) ( $this->is_published )( (int) $post_id );

		foreach ( $this->sources as $slug => $source ) {
			if ( null !== $only && ! in_array( $slug, $only, true ) ) {
				continue;
			}

			// A source that does not know the post leaves its mentions as they are (slice 606).
			if ( $source instanceof Partial && ! $source->knows( (int) $post_id ) ) {
				continue;
			}

			$leaves = $source->mention_sources();
			$wanted = array_fill_keys( $leaves, array() );
			$seen   = array();
			$kept   = array_fill_keys( $leaves, array() );

			foreach ( $published ? $source->candidates( (int) $post_id ) : array() as $candidate ) {
				$leaf = null !== $candidate->source && '' !== $candidate->source ? $candidate->source : $leaves[0];
				$name = $this->designate( $candidate, $leaf, $source->is_sure(), $dry, $seen );
				$done = $name['done'];

				++$report[ $done['result'] ];
				// The topics created for the broader terms count as created, not as resolved.
				$report['created'] += $done['new'] - ( Resolver::CREATED === $done['result'] ? 1 : 0 );

				if ( $done['topic'] > 0 ) {
					$wanted[ $leaf ][ $done['topic'] ] = $this->merged( $wanted[ $leaf ][ $done['topic'] ] ?? null, $candidate, $leaf );
				}

				if ( $name['designation'] > 0 ) {
					$kept[ $leaf ][ $name['designation'] ] = $this->merged( $kept[ $leaf ][ $name['designation'] ] ?? null, $candidate, $leaf );
				}
			}

			if ( $dry ) {
				continue;
			}

			if ( null !== $this->observations ) {
				foreach ( $kept as $leaf => $designations ) {
					$this->observations->sync( (int) $post_id, $leaf, $designations );
				}
			}

			foreach ( $wanted as $leaf => $topics ) {
				$synced = $this->mentions->sync(
					(int) $post_id,
					$leaf,
					array_map(
						static fn( $what ) => array(
							'strength' => $what['strength'],
							'via'      => $what['via'],
							'visible'  => $what['visible'] ?? array(),
						),
						$topics
					)
				);

				$report['added']   += $synced['added'];
				$report['removed'] += $synced['removed'];
			}
		}

		return $report;
	}

	/**
	 * Ties a candidate to a designation and to a topic (slice 626). The designation is found by its key, or created; **an assignment that a person made is
	 * kept** (the resolver is not asked, so a new reading never undoes a decision); otherwise the resolver proposes a topic and the assignment follows it.
	 * Without the model of slice 625 (or in a dry run) the candidate is only resolved.
	 *
	 * @param Candidate                                        $candidate The candidate.
	 * @param string                                           $leaf      Its source.
	 * @param bool                                             $sure      Whether the source is sure.
	 * @param bool                                             $dry       Write nothing.
	 * @param array<string, array{done:array,designation:int}> $seen      What was decided in this reading, by key (a designation is resolved once).
	 * @return array{done:array{result:string,topic:int,new:int},designation:int}
	 */
	private function designate( Candidate $candidate, $leaf, $sure, $dry, array &$seen ) {
		if ( null === $this->designations || null === $this->observations || $dry ) {
			return array(
				'done'        => $this->resolver->resolve( $candidate, $sure, $dry ),
				'designation' => 0,
			);
		}

		$key = Keys::of( $candidate, $leaf );

		if ( isset( $seen[ $key ] ) ) {
			$again = $seen[ $key ];

			if ( Resolver::CREATED === $again['done']['result'] ) {
				$again['done'] = array(
					'result' => Resolver::RESOLVED,
					'topic'  => $again['done']['topic'],
					'new'    => 0,
				);
			} else {
				$again['done']['new'] = 0;
			}

			return $again;
		}

		$id = $this->designations->find( $key );
		$id = $id > 0 ? $id : $this->designations->create( $key, $leaf, self::evidence( $candidate ) );

		if ( $id <= 0 ) {
			return array(
				'done'        => $this->resolver->resolve( $candidate, $sure, false ),
				'designation' => 0,
			);
		}

		$have = $this->observations->assignment( $id );

		if ( null !== $have && Observations::HAND === $have['by'] && $this->resolver->exists( $have['topic'] ) ) {
			$done = array(
				'result' => Resolver::RESOLVED,
				'topic'  => $have['topic'],
				'new'    => 0,
			);
		} else {
			$done = $this->resolver->resolve( $candidate, $sure, false );

			if ( $done['topic'] > 0 ) {
				$this->observations->assign( $id, $done['topic'], Observations::SYSTEM );
				$this->designations->set_state(
					$id,
					Designations::ASSIGNED,
					array(
						'topic' => $done['topic'],
						'why'   => (string) ( $candidate->keys[0] ?? 'label' ),
					)
				);
			} else {
				$this->observations->unassign( $id );
				$this->designations->set_state(
					$id,
					Resolver::AMBIGUOUS === $done['result'] ? Designations::AMBIGUOUS : Designations::PENDING,
					array(
						'topic' => 0,
						'why'   => (string) ( $candidate->keys[0] ?? 'label' ),
					)
				);
			}
		}

		$seen[ $key ] = array(
			'done'        => $done,
			'designation' => $id,
		);

		return $seen[ $key ];
	}

	/**
	 * What a candidate saw, to be kept with its designation: the label, the kind, the keys and the chain of broader labels. Never a URL or a coordinate.
	 *
	 * @param Candidate $candidate The candidate.
	 * @return array<string, mixed>
	 */
	private static function evidence( Candidate $candidate ) {
		$chain = array();

		for ( $up = $candidate->parent, $depth = 0; null !== $up && $depth < 30; $up = $up->parent, ++$depth ) {
			array_unshift( $chain, $up->label );
		}

		return array(
			'label' => $candidate->label,
			'kind'  => $candidate->kind,
			'keys'  => array_slice( $candidate->keys, 0, 10 ),
			'above' => $chain,
		);
	}

	/**
	 * What a mention wants once a candidate is added to what was already wanted for the same topic and source: the strongest strength, every
	 * `via`, and the modes in which it is shown (**every mode** as soon as one candidate is shown everywhere).
	 *
	 * @param array<string, mixed>|null $before    What was wanted, or null.
	 * @param Candidate                 $candidate The candidate.
	 * @param string                    $leaf      Its source.
	 * @return array{strength:string,via:string[],visible:?array}
	 */
	private function merged( $before, Candidate $candidate, $leaf ) {
		$modes = array() !== $candidate->modes ? $candidate->modes : Sources::default_visibility( $leaf );
		$modes = array() === $modes ? null : $modes;

		if ( null === $before ) {
			return array(
				'strength' => $candidate->strength,
				'via'      => $candidate->via,
				'visible'  => $modes,
			);
		}

		return array(
			'strength' => Candidate::MAIN === $before['strength'] || Candidate::MAIN === $candidate->strength ? Candidate::MAIN : Candidate::PASSING,
			'via'      => array_values( array_unique( array_merge( $before['via'], $candidate->via ) ) ),
			'visible'  => null === $before['visible'] || null === $modes ? null : array_values( array_unique( array_merge( $before['visible'], $modes ) ) ),
		);
	}
}
