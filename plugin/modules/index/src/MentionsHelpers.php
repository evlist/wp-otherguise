<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Two small helpers of the mentions.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers of `Mentions` (kept apart for the size of the file).
 */
trait MentionsHelpers {
	/**
	 * Two descriptions of one mention found through two topics.
	 *
	 * @param array $a First.
	 * @param array $b Second.
	 * @return array{sources:string[],strength:string}
	 */
	private function merged( array $a, array $b ) {
		$sources = array_values( array_unique( array_merge( $a['sources'], $b['sources'] ) ) );
		sort( $sources );

		return array(
			'sources'  => $sources,
			'strength' => ( Candidate::MAIN === $a['strength'] || Candidate::MAIN === $b['strength'] ) ? Candidate::MAIN : Candidate::PASSING,
			'details'  => $a['details'] + $b['details'],
		);
	}

	/**
	 * Whether a list of qualifications holds a value.
	 *
	 * @param array<string, array> $known     Qualifications by predicate.
	 * @param string               $predicate Predicate.
	 * @param string               $value     Value.
	 * @return bool
	 */
	private function has( array $known, $predicate, $value ) {
		foreach ( $known[ $predicate ] ?? array() as $statement ) {
			if ( (string) $statement->object()->key() === $value ) {
				return true;
			}
		}

		return false;
	}
}
