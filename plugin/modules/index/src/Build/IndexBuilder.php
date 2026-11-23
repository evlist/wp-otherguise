<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Builds the lines of an index from the mentions of some posts.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Build;

use Otherguise\Index\Topics;
use Otherguise\Index\Folding;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * The builder (slice 610): the posts are given in the order of the book; the mentions of each give its topics; the lines are one per topic,
 * with the posts that mention it as locators, sorted according to the language and grouped by letter. A flat index: nothing is rolled up.
 */
final class IndexBuilder {
	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * Compares two sort keys: `fn( string $a, string $b ): int`; null for the one of the language when it is available.
	 *
	 * @var callable|null
	 */
	private $compare;

	/**
	 * Builds the builder.
	 *
	 * @param Topics        $topics  The topics.
	 * @param Mentions      $mentions The mentions.
	 * @param callable|null $compare  The comparison of two labels; default `Collator` with the locale of the site, else the folded labels.
	 */
	public function __construct( Topics $topics, Mentions $mentions, $compare = null ) {
		$this->topics  = $topics;
		$this->mentions = $mentions;
		$this->compare  = $compare ?? self::language_comparison();
	}

	/**
	 * The comparison of the language of the site: `Collator` (intl) when it is there, otherwise null (the folded labels are compared).
	 *
	 * @return callable|null
	 */
	private static function language_comparison() {
		if ( ! class_exists( '\Collator' ) || ! function_exists( 'get_locale' ) ) {
			return null;
		}

		$collator = \Collator::create( str_replace( '_', '-', (string) get_locale() ) );

		return false === $collator || null === $collator ? null : static fn( $a, $b ) => (int) $collator->compare( $a, $b );
	}

	/**
	 * Builds the index of some posts.
	 *
	 * @param int[]                $post_ids The posts, in the order of the book.
	 * @param array<string, mixed> $options  `include` and `exclude` (the tokens of `Selection`: `kind:place source:post/tag visibility:print`; a bare word is a kind), `context` (the broader topic in parentheses, true by default), `rollup` (slice 613: the kinds of the broader topics that get a line of their own with the pages of what is below them: `kind:category`, or `all`).
	 * @return IndexModel
	 */
	public function build( array $post_ids, array $options = array() ) {
		$selection = Selection::parse( $options['include'] ?? '', $options['exclude'] ?? '' );
		$context   = ! array_key_exists( 'context', $options ) || (bool) $options['context'];
		$lines     = array();
		$order     = array_flip( array_values( array_unique( array_map( 'intval', $post_ids ) ) ) );

		foreach ( array_values( array_unique( array_map( 'intval', $post_ids ) ) ) as $post ) {
			foreach ( $this->mentions->of_post( $post ) as $id => $mention ) {
				if ( ! isset( $lines[ $id ] ) ) {
					$lines[ $id ] = $this->line( (int) $id, $context ) ?? false;
				}

				if ( false === $lines[ $id ] ) {
					continue;
				}

				// The selection is tested on each source of the mention: it keeps the sources that pass, and drops the mention when none does.
				$passing = array();
				$via     = array();

				foreach ( $mention['details'] as $source => $detail ) {
					if ( $selection->passes( $lines[ $id ]['kind'], (string) $source, $detail['visible'] ) ) {
						$passing[] = (string) $source;
						$via       = array_merge( $via, $detail['via'] );
					}
				}

				if ( array() !== $passing ) {
					$lines[ $id ]['locators'][] = array(
						'post'     => $post,
						'strength' => $mention['strength'],
						'sources'  => $passing,
						'via'      => array_values( array_unique( $via ) ),
					);
				}
			}
		}

		$lines = $this->rolled_up( $lines, (string) ( $options['rollup'] ?? '' ), $context, $order );
		$lines = array_values( array_filter( $lines, static fn( $line ) => false !== $line && array() !== $line['locators'] ) );

		usort( $lines, fn( $a, $b ) => $this->order( $a['sort'], $b['sort'] ) );

		$attribution = array(
			'osm'       => false,
			'wikimedia' => false,
		);

		foreach ( $lines as $line ) {
			$attribution['wikimedia'] = $attribution['wikimedia'] || $line['wiki'];

			foreach ( $line['locators'] as $locator ) {
				foreach ( $locator['sources'] as $source ) {
					// A place read from coordinates (a photograph, a track) comes from OpenStreetMap.
					$attribution['osm'] = $attribution['osm'] || Sources::is_a( $source, 'image' ) || Sources::is_a( $source, 'trace' );
				}
			}
		}

		return new IndexModel(
			array_map(
				static function ( $line ) {
					unset( $line['sort'], $line['wiki'] );

					return $line;
				},
				$lines
			),
			$attribution
		);
	}

