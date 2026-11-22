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
	 * @param array<string, mixed> $options  `include` and `exclude` (the tokens of `Selection`: `kind:place source:post/tag visibility:print`; a bare word is a kind), `context` (the broader topic in parentheses, true by default).
	 * @return IndexModel
	 */
	public function build( array $post_ids, array $options = array() ) {
		$selection = Selection::parse( $options['include'] ?? '', $options['exclude'] ?? '' );
		$context   = ! array_key_exists( 'context', $options ) || (bool) $options['context'];
		$lines     = array();

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
		$above = $context && $topic['parent'] > 0 ? $this->topics->get( (int) $topic['parent'] ) : null;

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
