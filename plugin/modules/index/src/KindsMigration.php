<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The topics that were of the kind `other` for want of a kind become `any`.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Until slice 615 `other` was the kind of every topic that nobody had classified. The root kind `any` says that, and `other` is now a kind of its own:
 * **once**, the topics of kind `other` whose kind **no person chose** take the kind `any`, so that what a source learns (a place, a species) can refine it.
 * The kind that a person set stays. The kind of a category or of a tag (which uses the same meta key) is **not** touched: only the terms of the topics.
 */
final class KindsMigration {
	public const OPTION = 'index_kinds_any';

	/**
	 * Does the change once. Action `init`.
	 *
	 * @param callable|null $update Receives nothing, returns the number of topics changed or false: default the term meta of WordPress.
	 * @return int The number of topics changed.
	 */
	public static function run( $update = null ) {
		if ( '1' === (string) get_option( self::OPTION, '' ) ) {
			return 0;
		}

		$changed = ( $update ?? array( self::class, 'update_in_wordpress' ) )();

		// A failure (false) is not counted as done: it is tried again on the next request.
		if ( false === $changed ) {
			return 0;
		}

		update_option( self::OPTION, '1' );

		return (int) $changed;
	}

	/**
	 * The change on WordPress: one query on the term meta of the terms of the topics, whose kind was not set by hand.
	 *
	 * @return int|false
	 */
	public static function update_in_wordpress() {
		global $wpdb;

		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->termmeta} AS kind INNER JOIN {$wpdb->term_taxonomy} AS tax ON tax.term_id = kind.term_id AND tax.taxonomy = %s SET kind.meta_value = %s WHERE kind.meta_key = %s AND kind.meta_value = %s AND NOT EXISTS ( SELECT 1 FROM {$wpdb->termmeta} AS hand WHERE hand.term_id = kind.term_id AND hand.meta_key = %s AND hand.meta_value = '1' )",
				TermTopics::TAXONOMY,
				Kinds::ANY,
				TermTopics::KIND,
				Kinds::OTHER,
				TermTopics::HAND
			)
		);

		if ( false === $changed ) {
			return false;
		}

		if ( $changed > 0 ) {
			wp_cache_flush();
		}

		return (int) $changed;
	}
}