	/**
	 * What is wrong with the value of the option `rollup` (kind tokens or `all`): nothing for an empty value.
	 *
	 * @param string $rollup The value.
	 * @return string[]
	 */
	public static function rollup_errors( $rollup ) {
		$rollup = trim( (string) $rollup );

		if ( '' === $rollup || 'all' === $rollup ) {
			return array();
		}

		if ( 1 === preg_match( '/(^|[\s,])(source|visibility):/', $rollup ) ) {
			return array( 'rollup: only kinds (kind:place) or all; source and visibility are chosen with include and exclude.' );
		}

		return Selection::parse( $rollup, '' )->errors();
	}

	/**
	 * Gives the broader topics that the option asks for a line of their own (slice 613): each carries the posts of what is below it, **as the selection
	 * already filtered them**, once per post (the strongest strength, the sources and the `via` of every descendant). A broader topic that was mentioned
	 * itself keeps its own mentions too. The broader topics are taken from the lines that were made from mentions, not from the lines made here.
	 *
	 * @param array<int, array|false> $lines   The lines by topic.
	 * @param string                  $rollup  The option.
	 * @param bool                    $context Whether the broader topic is shown in parentheses.
	 * @param array<int, int>         $order   The position of each post in the book.
	 * @return array<int, array|false>
	 */
	private function rolled_up( array $lines, $rollup, $context, array $order ) {
		$rollup = trim( $rollup );

		if ( '' === $rollup || array() !== self::rollup_errors( $rollup ) ) {
			return $lines;
		}

		$wanted = 'all' === $rollup ? null : Selection::parse( $rollup, '' );
		$direct = $lines;

		foreach ( $direct as $id => $line ) {
			if ( false === $line || array() === $line['locators'] ) {
				continue;
			}

			foreach ( $this->ancestors( (int) $id ) as $up ) {
				$topic = $this->topics->get( $up );

				if ( null === $topic || ( null !== $wanted && ! $wanted->passes( (string) $topic['kind'], '', null ) ) ) {
					continue;
				}

				if ( ! isset( $lines[ $up ] ) ) {
					$lines[ $up ] = $this->line( $up, $context ) ?? false;
				}

				if ( false !== $lines[ $up ] ) {
					$lines[ $up ]['locators'] = self::merged( $lines[ $up ]['locators'], $line['locators'], $order );
				}
			}
		}

		return $lines;
	}

	/**
	 * The broader topics of a topic, the nearest first.
	 *
	 * @param int $id Topic.
	 * @return int[]
	 */
	private function ancestors( $id ) {
		$found = array();
		$topic = $this->topics->get( $id );

		// At most 20 levels, and a cycle stops the walk.
		for ( $level = 0; $level < 20 && null !== $topic && (int) $topic['parent'] > 0 && ! in_array( (int) $topic['parent'], $found, true ); $level++ ) {
			$found[] = (int) $topic['parent'];
			$topic   = $this->topics->get( (int) $topic['parent'] );
		}

		return $found;
	}

