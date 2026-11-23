<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What an index lists: `include` and `exclude` on the kind, the source and the visibility.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Build;

use Otherguise\Index\Kinds;
use Otherguise\Index\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * The selection of an index (slice 605): lists of tokens `dimension:value` (`kind:place`, `source:image`, `visibility:print`), separated by spaces
 * or commas; a token with **no dimension is a kind**. A value **stands for its descendants** (the sub-kinds, the sub-sources).
 *
 * The unit that is tested is **a topic, a post and one source of the mention** (a mention made by a tag and by a link is two such units): the
 * kind is the topic's, the source and the visibility those of that source on that mention. A unit passes when
 *
 * 1. **for each dimension that `include` names**, it matches one of the tokens of that dimension (a dimension that `include` does not name is
 *    not tested: the default is everything), **and**
 * 2. it matches **no** token of `exclude`.
 *
 * A mention is kept with the sources that pass, and **dropped when none does**. A mention that has no visibility (`null`) is shown in every
 * mode: it matches every `visibility:` token.
 */
final class Selection {
	public const DIMENSIONS = array( 'kind', 'source', 'visibility' );

	/**
	 * The tokens that must match, by dimension.
	 *
	 * @var array<string, string[]>
	 */
	private $include;

	/**
	 * The tokens that remove, by dimension.
	 *
	 * @var array<string, string[]>
	 */
	private $exclude;

	/**
	 * What is wrong in what was given (an unknown dimension, an unknown kind or source).
	 *
	 * @var string[]
	 */
	private $errors;

	/**
	 * Builds a selection.
	 *
	 * @param array<string, string[]> $include Tokens by dimension.
	 * @param array<string, string[]> $exclude Tokens by dimension.
	 * @param string[]                $errors  What is wrong.
	 */
	private function __construct( array $include, array $exclude, array $errors ) {
		$this->include = $include;
		$this->exclude = $exclude;
		$this->errors  = $errors;
	}

	/**
	 * Reads the two lists.
	 *
	 * @param string|string[] $include The tokens to include (a string, or a list).
	 * @param string|string[] $exclude The tokens to exclude.
	 * @return Selection
	 */
	public static function parse( $include = '', $exclude = '' ) {
		$errors = array();

		return new self( self::tokens( $include, $errors ), self::tokens( $exclude, $errors ), $errors );
	}

	/**
	 * What is wrong in the lists: an unknown dimension, an unknown kind or source. Empty when all is well.
	 *
	 * @return string[]
	 */
	public function errors() {
		return $this->errors;
	}

	/**
	 * Whether nothing is selected or excluded.
	 *
	 * @return bool
	 */
	public function is_everything() {
		return array() === $this->include && array() === $this->exclude;
	}

	/**
	 * Tests one unit: a topic of a kind, and one source of a mention with its visibility.
	 *
	 * @param string        $kind    The kind of the topic.
	 * @param string        $source  The source of the mention (a path).
	 * @param string[]|null $visible The modes in which that source shows it, null for every mode.
	 * @return bool
	 */
	public function passes( $kind, $source, $visible ) {
		foreach ( $this->include as $dimension => $tokens ) {
			if ( ! $this->matches( $dimension, $tokens, $kind, $source, $visible ) ) {
				return false;
			}
		}

		foreach ( $this->exclude as $dimension => $tokens ) {
			if ( $this->matches( $dimension, $tokens, $kind, $source, $visible ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a unit matches one of the tokens of a dimension.
	 *
	 * @param string        $dimension Dimension.
	 * @param string[]      $tokens    The tokens.
	 * @param string        $kind      Kind.
	 * @param string        $source    Source.
	 * @param string[]|null $visible   Modes, null for every mode.
	 * @return bool
	 */
	private function matches( $dimension, array $tokens, $kind, $source, $visible ) {
		foreach ( $tokens as $token ) {
			if ( 'kind' === $dimension && Kinds::is_a( $kind, $token ) ) {
				return true;
			}

			if ( 'source' === $dimension && Sources::is_a( $source, $token ) ) {
				return true;
			}

			if ( 'visibility' === $dimension && ( null === $visible || in_array( $token, $visible, true ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads a list of tokens into tokens by dimension.
	 *
	 * @param string|string[] $list   The list.
	 * @param string[]        $errors Receives what is wrong.
	 * @return array<string, string[]>
	 */
	private static function tokens( $list, array &$errors ) {
		$list   = is_array( $list ) ? implode( ' ', array_map( 'strval', $list ) ) : (string) $list;
		$by_dim = array();

		$parts = preg_split( '/[\s,]+/', trim( $list ) );

		foreach ( false === $parts ? array() : $parts as $token ) {
			if ( '' === $token ) {
				continue;
			}

			$position  = strpos( $token, ':' );
			$dimension = false === $position ? 'kind' : substr( $token, 0, $position );
			$value     = false === $position ? $token : substr( $token, $position + 1 );

			if ( ! in_array( $dimension, self::DIMENSIONS, true ) || '' === $value ) {
				$errors[] = 'Unknown selection "' . $token . '": use kind:…, source:… or visibility:….';

				continue;
			}

			if ( 'kind' === $dimension && ! Kinds::is_kind( $value ) ) {
				$errors[] = 'Unknown kind "' . $value . '". The kinds: ' . implode( ', ', array_keys( Kinds::all() ) ) . '.';
			} elseif ( 'source' === $dimension && ! Sources::is_source( $value ) ) {
				$errors[] = 'Unknown source "' . $value . '". The sources: ' . implode( ', ', array_keys( Sources::registry() ) ) . '.';
			}

			$by_dim[ $dimension ][] = $value;
		}

		return $by_dim;
	}
}
