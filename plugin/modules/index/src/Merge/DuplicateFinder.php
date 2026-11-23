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
	 * Tells whether a pair was said not to be a double: `fn( int $a, int $b ): bool`.
	 *
	 * @var callable
	 */
	private $ignored;

	/**
	 * Builds the finder.
	 *
	 * @param Topics        $topics  The topics.
	 * @param callable|null $ignored Whether a pair was said not to be a double; default: none.
	 */
	public function __construct( Topics $topics, $ignored = null ) {
		$this->topics  = $topics;
		$this->ignored = $ignored ?? static fn() => false;
	}

	/**
	 * The groups of topics that may be one: the topics that are linked by a pair that can be a double, together (a topic and its two namesakes are one group of
	 * three, whose keeper a person chooses). A group that is **safe** is one where a single topic has a place in the hierarchy and a kind, and all the others
	 * are loose topics with no parent, no qualifier and the same or a more general kind (a link or a tag that names the same thing): they can go into it with no doubt.
	 *
	 * @return array<int, array{key:string,members:array<int,array>,safe:?array{keep:int,go:int[]}}> Sorted by the label of the first topic.
	 */
	public function groups() {
		$pairs  = $this->collect( true );
		$parent = array();
		$topics = array();
		$real   = array();

		foreach ( $pairs as $pair ) {
			foreach ( array( $pair['a'], $pair['b'] ) as $topic ) {
				$topics[ (int) $topic['id'] ]  = $topic;
				$parent[ (int) $topic['id'] ] = $parent[ (int) $topic['id'] ] ?? (int) $topic['id'];
			}

			$x = $this->root( $parent, (int) $pair['a']['id'] );
			$y = $this->root( $parent, (int) $pair['b']['id'] );

			if ( $x !== $y ) {
				$parent[ max( $x, $y ) ] = min( $x, $y );
			}

			if ( empty( $pair['related'] ) ) {
				$real[ (int) $pair['a']['id'] ] = true;
				$real[ (int) $pair['b']['id'] ] = true;
			}
		}

		$by_root = array();
		$offered = array();

		foreach ( $topics as $id => $topic ) {
			$root                = $this->root( $parent, $id );
			$by_root[ $root ][]  = $topic;
			$offered[ $root ]    = ( $offered[ $root ] ?? false ) || isset( $real[ $id ] );
		}

		$groups = array();

		foreach ( $by_root as $root => $members ) {
			// A parent and its child of the same name (a Third-level division and the commune of the same name) are shown with the others, to tell them apart, but
			// they are not a double: a group needs a pair that is.
			if ( ! $offered[ $root ] ) {
				continue;
			}

			usort( $members, static fn( $p, $q ) => (int) $p['id'] <=> (int) $q['id'] );

			$groups[] = array(
				'key'     => (string) $root,
				'members' => $members,
				'safe'    => self::safe( $members ),
			);
		}

		usort( $groups, static fn( $p, $q ) => strcmp( Folding::key( (string) $p['members'][0]['label'] ), Folding::key( (string) $q['members'][0]['label'] ) ) );

		return $groups;
	}

	/**
	 * The root of a topic in the groups being made.
	 *
	 * @param array<int, int> $parent The links.
	 * @param int             $id     A topic.
	 * @return int
	 */
	private function root( array &$parent, $id ) {
		while ( $parent[ $id ] !== $id ) {
			$parent[ $id ] = $parent[ $parent[ $id ] ];
			$id            = $parent[ $id ];
		}

		return $id;
	}

	/**
	 * What can be merged with no doubt in a group, or null.
	 *
	 * @param array<int, array> $members The topics of the group.
	 * @return array{keep:int,go:int[]}|null
	 */
	private static function safe( array $members ) {
		$placed = array_values( array_filter( $members, static fn( $topic ) => Kinds::ANY !== (string) $topic['kind'] && (int) $topic['parent'] > 0 ) );

		if ( 1 !== count( $placed ) ) {
			return null;
		}

		// A loose topic (a link, a tag) has no parent, no qualifier in parentheses (it names one of several namesakes), and a kind that is the one of the placed
		// topic or a more general one (`any`, `place`).
		$loose = array_values(
			array_filter(
				$members,
				static fn( $topic ) => (int) $placed[0]['id'] !== (int) $topic['id']
					&& 0 === (int) $topic['parent']
					&& 1 !== preg_match( '/\([^)]+\)\s*$/u', (string) $topic['label'] )
					&& ( Kinds::ANY === (string) $topic['kind'] || Kinds::is_a( (string) $placed[0]['kind'], (string) $topic['kind'] ) )
			)
		);

		if ( count( $loose ) !== count( $members ) - 1 ) {
			return null;
		}

		return array(
			'keep' => (int) $placed[0]['id'],
			'go'   => array_map( static fn( $topic ) => (int) $topic['id'], $loose ),
		);
	}

	/**
	 * The pairs.
	 *
	 * @return array<int, array{a:array,b:array,why:string}> The topic with the lower id first.
	 */
	public function pairs() {
		return $this->collect( false );
	}

	/**
	 * Collects the pairs; with `$with_related` also the parent and its child of the same name (not offered, but they tell a topic from another in a group).
	 *
	 * @param bool $with_related Include the pairs of a parent and its child.
	 * @return array<int, array{a:array,b:array,why:string,related?:bool}>
	 */
	private function collect( $with_related ) {
		$topics = array_values( $this->topics->all( array( 'status' => Topics::APPROVED ) ) );
		$found  = array();
		$plain  = array();
		$near   = array();

		foreach ( $topics as $topic ) {
			$label = (string) $topic['label'];
			$base  = Folding::key( trim( (string) preg_replace( '/\s*\([^)]*\)\s*/u', ' ', $label ) ) );

			if ( '' !== $base ) {
				$plain[ $base ][] = $topic;
				$near[ (int) $topic['parent'] ][] = array(
					'topic' => $topic,
					'key'   => $base,
					'len'   => strlen( $base ),
				);
			}
		}

		foreach ( $plain as $group ) {
			$this->pairs_of( $group, 'same label without the parentheses', $found, $with_related );
		}

		foreach ( $near as $group ) {
			usort( $group, static fn( $p, $q ) => $p['len'] <=> $q['len'] );

			$count = count( $group );

			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count && $group[ $j ]['len'] - $group[ $i ]['len'] <= 2; $j++ ) {
					$x = $group[ $i ]['key'];
					$y = $group[ $j ]['key'];

					// Names that differ only by their numbers are different things (EuroVelo 5 and EuroVelo 8, Route nationale 1 and 106).
					if ( $x !== $y && $group[ $i ]['len'] >= 7 && levenshtein( $x, $y ) <= 2 && preg_replace( '/\d+/', '', $x ) !== preg_replace( '/\d+/', '', $y ) ) {
						$this->pairs_of( array( $group[ $i ]['topic'], $group[ $j ]['topic'] ), 'labels that differ by a letter or two', $found, $with_related );
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
	 * @param bool                 $with_related Also keep the pairs of a parent and its child.
	 * @return void
	 */
	private function pairs_of( array $group, $why, array &$found, $with_related = false ) {
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

				$related = $this->related( $a, $b );

				if ( ( $related && ! $with_related ) || ( ! $related && ( $homonyms || ! Kinds::compatible( (string) $a['kind'], (string) $b['kind'] ) ) ) || self::other_qualifiers( $a, $b ) || ( $this->ignored )( (int) $a['id'], (int) $b['id'] ) ) {
					continue;
				}

				$found[ $a['id'] . '-' . $b['id'] ] = $found[ $a['id'] . '-' . $b['id'] ] ?? array(
					'a'       => $a,
					'b'       => $b,
					'why'     => $why,
					'related' => $related,
				);
			}
		}
	}

	/**
	 * Whether two labels have each a qualifier in parentheses and the qualifiers differ (*Route nationale 1 (Italie)* and *(Albanie)*): two things.
	 *
	 * @param array $a A topic.
	 * @param array $b Another.
	 * @return bool
	 */
	private static function other_qualifiers( array $a, array $b ) {
		$qualifier = static function ( $label ) {
			return 1 === preg_match( '/\(([^)]*)\)\s*$/u', (string) $label, $found ) ? Folding::key( $found[1] ) : '';
		};
		$x         = $qualifier( $a['label'] );
		$y         = $qualifier( $b['label'] );

		return '' !== $x && '' !== $y && $x !== $y;
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
