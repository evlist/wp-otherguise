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
	 * Builds the indexer.
	 *
	 * @param Resolver $resolver     The resolver.
	 * @param Mentions $mentions     The mentions.
	 * @param Source[] $sources      The sources.
	 * @param callable $is_published Whether a post is published.
	 */
	public function __construct( Resolver $resolver, Mentions $mentions, array $sources, $is_published ) {
		$this->resolver     = $resolver;
		$this->mentions     = $mentions;
		$this->is_published = $is_published;

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

			foreach ( $published ? $source->candidates( (int) $post_id ) : array() as $candidate ) {
				$done = $this->resolver->resolve( $candidate, $source->is_sure(), $dry );

				++$report[ $done['result'] ];
				// The topics created for the broader terms count as created, not as resolved.
				$report['created'] += $done['new'] - ( Resolver::CREATED === $done['result'] ? 1 : 0 );

				if ( $done['topic'] > 0 ) {
					$leaf = null !== $candidate->source && '' !== $candidate->source ? $candidate->source : $leaves[0];

					$wanted[ $leaf ][ $done['topic'] ] = $this->merged( $wanted[ $leaf ][ $done['topic'] ] ?? null, $candidate, $leaf );
				}
			}

			if ( $dry ) {
				continue;
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
