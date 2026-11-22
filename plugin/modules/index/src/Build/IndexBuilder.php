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

use Otherguise\Index\Entries;
use Otherguise\Index\Folding;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;

defined( 'ABSPATH' ) || exit;

/**
 * The builder (slice 610): the posts are given in the order of the book; the mentions of each give its entries; the lines are one per entry,
 * with the posts that mention it as locators, sorted according to the language and grouped by letter. A flat index: nothing is rolled up.
 */
final class IndexBuilder {
	/**
	 * The entries.
	 *
	 * @var Entries
	 */
	private $entries;

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
	 * @param Entries       $entries  The entries.
	 * @param Mentions      $mentions The mentions.
	 * @param callable|null $compare  The comparison of two labels; default `Collator` with the locale of the site, else the folded labels.
	 */
	public function __construct( Entries $entries, Mentions $mentions, $compare = null ) {
		$this->entries  = $entries;
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
	 * @param array<string, mixed> $options  `include` and `exclude` (the tokens of `Selection`: `kind:place source:post/tag visibility:print`; a bare word is a kind), `context` (the broader entry in parentheses, true by default).
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

				foreach ( $mention['details'] as $source => $detail ) {
					if ( $selection->passes( $lines[ $id ]['kind'], (string) $source, $detail['visible'] ) ) {
						$passing[] = (string) $source;
					}
				}

				if ( array() !== $passing ) {
					$lines[ $id ]['locators'][] = array(
						'post'     => $post,
						'strength' => $mention['strength'],
						'sources'  => $passing,
					);
				}
			}
		}

		$lines = array_values( array_filter( $lines, static fn( $line ) => false !== $line && array() !== $line['locators'] ) );

		usort( $lines, fn( $a, $b ) => $this->order( $a['sort'], $b['sort'] ) );

		return new IndexModel(
			array_map(
				static function ( $line ) {
					unset( $line['sort'] );

					return $line;
				},
				$lines
			)
		);
	}

	/**
	 * The line of an entry, or null when it is not in the index (not approved).
	 *
	 * @param int  $id      Entry.
	 * @param bool $context Whether the broader entry is shown.
	 * @return array<string, mixed>|null
	 */
	private function line( $id, $context ) {
		$entry = $this->entries->get( $id );

		if ( null === $entry || Entries::APPROVED !== $entry['status'] ) {
			return null;
		}

		$kind  = (string) $entry['kind'];
		$label = (string) $entry['label'];
		$above = $context && $entry['parent'] > 0 ? $this->entries->get( (int) $entry['parent'] ) : null;

		return array(
			'entry'    => $id,
			'label'    => null === $above ? $label : $label . ' (' . $above['label'] . ')',
			'kind'     => $kind,
			'classes'  => Kinds::classes( $kind ),
			'letter'   => $this->letter( $label ),
			'locators' => array(),
			'sort'     => Folding::key( $label ),
		);
	}

	/**
	 * The letter an entry is filed under: the first letter of its folded label, in capitals; digits and signs go under `#`.
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
