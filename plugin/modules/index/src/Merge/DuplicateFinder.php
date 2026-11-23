<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The topics that may be the same.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Merge;

use Otherguise\Index\Folding;
use Otherguise\Index\Kinds;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the pairs of approved topics that may be one (slice 616), for a person to look at (nothing is merged): **the same label once what is in
 * parentheses is left out** (*Aragon* and *Aragon (communauté autonome)*), or **labels that differ by a letter or two under the same parent**
 * (*Iturtzeta* and *Iturzaeta*). Two topics of kinds that cannot be one, or one below the other, are not offered.
 */
final class DuplicateFinder {
	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * Builds the finder.
	 *
	 * @param Topics $topics The topics.
	 */
	public function __construct( Topics $topics ) {
		$this->topics = $topics;
	}

	/**
	 * The pairs.
	 *
	 * @return array<int, array{a:array,b:array,why:string}> The topic with the lower id first.
	 */
	public function pairs() {
		$topics = array_values( $this->topics->all( array( 'status' => Topics::APPROVED ) ) );
		$found  = array();
		$plain  = array();
		$near   = array();

		foreach ( $topics as $topic ) {
			$base = Folding::key( trim( (string) preg_replace( '/\s*\([^)]*\)\s*/u', ' ', (string) $topic['label'] ) ) );

			if ( '' !== $base ) {
				$plain[ $base ][] = $topic;
				$near[ (int) $topic['parent'] ][] = array(
					'topic' => $topic,
					'key'   => $base,
				);
			}
		}

		foreach ( $plain as $group ) {
			$this->pairs_of( $group, 'same label without the parentheses', $found );
		}

		foreach ( $near as $group ) {
			$count = count( $group );

			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$x = $group[ $i ]['key'];
					$y = $group[ $j ]['key'];

					if ( $x !== $y && min( strlen( $x ), strlen( $y ) ) >= 7 && levenshtein( $x, $y ) <= 2 ) {
						$this->pairs_of( array( $group[ $i ]['topic'], $group[ $j ]['topic'] ), 'labels that differ by a letter or two', $found );
					}
				}
			}
		}

		usort( $found, static fn( $p, $q ) => array( $p['a']['id'], $p['b']['id'] ) <=> array( $q['a']['id'], $q['b']['id'] ) );

		return array_values( $found );
	}

	/**
	 * Adds the pairs of a group that can be one.
	 *
	 * @param array<int, array>    $group The topics.
	 * @param string               $why   Why they are offered.
	 * @param array<string, array> $found The pairs (modified), by their two ids.
	 * @return void
	 */
	private function pairs_of( array $group, $why, array &$found ) {
		$count = count( $group );

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$a = $group[ $i ];
				$b = $group[ $j ];

				if ( $a['id'] > $b['id'] ) {
					list( $a, $b ) = array( $b, $a );
				}

				// Two places of the same name under two different parents are homonyms (Beaufort in Savoie and in the Jura), not doubles.
				$homonyms = (int) $a['parent'] > 0 && (int) $b['parent'] > 0 && (int) $a['parent'] !== (int) $b['parent'];

				if ( $homonyms || ! Kinds::compatible( (string) $a['kind'], (string) $b['kind'] ) || $this->related( $a, $b ) ) {
					continue;
				}

				$found[ $a['id'] . '-' . $b['id'] ] = $found[ $a['id'] . '-' . $b['id'] ] ?? array(
					'a'   => $a,
					'b'   => $b,
					'why' => $why,
				);
			}
		}
	}

	/**
	 * Whether one topic is the parent of the other (a commune and its hamlet are not duplicates).
	 *
	 * @param array $a A topic.
	 * @param array $b Another.
	 * @return bool
	 */
	private function related( array $a, array $b ) {
		return (int) $a['parent'] === (int) $b['id'] || (int) $b['parent'] === (int) $a['id'];
	}
}
