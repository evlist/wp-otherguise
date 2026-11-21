<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Folding of labels into keys.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The key of a label: lower case, accents removed, spaces collapsed. `Normalizer` (intl) is used when it is there, `remove_accents()`
 * otherwise.
 */
final class Folding {
	/**
	 * Folds a label.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	public static function key( $label ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $label ) );

		if ( class_exists( '\Normalizer' ) ) {
			$decomposed = \Normalizer::normalize( $text, \Normalizer::FORM_D );

			if ( is_string( $decomposed ) ) {
				$text = (string) preg_replace( '/\p{Mn}+/u', '', $decomposed );
			}
		} elseif ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}
}
