<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The pairs of topics that a person said are not doubles.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Merge;

defined( 'ABSPATH' ) || exit;

/**
 * The pairs of topics that are **not** the same (slice 618, "Not doubles"), kept in an option so that the list of duplicates does not offer them again.
 * A pair is two ids, the lower first (`12-40`). Deleting a topic leaves its pairs, which then match nothing.
 */
final class IgnoredPairs {
	public const OPTION = 'index_not_duplicates';

	/**
	 * Reads the list: `fn(): array`.
	 *
	 * @var callable
	 */
	private $read;

	/**
	 * Writes the list: `fn( array $pairs ): void`.
	 *
	 * @var callable
	 */
	private $write;

	/**
	 * Builds the store.
	 *
	 * @param callable|null $read  Default the option.
	 * @param callable|null $write Default the option.
	 */
	public function __construct( $read = null, $write = null ) {
		$this->read  = $read ?? static function () {
			$saved = get_option( self::OPTION, array() );

			return is_array( $saved ) ? $saved : array();
		};
		$this->write = $write ?? static function ( array $pairs ) {
			update_option( self::OPTION, $pairs, false );
		};
	}

	/**
	 * Tells whether a pair was said not to be a double.
	 *
	 * @param int $a A topic.
	 * @param int $b Another.
	 * @return bool
	 */
	public function has( $a, $b ) {
		return in_array( self::key( $a, $b ), array_map( 'strval', (array) ( $this->read )() ), true );
	}

	/**
	 * Says that the topics of a group are not doubles of one another.
	 *
	 * @param int[] $ids The topics.
	 * @return int How many pairs were added.
	 */
	public function add_group( array $ids ) {
		$ids   = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$have  = array_map( 'strval', (array) ( $this->read )() );
		$added = 0;

		foreach ( $ids as $i => $a ) {
			foreach ( array_slice( $ids, $i + 1 ) as $b ) {
				$key = self::key( $a, $b );

				if ( $a > 0 && $b > 0 && $a !== $b && ! in_array( $key, $have, true ) ) {
					$have[] = $key;
					++$added;
				}
			}
		}

		if ( $added > 0 ) {
			( $this->write )( $have );
		}

		return $added;
	}

	/**
	 * Forgets all the pairs of a group (the person changed their mind).
	 *
	 * @param int[] $ids The topics.
	 * @return void
	 */
	public function remove_group( array $ids ) {
		$drop = array();

		foreach ( array_values( array_unique( array_map( 'intval', $ids ) ) ) as $i => $a ) {
			foreach ( array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), $i + 1 ) as $b ) {
				$drop[ self::key( $a, $b ) ] = true;
			}
		}

		( $this->write )( array_values( array_filter( array_map( 'strval', (array) ( $this->read )() ), static fn( $key ) => ! isset( $drop[ $key ] ) ) ) );
	}

	/**
	 * How many pairs are kept.
	 *
	 * @return int
	 */
	public function count() {
		return count( (array) ( $this->read )() );
	}

	/**
	 * The key of a pair.
	 *
	 * @param int $a A topic.
	 * @param int $b Another.
	 * @return string
	 */
	public static function key( $a, $b ) {
		return min( (int) $a, (int) $b ) . '-' . max( (int) $a, (int) $b );
	}
}
