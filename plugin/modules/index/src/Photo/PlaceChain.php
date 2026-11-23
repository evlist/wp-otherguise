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

defined( 'ABSPATH' ) || exit;

/**
 * Turns the answer of Media Helper for a place into candidates (slice 606), a pure function. **Every level is a topic**, from the country down:
 * `country` > `state` (kind `admin1`) > `county` (`admin2`) > `settlement` (`commune`) > the lowest place (`site`, else `locality`, else `district`; kind `site`).
 * A level that is empty is absent, and two levels may have the same name (slice 614); with no commune the place hangs from the highest level that exists. The leaf has a `wikidata:`
 * key **only when the item of the answer is the item of that very place** (`levels.kind` starts with `place `): the item belongs to the object found,
 * which can be a road or a house, and never to the commune.
 */
final class PlaceChain {
	/**
	 * The levels above the commune, from the top, with the kind of each (slice 614): the level of a place is its meaning, not its name in a country.
	 */
	private const ABOVE = array(
		'country'    => 'country',
		'state'      => 'admin1',
		'county'     => 'admin2',
		'settlement' => 'commune',
	);

	/**
	 * The places below the commune, the first that exists being the lowest topic.
	 */
	private const BELOW = array( 'site', 'locality', 'district' );

	/**
	 * The candidate of a place: the lowest topic, with its parents above it.
	 *
	 * @param array  $place  The `place` of the answer (its `levels`, its `answer`).
	 * @param string $source The source of the mention.
	 * @param array  $via    What produced it.
	 * @return Candidate|null Null when the place has no name at all.
	 */
	public static function candidate( array $place, $source, array $via ) {
		$levels = (array) ( $place['levels'] ?? array() );
		$chain  = array();

		foreach ( self::ABOVE as $level => $kind ) {
			$name = trim( (string) ( $levels[ $level ] ?? '' ) );

			// A level that is empty is absent. Two levels with the same name are two topics (Zaragoza the province and the city, Paris the
			// department and the commune: the key of a topic holds its parent); only a first division that repeats the country is not one
			// (the answer fills it with the name of the country for a country that has none).
			if ( '' === $name || ( 'admin1' === $kind && array() !== $chain && $chain[0]['name'] === $name ) ) {
				continue;
			}

			$chain[] = array(
				'name' => $name,
				'kind' => $kind,
			);
		}

		foreach ( self::BELOW as $level ) {
			$name = trim( (string) ( $levels[ $level ] ?? '' ) );

			if ( '' !== $name ) {
				$chain[] = array(
					'name' => $name,
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
