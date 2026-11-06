<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The rules that make a title a name.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki;

defined( 'ABSPATH' ) || exit;

/**
 * The rules of the names (slice 501), with no database and nothing of WordPress but the translation of the messages. The starting point is MediaWiki's, not its code: the name of a page is its title
 * after these steps, and two titles are the same name when they give the same result.
 *
 * 1. Unicode normal form C (needs the PHP extension `intl`; without it the step is skipped).
 * 2. A space and an underscore are the same: a run of spaces and underscores is one space, and the ends are trimmed.
 * 3. The first letter is a capital; nothing else is changed (the case and the accents tell two titles apart).
 * 4. Refused: an empty title, the control characters, the characters `# ? % < > [ ] { }` and `|`, a title that starts or ends with a slash,
 *    holds two in a row or has a part that is `.` or `..` (an address would be rewritten by the browser), and a qualified name of more than 191 bytes (the limit of a string of Triples).
 *
 * The colon is allowed in a title: only the first one of an address separates the space. A slash is a subpage **by convention** and nothing
 * more: there is no hierarchy in the data.
 */
final class Name {
	/**
	 * Longest qualified name, in bytes: the size of a literal of Triples.
	 */
	public const MAX_BYTES = 191;

	/**
	 * Tells whether the extension `intl` is there: without it a title is not normalized and the folded name keeps the accents.
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

		if ( '/' === $title[0] || '/' === substr( $title, -1 ) || false !== strpos( $title, '//' ) || array_intersect( array( '.', '..' ), explode( '/', $title ) ) ) {
			NameException::refuse( NameException::BAD_SLASH );
		}

		return mb_strtoupper( mb_substr( $title, 0, 1 ) ) . mb_substr( $title, 1 );
	}

	/**
	 * Builds the qualified name `Space:Title`.
	 *
	 * @param string $space The space, as registered (slice 500).
	 * @param mixed  $title The title as typed.
	 * @return string
	 * @throws NameException When the title is refused or the name is longer than 191 bytes.
	 */
	public static function qualified( $space, $title ) {
		$qualified = $space . ':' . self::title( $title );

		if ( strlen( $qualified ) > self::MAX_BYTES ) {
			NameException::refuse( NameException::TOO_LONG );
		}

		return $qualified;
	}

	/**
	 * Cuts a qualified name at its first colon.
	 *
	 * @param string $qualified A qualified name.
	 * @return array{space: string, title: string, qualified: string}
	 */
	public static function split( $qualified ) {
		$position = strpos( $qualified, ':' );

		return array(
			'space'     => false === $position ? '' : substr( $qualified, 0, $position ),
			'title'     => false === $position ? $qualified : substr( $qualified, $position + 1 ),
			'qualified' => $qualified,
		);
	}

	/**
	 * The folded name: lower case and no accents, in both parts. It serves the suggestions only, never an identity. Without `intl` the
	 * accents stay.
	 *
	 * @param string $qualified A qualified name.
	 * @return string
	 */
	public static function folded( $qualified ) {
		if ( self::has_intl() ) {
			$decomposed = \Normalizer::normalize( $qualified, \Normalizer::FORM_D );

			if ( is_string( $decomposed ) ) {
				$qualified = (string) preg_replace( '/\p{Mn}/u', '', $decomposed );
			}
		}

		return mb_strtolower( $qualified );
	}
}
