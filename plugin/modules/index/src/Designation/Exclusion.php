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
use Otherguise\Index\NoneTopic;
use Otherguise\Index\Observations;

defined( 'ABSPATH' ) || exit;

/**
 * **Excluding** a motif (slices 635 and 641) is **assigning it, by hand, to the topic `_None_`** (`NoneTopic`): *this motif is not for the index* (a tag that says nothing of
 * what an index lists). It is an assignment like the others: a new reading never changes it, the screen of the assignments shows it and handles it as any topic, and
 * **the mentions of the posts that observe it go** (a motif assigned to `_None_` makes none). Including it again takes the assignment away: the motif waits for the next
 * reading, which proposes a topic as for any motif (or a person assigns it to a topic, which is the same thing).
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
	 * The topic `_None_`.
	 *
	 * @var NoneTopic
	 */
	private $none;

	/**
	 * Builds the service.
	 *
	 * @param Designations $designations The motifs.
	 * @param Observations $observations The observations.
	 * @param Mentions     $mentions     The mentions.
	 * @param NoneTopic    $none         The topic `_None_`.
	 */
	public function __construct( Designations $designations, Observations $observations, Mentions $mentions, NoneTopic $none ) {
		$this->designations = $designations;
		$this->observations = $observations;
		$this->mentions     = $mentions;
		$this->none         = $none;
	}

	/**
	 * Takes motifs out of the index: they are assigned to `_None_`, by hand, and the mentions of their posts follow.
	 *
	 * @param int[] $ids The motifs.
	 * @return array{designations:int,posts:int} How many motifs, and how many posts had their mentions made again.
	 */
	public function exclude( array $ids ) {
		$none  = $this->none->id( true );
		$done  = 0;
		$posts = array();

		if ( $none <= 0 ) {
			return array(
				'designations' => 0,
				'posts'        => 0,
			);
		}

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( null === $this->designations->get( $id ) ) {
				continue;
			}

			$posts = array_merge( $posts, $this->observations->posts_of( $id ) );
			$this->observations->assign( $id, $none, Observations::HAND );
			$this->designations->set_state(
				$id,
				Designations::ASSIGNED,
				array(
					'topic' => $none,
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
	 * Puts motifs back that were assigned to `_None_`: the assignment goes and they wait for the next reading, which proposes a topic. The other motifs are left as they are.
	 *
	 * @param int[] $ids The motifs.
	 * @return int How many.
	 */
	public function include( array $ids ) {
		$none = $this->none->id( false );
		$done = 0;

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$have = $this->observations->assignment( $id );

			if ( $none <= 0 || null === $have || $none !== (int) $have['topic'] ) {
				continue;
			}

			$this->observations->unassign( $id );
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
