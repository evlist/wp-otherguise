<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The bulk actions of the screen of the assignments.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Admin;

use Otherguise\Index\Designation\Exclusion;
use Otherguise\Index\Designations;
use Otherguise\Index\NoneTopic;
use Otherguise\Index\Observations;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * The bulk actions that need no confirmation (slices 630, 635 and 638), a trait of `AssignmentsScreen` kept apart so that the class stays under the size limit of a
 * file.
 */
trait AssignmentsBulk {
	/**
	 * Does a bulk action that needs no confirmation.
	 *
	 * @param string $action `confirm`, `unassign` or `delete`.
	 * @param int[]  $ids    The motifs.
	 * @return array<string, int|string> What to say.
	 */
	private function bulk( $action, array $ids ) {
		$done  = 0;
		$skip  = 0;
		$posts = array();

		// Taking motifs out of the index, and back (slice 635).
		if ( in_array( $action, array( 'exclude', 'include' ), true ) ) {
			$exclusion = new Exclusion( $this->designations, $this->observations, $this->mentions, new NoneTopic( $this->topics ) );
			$count     = 'exclude' === $action ? $exclusion->exclude( $ids )['designations'] : $exclusion->include( $ids );

			return array(
				'did'     => $action,
				'count'   => $count,
				'skipped' => count( $ids ) - $count,
			);
		}

		if ( 'delete' === $action ) {
			$counts = $this->observations->post_counts( $ids );
		}

		foreach ( $ids as $id ) {
			$have = $this->observations->assignment( $id );

			if ( null === $this->designations->get( $id ) || ( 'confirm' === $action && null === $have ) || ( 'unassign' === $action && null === $have ) || ( 'delete' === $action && 0 !== ( $counts[ $id ] ?? 0 ) ) ) {
				++$skip;

				continue;
			}

			if ( 'confirm' === $action && null !== $have ) {
				$this->observations->assign( $id, $have['topic'], Observations::HAND );
			} elseif ( 'unassign' === $action ) {
				$posts = array_merge( $posts, $this->observations->posts_of( $id ) );
				$this->observations->unassign( $id );
				$this->designations->set_state(
					$id,
					Designations::AMBIGUOUS,
					array(
						'topic' => 0,
						'why'   => 'unassigned by hand',
					)
				);
			} elseif ( 'delete' === $action ) {
				$this->observations->unassign( $id );
				$this->designations->delete( $id );
			}

			++$done;
		}

		foreach ( array_unique( $posts ) as $post ) {
			$this->mentions->derive_post( (int) $post );
		}

		return array(
			'did'     => $action,
			'count'   => $done,
			'skipped' => $skip,
		);
	}

	/**
	 * Approves topics (slice 638): the ticked lines of **topics with no motif** and **the topic of each ticked motif**; only a candidate changes (it enters the index),
	 * and a topic that is approved already, or that a motif has not, counts as left as it was.
	 *
	 * @param int[] $designations The motifs that were ticked.
	 * @param int[] $topics       The topics that were ticked (the lines with no motif).
	 * @return array<string, int|string> What to say.
	 */
	private function approve_topics( array $designations, array $topics ) {
		$wanted = array_map( 'intval', $topics );

		foreach ( $designations as $id ) {
			$have = $this->observations->assignment( (int) $id );

			if ( null !== $have ) {
				$wanted[] = (int) $have['topic'];
			}
		}

		$wanted = array_values( array_unique( array_filter( $wanted ) ) );

		if ( array() === $wanted && array() === $designations ) {
			return array( 'error' => __( 'Tick at least one line.', 'otherguise' ) );
		}

		$done = 0;

		foreach ( $wanted as $id ) {
			$topic = $this->topics->get( $id );

			if ( null !== $topic && Topics::CANDIDATE === $topic['status'] ) {
				$this->topics->set_status( $id, Topics::APPROVED );
				++$done;
			}
		}

		return array(
			'did'     => 'approve',
			'count'   => $done,
			'skipped' => count( $wanted ) - $done,
		);
	}
}