	/**
	 * Merges two lists of locators: one per post, the strongest strength, the sources and the `via` of both, in the order of the book.
	 *
	 * @param array<int, array> $a     Locators.
	 * @param array<int, array> $b     Locators.
	 * @param array<int, int>   $order The position of each post in the book.
	 * @return array<int, array>
	 */
	private static function merged( array $a, array $b, array $order ) {
		$by_post = array();

		foreach ( array_merge( $a, $b ) as $locator ) {
			$post = (int) $locator['post'];

			if ( ! isset( $by_post[ $post ] ) ) {
				$by_post[ $post ] = $locator;

				continue;
			}

			$by_post[ $post ]['strength'] = 'main' === $by_post[ $post ]['strength'] || 'main' === $locator['strength'] ? 'main' : 'passing';
			$by_post[ $post ]['sources']  = array_values( array_unique( array_merge( $by_post[ $post ]['sources'], $locator['sources'] ) ) );
			$by_post[ $post ]['via']      = array_values( array_unique( array_merge( $by_post[ $post ]['via'] ?? array(), $locator['via'] ?? array() ) ) );
		}

		uksort( $by_post, static fn( $x, $y ) => ( $order[ $x ] ?? 0 ) <=> ( $order[ $y ] ?? 0 ) );

		return array_values( $by_post );
	}

	/**
	 * The broader topic that a label shows in parentheses (slice 619): the nearest one **that is not of a skipped kind** (by default the third divisions, a
	 * comarca between a province and its communes: *A Guarda (Pontevedra)*, not *A Guarda (O Baixo Miño)*); when every broader topic is of a skipped kind, the
	 * direct one. The filter `index_context_skipped_kinds` changes the kinds.
	 *
	 * @param array $topic A topic.
	 * @return array|null
	 */
	private function context_of( array $topic ) {
		$skipped = apply_filters( 'index_context_skipped_kinds', array( 'admin3' ) );
		$skipped = is_array( $skipped ) ? array_map( 'strval', $skipped ) : array( 'admin3' );
		$direct  = $this->topics->get( (int) $topic['parent'] );
		$above   = $direct;

		for ( $level = 0; null !== $above && in_array( (string) $above['kind'], $skipped, true ) && $level < 10; $level++ ) {
			$above = (int) $above['parent'] > 0 ? $this->topics->get( (int) $above['parent'] ) : null;
		}

		return null === $above ? $direct : $above;
	}

	/**
	 * The line of a topic, or null when it is not in the index (not approved).
	 *
	 * @param int  $id      Topic.
	 * @param bool $context Whether the broader topic is shown.
	 * @return array<string, mixed>|null
	 */
	private function line( $id, $context ) {
		$topic = $this->topics->get( $id );

		if ( null === $topic || Topics::APPROVED !== $topic['status'] ) {
			return null;
		}

		$kind  = (string) $topic['kind'];
		$label = (string) $topic['label'];
		$above = $context && $topic['parent'] > 0 ? $this->context_of( $topic ) : null;

		return array(
			'topic'    => $id,
			'label'    => null === $above ? $label : $label . ' (' . $above['label'] . ')',
			'kind'     => $kind,
			'classes'  => Kinds::classes( $kind ),
			'letter'   => $this->letter( $label ),
			'locators' => array(),
			'sort'     => Folding::key( $label ),
			'wiki'     => $this->from_wikimedia( (array) $topic['keys'] ),
		);
	}

	/**
	 * Whether a topic has an identifier of Wikidata or a link to Wikipedia among its keys: it owes something to the Wikimedia projects.
	 *
	 * @param string[] $keys Keys.
	 * @return bool
	 */
	private function from_wikimedia( array $keys ) {
		foreach ( $keys as $key ) {
			if ( 0 === strpos( (string) $key, 'wikidata:' ) || 0 === strpos( (string) $key, 'wikipedia:' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The letter a topic is filed under: the first letter of its folded label, in capitals; digits and signs go under `#`.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	private function letter( $label ) {
		$first = function_exists( 'mb_substr' ) ? mb_substr( Folding::key( $label ), 0, 1, 'UTF-8' ) : substr( Folding::key( $label ), 0, 1 );

		return 1 === preg_match( '/^\p{L}$/u', $first ) ? ( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first, 'UTF-8' ) : strtoupper( $first ) ) : '#';
	}

	/**
	 * Compares two sort keys.
	 *
	 * @param string $a Key.
	 * @param string $b Key.
	 * @return int
	 */
	private function order( $a, $b ) {
		if ( null !== $this->compare ) {
			return (int) ( $this->compare )( $a, $b );
		}

		return strcmp( $a, $b );
	}
}
