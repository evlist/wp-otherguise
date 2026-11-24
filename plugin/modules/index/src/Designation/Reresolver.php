<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The designations that are resolved again, or by a choice.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Index\Designations;
use Otherguise\Index\Mentions;
use Otherguise\Index\Observations;
use Otherguise\Index\Resolver;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a designation **without reading its page**, from its evidence (slice 631):
 *
 * - `run()` resolves again the designations that are ambiguous, which **records the topics that a level hesitated between** (the list of the screen of the
 *   assignments) and settles those that the topics made since have cleared up;
 * - `choose()` is **the decision of a person** among those topics: the choice is **pinned** to the level that hesitated (the next designation that hesitates
 *   in the same way gets it too), the designation is resolved again (**the levels that are missing under the topic chosen are made**), **assigned by hand**,
 *   and the mentions of its posts are made.
 */
final class Reresolver {
	/**
	 * The designations.
	 *
	 * @var Designations
	 */
	private $designations;

	/**
	 * The observations and assignments.
	 *
	 * @var Observations
	 */
	private $observations;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * The resolver.
	 *
	 * @var Resolver
	 */
	private $resolver;

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * Builds the service.
	 *
	 * @param Designations $designations The designations.
	 * @param Observations $observations The observations.
	 * @param Mentions     $mentions     The mentions.
	 * @param Resolver     $resolver     The resolver.
	 * @param Topics       $topics       The topics.
	 */
	public function __construct( Designations $designations, Observations $observations, Mentions $mentions, Resolver $resolver, Topics $topics ) {
		$this->designations = $designations;
		$this->observations = $observations;
		$this->mentions     = $mentions;
		$this->resolver     = $resolver;
		$this->topics       = $topics;
	}

	/**
	 * Resolves again the designations that are ambiguous.
	 *
	 * @param bool $dry   Change nothing, say what would happen.
	 * @param int  $limit At most this many (0 for all).
	 * @return array{looked:int,settled:int,still:int,recorded:int}
	 */
	public function run( $dry = true, $limit = 0 ) {
		$report = array(
			'looked'   => 0,
			'settled'  => 0,
			'still'    => 0,
			'recorded' => 0,
		);

		foreach ( $this->designations->all( array( 'state' => Designations::AMBIGUOUS ) ) as $id => $one ) {
			if ( $limit > 0 && $report['looked'] >= $limit ) {
				break;
			}

			++$report['looked'];

			$candidate = Evidence::candidate( (array) $one['evidence'], (string) $one['source'] );
			$done      = $this->resolver->resolve( $candidate, true, $dry, false );

			if ( $done['topic'] > 0 ) {
				++$report['settled'];

				if ( ! $dry ) {
					$this->settle( (int) $id, (int) $done['topic'], Observations::SYSTEM, (string) ( $candidate->keys[0] ?? 'label' ) );
				}

				continue;
			}

			++$report['still'];

			if ( isset( $done['ambiguity'] ) && ! $dry ) {
				++$report['recorded'];
				$this->designations->set_state(
					(int) $id,
					Designations::AMBIGUOUS,
					array(
						'topic'     => 0,
						'why'       => (string) ( $candidate->keys[0] ?? 'label' ),
						'ambiguity' => $done['ambiguity'],
					)
				);
			}
		}

		return $report;
	}

	/**
	 * A person chooses, among the topics that a level hesitated between, the one that it is.
	 *
	 * @param int $id    The designation.
	 * @param int $topic The topic chosen.
	 * @return array{ok:bool,error:string,topic:int}
	 */
	public function choose( $id, $topic ) {
		$one       = $this->designations->get( (int) $id );
		$ambiguity = null === $one ? null : ( $one['proposal']['ambiguity'] ?? null );
		$fail      = static fn( $error ) => array(
			'ok'    => false,
			'error' => $error,
			'topic' => 0,
		);

		if ( null === $one ) {
			return $fail( __( 'This designation does not exist.', 'otherguise' ) );
		}

		if ( ! is_array( $ambiguity ) || ! in_array( (int) $topic, array_map( 'intval', (array) $ambiguity['candidates'] ), true ) || null === $this->topics->get( (int) $topic ) ) {
			return $fail( __( 'This topic is not one of those that the level hesitated between.', 'otherguise' ) );
		}

		$this->topics->pin( (int) $topic, (string) $ambiguity['key'] );

		$candidate = Evidence::candidate( (array) $one['evidence'], (string) $one['source'] );
		$done      = $this->resolver->resolve( $candidate, true, false, false );

		if ( $done['topic'] <= 0 ) {
			$next = $done['ambiguity']['level'] ?? '';

			$this->designations->set_state(
				(int) $id,
				Designations::AMBIGUOUS,
				array(
					'topic'     => 0,
					'why'       => 'chosen, then another level hesitated',
					'ambiguity' => $done['ambiguity'] ?? array(),
				)
			);

			/* translators: %s: the label of the level that hesitates. */
			return $fail( sprintf( __( 'Chosen. Another level hesitates now (%s): choose again.', 'otherguise' ), $next ) );
		}

		$this->settle( (int) $id, (int) $done['topic'], Observations::HAND, 'chosen' );

		return array(
			'ok'    => true,
			'error' => '',
			'topic' => (int) $done['topic'],
		);
	}

	/**
	 * Assigns a designation, says so, and makes the mentions of its posts.
	 *
	 * @param int    $id    The designation.
	 * @param int    $topic The topic.
	 * @param string $by    `system` or `hand`.
	 * @param string $why   Why.
	 * @return void
	 */
	private function settle( $id, $topic, $by, $why ) {
		$this->observations->assign( $id, $topic, $by );
		$this->designations->set_state(
			$id,
			Designations::ASSIGNED,
			array(
				'topic' => $topic,
				'why'   => $why,
			)
		);

		foreach ( $this->observations->posts_of( $id ) as $post ) {
			$this->mentions->derive_post( (int) $post );
		}
	}
}
