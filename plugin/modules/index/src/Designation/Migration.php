<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The conversion of the mentions made by hand into observations.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Index\Designations;
use Otherguise\Index\Mentions;
use Otherguise\Index\Observations;

defined( 'ABSPATH' ) || exit;

/**
 * Converts the mentions made by a person (the source `hand`) into a motif assigned by hand and one observation each (slice 626). **The mentions of
 * the other sources cannot be converted** (they have no evidence): reading the pages again makes them as motifs and observations. It can be run
 * twice: what exists is found again.
 */
final class Migration {
	/**
	 * Returns the service of the statements; called when needed.
	 *
	 * @var callable
	 */
	private $statements;

	/**
	 * The motifs.
	 *
	 * @var Designations
	 */
	private $designations;

	/**
	 * The observations.
	 *
	 * @var Observations
	 */
	private $observations;

	/**
	 * Builds the service.
	 *
	 * @param callable     $statements   Returns the `Statements` service.
	 * @param Designations $designations The motifs.
	 * @param Observations $observations The observations.
	 */
	public function __construct( $statements, Designations $designations, Observations $observations ) {
		$this->statements   = $statements;
		$this->designations = $designations;
		$this->observations = $observations;
	}

	/**
	 * The mentions made by hand: post id => topic ids.
	 *
	 * @return array<int, int[]>
	 */
	public function hand_mentions() {
		$statements = ( $this->statements )();
		$found      = array();
		$mentions   = $statements->match( null, Mentions::MENTIONS );
		$about      = $statements->qualifications_of( $mentions );

		foreach ( $mentions as $mention ) {
			foreach ( $about[ $mention->id() ][ Mentions::SOURCE ] ?? array() as $source ) {
				if ( Mentions::HAND === (string) $source->object()->key() ) {
					$found[ (int) $mention->subject()->id() ][] = (int) $mention->object()->id();
				}
			}
		}

		return $found;
	}

	/**
	 * Converts them.
	 *
	 * @param bool $dry Count only.
	 * @return array{posts:int,observations:int,created:int}
	 */
	public function run( $dry = true ) {
		$report = array(
			'posts'        => 0,
			'observations' => 0,
			'created'      => 0,
		);

		foreach ( $this->hand_mentions() as $post => $topics ) {
			++$report['posts'];
			$wanted = $dry ? array() : $this->observations->of_post( (int) $post, Mentions::HAND );

			foreach ( $topics as $topic ) {
				$key = Keys::by_hand( $topic );
				$id  = $this->designations->find( $key );

				if ( 0 === $id ) {
					++$report['created'];
					$id = $dry ? 0 : $this->designations->create(
						$key,
						Mentions::HAND,
						array(
							'label' => 'by hand',
							'topic' => $topic,
						)
					);
				}

				++$report['observations'];

				if ( $dry || $id <= 0 ) {
					continue;
				}

				$this->observations->assign( $id, $topic, Observations::HAND );
				$this->designations->set_state(
					$id,
					Designations::ASSIGNED,
					array(
						'topic' => $topic,
						'why'   => Mentions::HAND,
					)
				);
				$wanted[ $id ] = $wanted[ $id ] ?? array( 'strength' => 'main' );
			}

			if ( ! $dry ) {
				$this->observations->sync( (int) $post, Mentions::HAND, $wanted );
			}
		}

		return $report;
	}
}
