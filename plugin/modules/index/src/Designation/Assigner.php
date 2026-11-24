<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The assignment of designations to a topic, by a person.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Index\Designations;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Observations;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns designations to a topic (slice 626): **the only gesture left of what was called merge, link and correction** (Eric, 2026-11-24). The assignment is
 * **manual**: a new reading never changes it. The mentions of the posts that observe the designations follow at once. **Nothing is deleted**: the
 * designations and the observations stay, and the previous assignment is reported.
 */
final class Assigner {
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
	 * @param Topics       $topics       The topics.
	 */
	public function __construct( Designations $designations, Observations $observations, Mentions $mentions, Topics $topics ) {
		$this->designations = $designations;
		$this->observations = $observations;
		$this->mentions     = $mentions;
		$this->topics       = $topics;
	}

	/**
	 * Assigns designations to a topic.
	 *
	 * @param int[] $ids   The designations.
	 * @param int   $topic The topic.
	 * @param bool  $dry   Change nothing, say what would be.
	 * @param bool  $force Assign across kinds that exclude each other.
	 * @param int   $from  The topic they leave, when all its designations go: its keys then follow them (0 for none).
	 * @return array{ok:bool,error:string,designations:int,observations:int,posts:int,lines:string[],conflict:array{designation?:int,was?:string,target?:string}} `conflict` says, when
	 *         the kinds exclude each other, **which designation** and **the two kinds** (slice 633).
	 */
	public function assign( array $ids, $topic, $dry = true, $force = false, $from = 0 ) {
		$report = array(
			'ok'           => false,
			'error'        => '',
			'designations' => 0,
			'observations' => 0,
			'posts'        => 0,
			'lines'        => array(),
			'conflict'     => array(),
		);
		$target = $this->topics->get( (int) $topic );

		if ( null === $target ) {
			$report['error'] = 'The topic ' . (int) $topic . ' does not exist.';

			return $report;
		}

		$posts = array();

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$one = $this->designations->get( $id );

			if ( null === $one ) {
				$report['error'] = 'The designation ' . $id . ' does not exist.';

				return $report;
			}

			$have = $this->observations->assignment( $id );
			$was  = null === $have ? null : $this->topics->get( (int) $have['topic'] );

			if ( ! $force && null !== $was && ! Kinds::compatible( (string) $was['kind'], (string) $target['kind'] ) ) {
				$report['error'] = 'The designation ' . $id . ' is assigned to a topic of the kind ' . $was['kind'] . ', which is not one a sort of ' . $target['kind'] . ': use --force to assign it anyway.';
				$report['conflict'] = array(
					'designation' => $id,
					'was'         => (string) $was['kind'],
					'target'      => (string) $target['kind'],
				);

				return $report;
			}

			$where = $this->observations->posts_of( $id );

			++$report['designations'];
			$report['observations'] += count( $where );
			$posts                   = array_merge( $posts, $where );
			$report['lines'][]       = $id . "\t" . $one['key'] . "\t" . ( null === $was ? '(none)' : $was['label'] . ' (' . $was['id'] . ')' ) . ' -> ' . $target['label'] . ' (' . $target['id'] . ')';

			if ( ! $dry ) {
				$this->observations->assign( $id, (int) $topic, Observations::HAND );
				$this->designations->set_state(
					$id,
					Designations::ASSIGNED,
					array(
						'topic' => (int) $topic,
						'why'   => Observations::HAND,
					)
				);
			}
		}

		$posts                 = array_values( array_unique( $posts ) );
		$report['posts']       = count( $posts );

		if ( ! $dry ) {
			foreach ( $posts as $post ) {
				$this->mentions->derive_post( (int) $post );
			}

			$gone = null === $this->topics->get( (int) $from ) ? null : $this->topics->get( (int) $from );

			if ( null !== $gone && (int) $from !== (int) $topic ) {
				$this->topics->add_keys( (int) $topic, (array) $gone['keys'] );
			}
		}

		$report['ok'] = true;

		return $report;
	}
}
