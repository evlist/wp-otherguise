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
	 * @param array<string, mixed> $options  `include` and `exclude` (lists of kinds, with their sub-kinds), `context` (the broader entry in parentheses, true by default).
	 * @return IndexModel
	 */
	public function build( array $post_ids, array $options = array() ) {
		$include = $this->kinds( $options['include'] ?? array() );
		$exclude = $this->kinds( $options['exclude'] ?? array() );
		$context = ! array_key_exists( 'context', $options ) || (bool) $options['context'];
		$lines   = array();

		foreach ( array_values( array_unique( array_map( 'intval', $post_ids ) ) ) as $post ) {
			foreach ( $this->mentions->of_post( $post ) as $id => $mention ) {
				if ( ! isset( $lines[ $id ] ) ) {
					$line = $this->line( (int) $id, $include, $exclude, $context );

					if ( null === $line ) {
						$lines[ $id ] = false;

						continue;
					}

					$lines[ $id ] = $line;
				}

				if ( false !== $lines[ $id ] ) {
					$lines[ $id ]['locators'][] = array(
						'post'     => $post,
						'strength' => $mention['strength'],
					);
				}
			}
		}

		$lines = array_values( array_filter( $lines ) );

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
	 * The line of an entry, or null when it is not in the index.
	 *
	 * @param int      $id      Entry.
	 * @param string[] $include Kinds that are in (with their sub-kinds), empty for all.
	 * @param string[] $exclude Kinds that are out.
	 * @param bool     $context Whether the broader entry is shown.
	 * @return array<string, mixed>|null
	 */
	private function line( $id, array $include, array $exclude, $context ) {
		$entry = $this->entries->get( $id );

		if ( null === $entry || Entries::APPROVED !== $entry['status'] ) {
			return null;
		}

		$kind = (string) $entry['kind'];

		if ( ( array() !== $include && ! in_array( $kind, $include, true ) ) || in_array( $kind, $exclude, true ) ) {
			return null;
		}

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
	 * The kinds of a list, each with its sub-kinds.
	 *
	 * @param mixed $kinds A list of kinds, or a string of kinds separated by commas.
	 * @return string[]
	 */
	private function kinds( $kinds ) {
		$kinds = is_string( $kinds ) ? explode( ',', $kinds ) : (array) $kinds;
		$found = array();

		foreach ( $kinds as $kind ) {
			$kind = trim( (string) $kind );

			if ( '' !== $kind ) {
				$found = array_merge( $found, Kinds::descendants( $kind ) );
			}
		}

		return array_values( array_unique( $found ) );
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
