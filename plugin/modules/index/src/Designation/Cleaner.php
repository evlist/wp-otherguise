<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The cleaning of the motifs.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Index\Designations;
use Otherguise\Index\Observations;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes the motifs that nothing needs (slice 626): **those that the system proposed and that no post observes any more**. A motif that a
 * person assigned stays, observed or not, until a person deletes it (Eric, 2026-11-24).
 */
final class Cleaner {
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
	 * Builds the cleaner.
	 *
	 * @param Designations $designations The motifs.
	 * @param Observations $observations The observations.
	 */
	public function __construct( Designations $designations, Observations $observations ) {
		$this->designations = $designations;
		$this->observations = $observations;
	}

	/**
	 * The motifs that can go.
	 *
	 * @param bool $everything Also the ones that a person assigned.
	 * @return int[]
	 */
	public function candidates( $everything = false ) {
		$free = $this->observations->unobserved( array_keys( $this->designations->all() ) );

		return array_values(
			array_filter(
				$free,
				fn( $id ) => $everything || Observations::HAND !== ( $this->observations->assignment( (int) $id )['by'] ?? '' )
			)
		);
	}

	/**
	 * The motifs that a reset would leave nothing to (slice 626): once the observations are dropped, **every motif that the system proposed**.
	 *
	 * @param bool $everything Also the ones that a person assigned.
	 * @return int[]
	 */
	public function after_reset( $everything = false ) {
		return array_values(
			array_filter(
				array_map( 'intval', array_keys( $this->designations->all() ) ),
				fn( $id ) => $everything || Observations::HAND !== ( $this->observations->assignment( $id )['by'] ?? '' )
			)
		);
	}

	/**
	 * Deletes them (and their assignment).
	 *
	 * @param int[] $ids The motifs.
	 * @return int How many.
	 */
	public function delete( array $ids ) {
		foreach ( $ids as $id ) {
			$this->observations->unassign( (int) $id );
			$this->designations->delete( (int) $id );
		}

		return count( $ids );
	}
}
