<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The rename of the taxonomy of the topics.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Until 2026-11-22 the topics were called entries and were the terms of the taxonomy `index_entry`; they are the terms of `index_topic` now. The
 * terms, their meta and the statements of the mentions (which point at the term ids) do not change: **only the name of the taxonomy in the table
 * of the taxonomies does**. It runs once, on `init`, before the taxonomy is registered, and can be run again (it finds nothing to do).
 */
final class TopicsMigration {
	public const OPTION = 'index_topics_renamed';
	public const FROM   = 'index_entry';
	public const TO     = 'index_topic';

	/**
	 * Renames the taxonomy of the terms that have the old one. Action `init`.
	 *
	 * @param callable|null $rename Receives the old and the new name, returns the number of rows changed or false: default the table of the taxonomies of WordPress.
	 * @return int The number of terms moved.
	 */
	public static function run( $rename = null ) {
		if ( '1' === (string) get_option( self::OPTION, '' ) ) {
			return 0;
		}

		$moved = ( $rename ?? array( self::class, 'rename_in_wordpress' ) )( self::FROM, self::TO );

		// A failure (false) is not counted as done: it is tried again on the next request.
		if ( false === $moved ) {
			return 0;
		}

		update_option( self::OPTION, '1' );

		return (int) $moved;
	}

	/**
	 * The rename on WordPress: the table of the taxonomies, then the caches that hold the old name.
	 *
	 * @param string $from The old name.
	 * @param string $to   The new name.
	 * @return int|false
	 */
	public static function rename_in_wordpress( $from, $to ) {
		global $wpdb;

		$moved = $wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => $to ), array( 'taxonomy' => $from ) );

		if ( false === $moved ) {
			return false;
		}

		if ( $moved > 0 ) {
			// The terms are cached with their taxonomy, and a hierarchical taxonomy caches the list of its children in an option.
			delete_option( $from . '_children' );
			delete_option( $to . '_children' );
			wp_cache_flush();
		}

		return (int) $moved;
	}
}
