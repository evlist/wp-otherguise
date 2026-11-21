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

defined( 'ABSPATH' ) || exit;

/**
 * For a post: each enabled source says what it speaks of, the resolver ties it to entries, `Mentions::sync()` writes. A post that is not
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

			$wanted = array();

			foreach ( $published ? $source->candidates( (int) $post_id ) : array() as $candidate ) {
				$done = $this->resolver->resolve( $candidate, $source->is_sure(), $dry );

				++$report[ $done['result'] ];
				// The entries created for the broader terms count as created, not as resolved.
				$report['created'] += $done['new'] - ( Resolver::CREATED === $done['result'] ? 1 : 0 );

				if ( $done['entry'] > 0 && ( ! isset( $wanted[ $done['entry'] ] ) || Candidate::MAIN === $candidate->strength ) ) {
					$wanted[ $done['entry'] ] = $candidate->strength;
				}
			}

			if ( $dry ) {
				continue;
			}

			$synced = $this->mentions->sync( (int) $post_id, $slug, $wanted );

			$report['added']   += $synced['added'];
			$report['removed'] += $synced['removed'];
		}

		return $report;
	}
}
