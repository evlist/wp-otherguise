<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The stops of a day of photographs.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Photo;

defined( 'ABSPATH' ) || exit;

/**
 * Groups the photographs of a post into stops (slice 606), a pure function.
 *
 * - A photograph with a **precise time** is put in time order, and **consecutive photographs within `chain_metres` of the previous one** form a stop (the
 *   chain rule measured on Eric's day).
 * - A photograph with **no precise time** (a date with a day only is noon) is grouped **by place only**: the same `site` and `settlement`.
 * - A stop **gives a place** when it has at least `photos_min` photographs **or** lasts at least `minutes_min` minutes (the duration of a stop is
 *   the time between its first and last photograph, which only precise times give).
 */
final class Stops {
	public const DEFAULTS = array(
		'photos_min'   => 3,
		'minutes_min'  => 20,
		'chain_metres' => 50,
	);

	/**
	 * Groups photographs.
	 *
	 * @param array<int, array{url:string,lat:float,lon:float,time:?int,place:?array}> $photos   The photographs (`time` is null when it is not precise).
	 * @param array<string, int>                                                       $settings `photos_min`, `minutes_min`, `chain_metres`.
	 * @return array<int, array{photos:array<int,array>,minutes:int,place:?array,qualifies:bool}>
	 */
	public static function group( array $photos, array $settings = array() ) {
		$settings = array_merge( self::DEFAULTS, array_intersect_key( $settings, self::DEFAULTS ) );
		$timed    = array_values( array_filter( $photos, static fn( $photo ) => null !== $photo['time'] ) );
		$untimed  = array_values( array_filter( $photos, static fn( $photo ) => null === $photo['time'] ) );
		$stops    = array();

		usort( $timed, static fn( $a, $b ) => $a['time'] <=> $b['time'] );

		foreach ( $timed as $photo ) {
			$last = array() === $stops ? null : count( $stops ) - 1;

			if ( null !== $last && null !== $stops[ $last ]['timed'] && self::metres( end( $stops[ $last ]['photos'] ), $photo ) <= $settings['chain_metres'] ) {
				$stops[ $last ]['photos'][] = $photo;

				continue;
			}

			$stops[] = array(
				'photos' => array( $photo ),
				'timed'  => true,
				'key'    => '',
			);
		}

		foreach ( $untimed as $photo ) {
			$key = self::place_key( $photo['place'] );

			// A photograph with no place yet is left to the second pass: it cannot be grouped by place.
			if ( '' === $key ) {
				$stops[] = array(
					'photos' => array( $photo ),
					'timed'  => null,
					'key'    => '',
				);

				continue;
			}

			foreach ( $stops as $index => $stop ) {
				if ( null === $stop['timed'] && $stop['key'] === $key ) {
					$stops[ $index ]['photos'][] = $photo;

					continue 2;
				}
			}

			$stops[] = array(
				'photos' => array( $photo ),
				'timed'  => null,
				'key'    => $key,
			);
		}

		return array_map(
			static function ( $stop ) use ( $settings ) {
				$times   = array_filter( array_column( $stop['photos'], 'time' ), static fn( $time ) => null !== $time );
				$minutes = array() === $times ? 0 : (int) floor( ( max( $times ) - min( $times ) ) / 60 );
				$place   = null;

				foreach ( $stop['photos'] as $photo ) {
					if ( is_array( $photo['place'] ) ) {
						$place = $photo['place'];
						break;
					}
				}

				return array(
					'photos'    => $stop['photos'],
					'minutes'   => $minutes,
					'place'     => $place,
					'qualifies' => count( $stop['photos'] ) >= $settings['photos_min'] || $minutes >= $settings['minutes_min'],
				);
			},
			$stops
		);
	}

	/**
	 * The distance between two photographs, in metres (haversine).
	 *
	 * @param array $a A photograph.
	 * @param array $b Another.
	 * @return float
	 */
	public static function metres( array $a, array $b ) {
		$p1    = deg2rad( (float) $a['lat'] );
		$p2    = deg2rad( (float) $b['lat'] );
		$delta = deg2rad( (float) $b['lon'] - (float) $a['lon'] );
		$h     = sin( ( $p2 - $p1 ) / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $delta / 2 ) ** 2;

		return 12742000 * asin( min( 1, sqrt( $h ) ) );
	}

	/**
	 * What two photographs without a time must share to be one stop: their `site` and `settlement`.
	 *
	 * @param array|null $place The levels of the place, or null.
	 * @return string Empty when there is no place.
	 */
	private static function place_key( $place ) {
		if ( ! is_array( $place ) ) {
			return '';
		}

		$site       = (string) ( $place['site'] ?? '' );
		$settlement = (string) ( $place['settlement'] ?? '' );

		return '' === $site . $settlement ? '' : $site . '|' . $settlement;
	}
}
