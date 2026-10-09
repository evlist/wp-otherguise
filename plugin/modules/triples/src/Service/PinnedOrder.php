<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Pinned items in an ordered list.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the pinned items of a list at their rank. A pure function, without WordPress or database.
 */
final class PinnedOrder {
	/**
	 * Merges the pinned items into an ordered list.
	 *
	 * The items without a pin keep their order and fill the free places. A pinned item sits at its rank (ranks start at 1, a lower value
	 * is read as 1). When a place is taken, the item goes to the next free place, or to the nearest free place before it when none is
	 * left. An item whose rank is beyond the end of the list goes last, after the others, in the order of the ranks. Items pinned to the
	 * same rank are placed in the order of their ids. Pins of items that are not in the list are ignored.
	 *
	 * @param int[]           $ordered_ids Ids in the natural order.
	 * @param array<int, int> $pins        Rank by id.
	 * @return int[] The ids, pinned items included.
	 */
	public static function merge( array $ordered_ids, array $pins ) {
		$ordered_ids = array_values( $ordered_ids );
		$count       = count( $ordered_ids );
		$present     = array_flip( $ordered_ids );
		$wanted      = array();

		foreach ( $pins as $id => $rank ) {
			if ( isset( $present[ $id ] ) ) {
				$wanted[] = array( max( 1, (int) $rank ), (int) $id );
			}
		}

		if ( array() === $wanted ) {
			return $ordered_ids;
		}

		usort(
			$wanted,
			static function ( array $a, array $b ) {
				return $a <=> $b;
			}
		);

		$places  = array();
		$beyond  = array();
		$pinned  = array();

		foreach ( $wanted as list( $rank, $id ) ) {
			$pinned[ $id ] = true;

			if ( $rank > $count ) {
				$beyond[] = $id;
				continue;
			}

			$places[ self::free_place( $places, $rank, $count ) ] = $id;
		}

		foreach ( array_reverse( $beyond ) as $id ) {
			$places[ self::free_place( $places, $count, $count ) ] = $id;
		}

		$result = array();
		$rest   = array_values( array_filter( $ordered_ids, static fn( $id ) => ! isset( $pinned[ $id ] ) ) );

		for ( $place = 1; $place <= $count; $place++ ) {
			$result[] = $places[ $place ] ?? array_shift( $rest );
		}

		return $result;
	}

	/**
	 * Finds the free place nearest to a rank: the rank itself, the next ones, then the previous ones.
	 *
	 * @param array<int, int> $places Taken places, from 1.
	 * @param int             $rank   Wanted place.
	 * @param int             $count  Number of places.
	 * @return int
	 */
	private static function free_place( array $places, $rank, $count ) {
		for ( $place = $rank; $place <= $count; $place++ ) {
			if ( ! isset( $places[ $place ] ) ) {
				return $place;
			}
		}

		for ( $place = $rank - 1; $place >= 1; $place-- ) {
			if ( ! isset( $places[ $place ] ) ) {
				return $place;
			}
		}

		return $count;
	}
}
