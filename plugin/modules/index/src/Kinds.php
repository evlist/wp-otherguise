<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The kinds of entries.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * A short, editable list of kinds (slice 600). The filter `index_kinds` changes it; a kind is a slug with a label.
 */
final class Kinds {
	public const OTHER = 'other';

	/**
	 * The kinds: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function all() {
		$kinds = array(
			'place'   => __( 'Place', 'otherguise' ),
			'species' => __( 'Species', 'otherguise' ),
			'route'   => __( 'Route', 'otherguise' ),
			'person'  => __( 'Person', 'otherguise' ),
			'work'    => __( 'Work', 'otherguise' ),
			'product' => __( 'Product', 'otherguise' ),
			'event'   => __( 'Event', 'otherguise' ),
			'other'   => __( 'Other', 'otherguise' ),
		);

		$filtered = apply_filters( 'index_kinds', $kinds );

		if ( ! is_array( $filtered ) || array() === $filtered ) {
			return $kinds;
		}

		$clean = array();

		foreach ( $filtered as $slug => $label ) {
			if ( is_string( $slug ) && 1 === preg_match( '/^[a-z][a-z0-9-]{0,30}$/', $slug ) ) {
				$clean[ $slug ] = (string) $label;
			}
		}

		return array() === $clean ? $kinds : $clean;
	}

	/**
	 * Tells whether a slug is a kind.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function is_kind( $slug ) {
		return array_key_exists( (string) $slug, self::all() );
	}
}
