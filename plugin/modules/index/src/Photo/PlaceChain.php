<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The topics of a place.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Photo;

use Otherguise\Index\Candidate;
use Otherguise\Index\Folding;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the answer of Media Helper for a place into candidates (slice 606), a pure function. **Every level is a topic**, from the country down:
 * `country` > the first division (`admin1`) > the second (`admin2`) > a third that some countries have (`admin3`) > `settlement` (`commune`) > the lowest place
 * (`site`, else `locality`, else `district`; kind `site`). A level that is empty is absent, and two levels may have the same name (slice 614); with no commune the
 * place hangs from the highest level that exists. The leaf has a `wikidata:`
 * key **only when the item of the answer is the item of that very place** (`levels.kind` starts with `place `): the item belongs to the object found,
 * which can be a road or a house, and never to the commune.
 */
final class PlaceChain {
	/**
	 * The places below the commune, the first that exists being the lowest topic.
	 */
	private const BELOW = array( 'site', 'locality', 'district' );

	/**
	 * The candidate of a place: the lowest topic, with its parents above it.
	 *
	 * The levels come **from the numbered administrative levels of OpenStreetMap when the answer has them** (slice 617): `level4` is the first division
	 * (a region, a communauté autonome, a Land), `level6` the second (a département, a province, a powiat), `level7` a third one that exists in some
	 * countries (a comarca, an arrondissement). The names `state` and `county` of the answer are the fallback **only when the answer has no numbered level at all** (an
	 * old answer of the cache): in Spain `county` is the comarca and the province is `level6`, so a missing `level6` is a missing province, not the comarca. The commune is `settlement`. A third division that is the commune or repeats the second is not one.
	 *
	 * @param array  $place  The `place` of the answer (its `levels`, its `answer`).
	 * @param string $source The source of the mention.
	 * @param array  $via    What produced it.
	 * @return Candidate|null Null when the place has no name at all.
	 */
	public static function candidate( array $place, $source, array $via ) {
		$levels = (array) ( $place['levels'] ?? array() );
		$admin  = (array) ( $levels['admin'] ?? array() );
		$name   = static fn( $value ) => trim( (string) $value );
		$wanted = array(
			array( $name( $levels['country'] ?? '' ), 'country' ),
			array( '' !== $name( $admin['level4'] ?? '' ) ? $name( $admin['level4'] ) : $name( $levels['state'] ?? '' ), 'admin1' ),
			array( '' !== $name( $admin['level6'] ?? '' ) ? $name( $admin['level6'] ) : ( array() === $admin ? $name( $levels['county'] ?? '' ) : '' ), 'admin2' ),
			array( $name( $admin['level7'] ?? '' ), 'admin3' ),
			array( $name( $levels['settlement'] ?? '' ), 'commune' ),
		);
		$chain  = array();

		foreach ( $wanted as $level ) {
			if ( '' === $level[0] ) {
				continue;
			}

			// Only a first division that repeats the country is not one (the answer fills it so for a country that has none); a third division that is
			// the commune, or the second division, is the same place seen once more (Poland: the gmina is level 7 and the commune).
			if ( ( 'admin1' === $level[1] && array() !== $chain && $chain[0]['name'] === $level[0] ) || ( 'admin3' === $level[1] && self::is_one_of( $level[0], array( $name( $levels['settlement'] ?? '' ), $name( $admin['level6'] ?? '' ), $name( $admin['level4'] ?? '' ) ) ) ) ) {
				continue;
			}

			$chain[] = array(
				'name' => $level[0],
				'kind' => $level[1],
			);
		}

		foreach ( self::BELOW as $level ) {
			$below = $name( $levels[ $level ] ?? '' );

			if ( '' !== $below ) {
				$chain[] = array(
					'name' => $below,
					'kind' => 'site',
				);
				break;
			}
		}

		if ( array() === $chain ) {
			return null;
		}

		$parent = null;
		$last   = count( $chain ) - 1;
		$wiki   = self::wikidata( $place );

		foreach ( $chain as $position => $level ) {
			$keys   = $position === $last && '' !== $wiki ? array( 'wikidata:' . $wiki ) : array();
			$parent = new Candidate( $level['name'], $level['kind'], $keys, $parent, Candidate::PASSING );
		}

		return $parent->from( $source, $via );
	}

	/**
	 * Whether a name is one of some others, the case and the accents left out.
	 *
	 * @param string   $name   The name.
	 * @param string[] $others The others.
	 * @return bool
	 */
	private static function is_one_of( $name, array $others ) {
		foreach ( $others as $other ) {
			if ( '' !== $other && Folding::key( $other ) === Folding::key( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The Wikidata item of the place found, when it is the item of a place and not of a road or a house.
	 *
	 * @param array $place The `place` of the answer.
	 * @return string `Q42`, or an empty string.
	 */
	public static function wikidata( array $place ) {
		if ( 0 !== strpos( (string) ( $place['levels']['kind'] ?? '' ), 'place ' ) ) {
			return '';
		}

		$item = (string) ( $place['answer']['features'][0]['properties']['geocoding']['extra']['wikidata'] ?? '' );

		return 1 === preg_match( '/^Q[1-9][0-9]*$/', $item ) ? $item : '';
	}
}
