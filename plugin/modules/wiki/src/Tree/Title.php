<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The rules of the titles of the tree.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

use Otherguise\Wiki\Name;
use Otherguise\Wiki\NameException;

defined( 'ABSPATH' ) || exit;

/**
 * The rules that make a title a name in the hierarchical model (slice 501), with no database and nothing of WordPress but the translation
 * of the messages.
 *
 * 1. Unicode normal form C (needs the PHP extension `intl`; without it the step is skipped).
 * 2. A space and an underscore are the same: a run of spaces and underscores is one space, and the ends are trimmed.
 * 3. Nothing else is changed: **the first letter is not made a capital**, the titles are case-sensitive and an accent tells two titles apart.
 * 4. Refused: an empty title, the control characters, the characters `# ? % < > [ ] { }` and `|`, **any slash** (it separates the levels),
 *    a title that is `.` or `..`.
 * 5. At the first level only, a title made only of digits is refused (`/2026` is the archive by year).
 *
 * The identity of a page is the **key**: `<id of the parent>/<title>` (`0/Biography` at the first level), at most 191 bytes (the size of a
 * literal of Triples): only one title counts, so the depth of the tree is not limited.
 */
final class Title {
	/**
	 * Longest key, in bytes.
	 */
	public const MAX_BYTES = 191;

	/**
	 * Tells whether the extension `intl` is there: without it a title is not normalized and the folded key keeps the accents.
	 *
	 * @return bool
	 */
	public static function has_intl() {
		return class_exists( 'Normalizer' );
	}

	/**
	 * Gives a title its form of a name.
	 *
	 * @param mixed $raw The title as typed or imported.
	 * @return string
	 * @throws NameException When the title is refused.
	 */
	public static function title( $raw ) {
		if ( ! is_string( $raw ) || ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			NameException::refuse( NameException::BAD_ENCODING );
		}

		if ( 1 === preg_match( '/\p{Cc}/u', $raw ) ) {
			NameException::refuse( NameException::CONTROL_CHARACTER );
		}

		if ( self::has_intl() ) {
			$normalized = \Normalizer::normalize( $raw, \Normalizer::FORM_C );
			$raw        = is_string( $normalized ) ? $normalized : $raw;
		}

		$title = trim( (string) preg_replace( '/[\p{Z}\s_]+/u', ' ', $raw ) );

		if ( '' === $title ) {
			NameException::refuse( NameException::EMPTY_TITLE );
		}

		if ( 1 === preg_match( '/[#?%<>\[\]{}|]/', $title ) ) {
			NameException::refuse( NameException::FORBIDDEN );
		}

		if ( false !== strpos( $title, '/' ) ) {
			NameException::refuse( NameException::SLASH );
		}

		if ( '.' === $title || '..' === $title ) {
			NameException::refuse( NameException::DOTS );
		}

		return $title;
	}

	/**
	 * Builds the key of a page: the id of its parent, a slash, its title.
	 *
	 * @param int   $parent_id The parent (0 for the first level).
	 * @param mixed $title     The title as typed.
	 * @return string
	 * @throws NameException When the title is refused, is made only of digits at the first level, or the key is longer than 191 bytes.
	 */
	public static function key( $parent_id, $title ) {
		$parent_id = max( 0, (int) $parent_id );
		$title     = self::title( $title );

		if ( 0 === $parent_id && 1 === preg_match( '/^[0-9]+$/', $title ) ) {
			NameException::refuse( NameException::DIGITS );
		}

		return self::join( $parent_id, $title );
	}

	/**
	 * Writes a parent and a title as a key, without the rules (the title is already a name).
	 *
	 * @param int    $parent_id The parent.
	 * @param string $title     The title, put through the rules.
	 * @return string
	 * @throws NameException When the key is longer than 191 bytes.
	 */
	public static function join( $parent_id, $title ) {
		$key = (int) $parent_id . '/' . $title;

		if ( strlen( $key ) > self::MAX_BYTES ) {
			NameException::refuse( NameException::TOO_LONG );
		}

		return $key;
	}

	/**
	 * Cuts a key at its first slash.
	 *
	 * @param string $key A key.
	 * @return array{parent: int, title: string, key: string}
	 */
	public static function split( $key ) {
		$position = strpos( $key, '/' );

		return array(
			'parent' => false === $position ? 0 : (int) substr( $key, 0, $position ),
			'title'  => false === $position ? $key : substr( $key, $position + 1 ),
			'key'    => $key,
		);
	}

	/**
	 * The folded form of a title: lower case and no accents, for the suggestions only, never an identity. Without `intl` the accents stay.
	 *
	 * @param string $title A title, put through the rules.
	 * @return string
	 */
	public static function folded( $title ) {
		if ( self::has_intl() ) {
			$decomposed = \Normalizer::normalize( $title, \Normalizer::FORM_D );

			if ( is_string( $decomposed ) ) {
				$title = (string) preg_replace( '/\p{Mn}/u', '', $decomposed );
			}
		}

		return mb_strtolower( $title );
	}
}
