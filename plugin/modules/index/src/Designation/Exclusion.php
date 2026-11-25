<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Taking motifs out of the index, and putting them back.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Index\Designations;
use Otherguise\Index\Mentions;
use Otherguise\Index\Observations;

defined( 'ABSPATH' ) || exit;

/**
 * **Excluding** a motif (slice 635) is a decision of a person that **this motif is not for the index** (a tag that says nothing of what an index lists): it keeps its
 * observations, **has no topic**, and a new reading **leaves it alone** (the state `excluded`: the resolver is not asked, as it is not for a motif that a
 * person assigned). **The mentions of the posts that observe it go.** Including it again makes it wait for the next reading, which proposes a topic as for any motif.
 * This is the case that "no topic" cannot be: a motif with no topic is proposed one again at every reading.
 */
final class Exclusion {
	/**
	 * The motifs.
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
	 * Builds the service.
	 *
	 * @param Designations $designations The motifs.
	 * @param Observations $observations The observations.
	 * @param Mentions     $mentions     The mentions.
	 */
	public function __construct( Designations $designations, Observations $observations, Mentions $mentions ) {
		$this->designations = $designations;
		$this->observations = $observations;
		$this->mentions     = $mentions;
	}

	/**
	 * Takes motifs out of the index: the assignment goes, the state is `excluded`, the mentions of their posts follow.
	 *
	 * @param int[] $ids The motifs.
	 * @return array{designations:int,posts:int} How many motifs, and how many posts had their mentions made again.
	 */
	public function exclude( array $ids ) {
		$done  = 0;
		$posts = array();

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( null === $this->designations->get( $id ) ) {
				continue;
			}

			$posts = array_merge( $posts, $this->observations->posts_of( $id ) );
			$this->observations->unassign( $id );
			$this->designations->set_state(
				$id,
				Designations::EXCLUDED,
				array(
					'topic' => 0,
					'why'   => Observations::HAND,
				)
			);
			++$done;
		}

		$posts = array_values( array_unique( $posts ) );

		foreach ( $posts as $post ) {
			$this->mentions->derive_post( (int) $post );
		}

		return array(
			'designations' => $done,
			'posts'        => count( $posts ),
		);
	}

	/**
	 * Puts excluded motifs back: they wait for the next reading, which proposes a topic. Motifs that are not excluded are left as they are.
	 *
	 * @param int[] $ids The motifs.
	 * @return int How many.
	 */
	public function include( array $ids ) {
		$done = 0;

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$one = $this->designations->get( $id );

			if ( null === $one || Designations::EXCLUDED !== $one['state'] ) {
				continue;
			}

			$this->designations->set_state(
				$id,
				Designations::PENDING,
				array(
					'topic' => 0,
					'why'   => 'included again',
				)
			);
			++$done;
		}

		return $done;
	}
}
