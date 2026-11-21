<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * From the classes of an item to a kind.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Wikidata;

use Otherguise\Index\Kinds;

defined( 'ABSPATH' ) || exit;

/**
 * The editable table **Wikidata class → kind**, and the cache of the ancestors of the classes (slice 604).
 *
 * The classes of an item and **their ancestors** are looked up in the table; the **most specific kind wins** (the deepest in the hierarchy of the
 * kinds), and on a tie the order of the table. A class that is not in the table says nothing.
 *
 * **The default table is a proposal that was not checked against Wikidata** (no service was reachable when it was written): the identifiers
 * are the ones its author remembers. `wp otherguise index enrich classes` prints each class of the table with its label read from Wikidata,
 * so that every line can be checked at a glance before it is trusted; `enrich map` changes a line.
 */
final class ClassTable {
	public const OPTION    = 'index_wikidata_classes';
	public const ANCESTORS = 'index_wikidata_ancestors';
	public const TTL       = 7776000;

	/**
	 * The default table: class => kind. **To check against Wikidata.**
	 */
	public const DEFAULTS = array(
		'Q4022'     => 'waterway',   // River.
		'Q47521'    => 'waterway',   // Stream.
		'Q355304'   => 'waterway',   // Watercourse.
		'Q23397'    => 'waterway',   // Lake.
		'Q12284'    => 'waterway',   // Canal.
		'Q486972'   => 'place',      // Human settlement.
		'Q8502'     => 'place',      // Mountain.
		'Q133056'   => 'place',      // Mountain pass.
		'Q39816'    => 'place',      // Valley.
		'Q6256'     => 'place',      // Country.
		'Q56061'    => 'place',      // Administrative territorial entity.
		'Q16521'    => 'species',    // Taxon.
		'Q5'        => 'person',     // Human.
		'Q47461344' => 'work',       // Written work.
		'Q11424'    => 'work',       // Film.
		'Q1656682'  => 'event',      // Event.
		'Q2095'     => 'product',    // Food.
	);

	/**
	 * The options.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * Returns the time.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Returns a random number between two integers: `fn( int $min, int $max ): int`.
	 *
	 * @var callable
	 */
	private $random;

	/**
	 * Builds the table.
	 *
	 * @param Options       $options The options.
	 * @param callable|null $now     Default `time`.
	 * @param callable|null $random  Default `wp_rand`.
	 */
	public function __construct( Options $options, $now = null, $random = null ) {
		$this->options = $options;
		$this->now     = $now ?? 'time';
		$this->random  = $random ?? 'wp_rand';
	}

	/**
	 * The table: class => kind.
	 *
	 * @return array<string, string>
	 */
	public function mapping() {
		$stored = $this->options->get( self::OPTION, null );

		return is_array( $stored ) ? $stored : self::DEFAULTS;
	}

	/**
	 * Adds or changes a line.
	 *
	 * @param string $class Class.
	 * @param string $kind  Kind.
	 * @return true|string
	 */
	public function set( $class, $kind ) {
		if ( ! ItemReader::valid_id( $class ) ) {
			return '"' . $class . '" is not the identifier of a Wikidata class (Q followed by digits).';
		}

		if ( ! Kinds::is_kind( $kind ) ) {
			return 'Unknown kind "' . $kind . '".';
		}

		$this->options->set( self::OPTION, array_merge( $this->mapping(), array( $class => $kind ) ) );

		return true;
	}

	/**
	 * Takes a line off.
	 *
	 * @param string $class Class.
	 * @return bool Whether there was one.
	 */
	public function remove( $class ) {
		$mapping = $this->mapping();

		if ( ! isset( $mapping[ $class ] ) ) {
			return false;
		}

		unset( $mapping[ $class ] );
		$this->options->set( self::OPTION, $mapping );

		return true;
	}

	/**
	 * The classes whose ancestors are not known yet (or no longer: the cache expires).
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public function unknown( array $classes ) {
		$cache = (array) $this->options->get( self::ANCESTORS, array() );
		$now   = (int) ( $this->now )();

		return array_values(
			array_filter(
				array_unique( $classes ),
				static fn( $class ) => ! isset( $cache[ $class ] ) || (int) ( $cache[ $class ]['until'] ?? 0 ) <= $now
			)
		);
	}

	/**
	 * Keeps the ancestors of some classes for about 90 days (with a jitter of 20 %, so that they do not all expire together).
	 *
	 * @param array<string, string[]> $ancestors Class => its ancestors, itself included.
	 * @return void
	 */
	public function remember( array $ancestors ) {
		$cache = (array) $this->options->get( self::ANCESTORS, array() );
		$now   = (int) ( $this->now )();

		foreach ( $ancestors as $class => $list ) {
			$cache[ $class ] = array(
				'a'     => array_values( $list ),
				'until' => $now + (int) ( $this->random )( (int) ( self::TTL * 0.8 ), (int) ( self::TTL * 1.2 ) ),
			);
		}

		$this->options->set( self::ANCESTORS, $cache );
	}

	/**
	 * The kind that the classes of an item stand for, or null when none of them is in the table.
	 *
	 * @param string[] $classes The classes of the item.
	 * @return string|null
	 */
	public function kind_for( array $classes ) {
		$mapping = $this->mapping();
		$cache   = (array) $this->options->get( self::ANCESTORS, array() );
		$keys    = array_keys( $mapping );
		$best    = null;
		$depth   = -1;
		$first   = PHP_INT_MAX;

		foreach ( $classes as $class ) {
			foreach ( (array) ( $cache[ $class ]['a'] ?? array( $class ) ) as $candidate ) {
				$kind = $mapping[ $candidate ] ?? null;

				if ( null === $kind || ! Kinds::is_kind( $kind ) ) {
					continue;
				}

				$level = count( Kinds::ancestors( $kind ) );
				$rank  = (int) array_search( $candidate, $keys, true );

				// The deepest kind wins; on a tie the line that comes first in the table.
				if ( $level > $depth || ( $level === $depth && $rank < $first ) ) {
					$best  = $kind;
					$depth = $level;
					$first = $rank;
				}
			}
		}

		return $best;
	}
}
