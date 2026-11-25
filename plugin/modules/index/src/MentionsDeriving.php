<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What `Mentions` derives from the observations.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The mentions of a post, made again from its observations (slice 626): when a person assigns a motif to another topic, **the mentions of the posts
 * that observe it follow**, without reading the pages again. A trait of `Mentions` (it uses `sync()`), kept apart so that the class stays under the size
 * limit of a file.
 */
trait MentionsDeriving {
	/**
	 * Makes the mentions of a post, for every source that has observations or mentions in it, from the observations: each motif counts for the topic it
	 * is assigned to, a motif with no assignment counts for none.
	 *
	 * @param int $post_id Post.
	 * @return array{added:int,removed:int}
	 */
	public function derive_post( $post_id ) {
		$report = array(
			'added'   => 0,
			'removed' => 0,
		);

		if ( null === $this->observations ) {
			return $report;
		}

		$wanted = array();
		$leaves = array();

		foreach ( $this->observations->of_post( (int) $post_id ) as $designation => $what ) {
			$leaves[ $what['source'] ] = true;
			$assigned                  = $this->observations->assignment( (int) $designation );

			if ( null === $assigned ) {
				continue;
			}

			$topic = (int) $assigned['topic'];

			// A motif that is assigned to `_None_` is not for the index: it makes no mention (slice 641).
			if ( $this->skips( $topic ) ) {
				continue;
			}

			$was = $wanted[ $what['source'] ][ $topic ] ?? null;

			$wanted[ $what['source'] ][ $topic ] = null === $was ? array(
				'strength' => $what['strength'],
				'via'      => $what['via'],
				'visible'  => $what['visible'] ?? array(),
			) : array(
				'strength' => Candidate::MAIN === $was['strength'] || Candidate::MAIN === $what['strength'] ? Candidate::MAIN : Candidate::PASSING,
				'via'      => array_values( array_unique( array_merge( $was['via'], $what['via'] ) ) ),
				'visible'  => array() === $was['visible'] || null === $what['visible'] ? array() : array_values( array_unique( array_merge( $was['visible'], $what['visible'] ) ) ),
			);
		}

		foreach ( array_keys( $leaves ) as $leaf ) {
			$done                = $this->sync( (int) $post_id, (string) $leaf, $wanted[ $leaf ] ?? array() );
			$report['added']   += $done['added'];
			$report['removed'] += $done['removed'];
		}

		return $report;
	}
}
