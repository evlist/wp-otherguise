<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The key of a designation.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Index\Candidate;
use Otherguise\Index\Folding;

defined( 'ABSPATH' ) || exit;

/**
 * What makes two observations the same designation (slice 625, 626), a pure function: **the source** and **the strongest identity that the candidate
 * carries**, whatever the post. A link to one Wikipedia page, a tag, a commune given by the photographs are each **one** designation, however many posts
 * show them.
 *
 * - an identifier of Wikidata, then of Wikipedia, then of OpenStreetMap, then the term of a tag or a category, when the candidate has one;
 * - else the **chain of its labels** from the broadest to the narrowest (a mark with no identifier is designated by its text; a place by its levels).
 */
final class Keys {
	/**
	 * The longest key, in characters (a longer one keeps its beginning and a short hash of the whole).
	 */
	public const MAX = 180;

	/**
	 * The prefixes of the identifiers, strongest first.
	 *
	 * @var string[]
	 */
	private const IDENTIFIERS = array( 'wikidata:', 'wikipedia:', 'osm:', 'term:' );

	/**
	 * The key of the designation that a candidate makes.
	 *
	 * @param Candidate $candidate The candidate.
	 * @param string    $leaf      The source of its mention (`post/link`).
	 * @return string
	 */
	public static function of( Candidate $candidate, $leaf ) {
		return self::bound( $leaf . '|' . self::identity( $candidate ) );
	}

	/**
	 * The key of the designation of a mention made by hand.
	 *
	 * @param int $topic Topic.
	 * @return string
	 */
	public static function by_hand( $topic ) {
		return 'hand|topic:' . (int) $topic;
	}

	/**
	 * The strongest identity of a candidate.
	 *
	 * @param Candidate $candidate The candidate.
	 * @return string
	 */
	private static function identity( Candidate $candidate ) {
		foreach ( self::IDENTIFIERS as $prefix ) {
			foreach ( $candidate->keys as $key ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					return $key;
				}
			}
		}

		$chain = array();

		for ( $level = $candidate, $depth = 0; null !== $level && $depth < 30; $level = $level->parent, ++$depth ) {
			array_unshift( $chain, Folding::key( $level->label ) );
		}

		return 'chain:' . implode( ' > ', $chain );
	}

	/**
	 * Keeps a key within its limit.
	 *
	 * @param string $key The key.
	 * @return string
	 */
	private static function bound( $key ) {
		return mb_strlen( $key ) <= self::MAX ? $key : mb_substr( $key, 0, self::MAX - 9 ) . '#' . substr( sha1( $key ), 0, 8 );
	}
}
