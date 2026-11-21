<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The entries as terms of a taxonomy.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Entries kept as terms of the hidden hierarchical taxonomy `index_entry`. The keys of an entry are rows `_index_key` of the term meta (one
 * row per key, so that a lookup is one query); the kind, the status and the "kind set by hand" flag are term meta too.
 */
final class TermEntries implements Entries {
	public const TAXONOMY = 'index_entry';
	public const KEY      = '_index_key';
	public const KIND     = '_index_kind';
	public const HAND     = '_index_kind_set_by_hand';
	public const STATUS   = '_index_status';

	/**
	 * Registers the taxonomy: hierarchical, not public, no rewrite, no screen of its own. Action `init`.
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			array(),
			array(
				'labels'            => array(
					'name'          => __( 'Index entries', 'otherguise' ),
					'singular_name' => __( 'Index entry', 'otherguise' ),
				),
				'public'            => false,
				'hierarchical'      => true,
				'show_ui'           => false,
				'show_in_rest'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'show_admin_column' => false,
			)
		);
	}

	/**
	 * The ids of the entries that hold a key.
	 *
	 * @param string $key Key.
	 * @return int[]
	 */
	public function with_key( $key ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.term_id FROM {$wpdb->termmeta} m INNER JOIN {$wpdb->term_taxonomy} t ON t.term_id = m.term_id WHERE m.meta_key = %s AND m.meta_value = %s AND t.taxonomy = %s ORDER BY m.term_id",
				self::KEY,
				(string) $key,
				self::TAXONOMY
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Creates an entry.
	 *
	 * @param string   $label  Label.
	 * @param string   $kind   Kind.
	 * @param int      $parent Parent entry, 0 for none.
	 * @param string   $status Status.
	 * @param string[] $keys   Keys.
	 * @return int The id, 0 when WordPress refuses.
	 */
	public function create( $label, $kind, $parent, $status, array $keys ) {
		$inserted = wp_insert_term(
			$label,
			self::TAXONOMY,
			array(
				'parent' => (int) $parent,
				// A distinct slug lets two entries of one name share a parent (two unlinked homonyms).
				'slug'   => sanitize_title( $label ) . '-' . substr( md5( $label . '|' . (int) $parent . '|' . implode( '|', $keys ) ), 0, 8 ),
			)
		);

		if ( is_wp_error( $inserted ) ) {
			return 0;
		}

		$id = (int) $inserted['term_id'];

		update_term_meta( $id, self::KIND, (string) $kind );
		update_term_meta( $id, self::STATUS, (string) $status );
		$this->add_keys( $id, $keys );

		return $id;
	}

	/**
	 * Adds keys to an entry.
	 *
	 * @param int      $id   Entry.
	 * @param string[] $keys Keys.
	 * @return void
	 */
	public function add_keys( $id, array $keys ) {
		$have = array_map( 'strval', (array) get_term_meta( (int) $id, self::KEY, false ) );

		foreach ( $keys as $key ) {
			if ( '' !== (string) $key && ! in_array( (string) $key, $have, true ) ) {
				add_term_meta( (int) $id, self::KEY, (string) $key );
				$have[] = (string) $key;
			}
		}
	}

	/**
	 * Sets the kind of an entry.
	 *
	 * @param int    $id      Entry.
	 * @param string $kind    Kind.
	 * @param bool   $by_hand Whether a person chose it.
	 * @return void
	 */
	public function set_kind( $id, $kind, $by_hand ) {
		update_term_meta( (int) $id, self::KIND, (string) $kind );

		if ( $by_hand ) {
			update_term_meta( (int) $id, self::HAND, '1' );
		}
	}

	/**
	 * Deletes an entry.
	 *
	 * @param int $id Entry.
	 * @return void
	 */
	public function delete( $id ) {
		wp_delete_term( (int) $id, self::TAXONOMY );
	}

	/**
	 * Reads an entry.
	 *
	 * @param int $id Entry.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		$term = get_term( (int) $id, self::TAXONOMY );

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return array(
			'id'           => (int) $term->term_id,
			'label'        => (string) $term->name,
			'kind'         => (string) get_term_meta( (int) $id, self::KIND, true ),
			'parent'       => (int) $term->parent,
			'status'       => (string) get_term_meta( (int) $id, self::STATUS, true ),
			'keys'         => array_map( 'strval', (array) get_term_meta( (int) $id, self::KEY, false ) ),
			'kind_by_hand' => '1' === (string) get_term_meta( (int) $id, self::HAND, true ),
		);
	}

	/**
	 * The entries, filtered.
	 *
	 * @param array<string, mixed> $filters `kind`, `status`, `parent`.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( array $filters = array() ) {
		$args = array(
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'name',
			'fields'     => 'ids',
		);

		if ( isset( $filters['parent'] ) ) {
			$args['parent'] = (int) $filters['parent'];
		}

		$meta = array();

		foreach ( array(
			'kind'   => self::KIND,
			'status' => self::STATUS,
		) as $filter => $meta_key ) {
			if ( isset( $filters[ $filter ] ) ) {
				$meta[] = array(
					'key'   => $meta_key,
					'value' => (string) $filters[ $filter ],
				);
			}
		}

		if ( array() !== $meta ) {
			$args['meta_query'] = $meta;
		}

		$entries = array();

		foreach ( (array) get_terms( $args ) as $id ) {
			$entry = $this->get( (int) $id );

			if ( null !== $entry ) {
				$entries[ (int) $id ] = $entry;
			}
		}

		return $entries;
	}
}
