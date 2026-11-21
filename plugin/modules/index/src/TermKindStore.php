<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The kinds as terms of a taxonomy.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The kinds kept as terms of the hidden hierarchical taxonomy `index_kind` (slice 603): the slug of the term is the identifier of the kind,
 * its name the label, its parent the broader kind; the term meta hold the CSS suffix and the built-in flag.
 */
final class TermKindStore implements KindStore {
	public const TAXONOMY = 'index_kind';
	public const CSS      = '_index_kind_css';
	public const BUILTIN  = '_index_kind_builtin';

	/**
	 * Registers the taxonomy: hierarchical, not public, no screen of its own. Action `init`.
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			array(),
			array(
				'labels'       => array(
					'name'          => __( 'Index kinds', 'otherguise' ),
					'singular_name' => __( 'Index kind', 'otherguise' ),
				),
				'public'       => false,
				'hierarchical' => true,
				'show_ui'      => false,
				'show_in_rest' => false,
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
	}

	/**
	 * The kinds, by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);
		$slugs = array();
		$kinds = array();

		if ( ! is_array( $terms ) ) {
			return array();
		}

		foreach ( $terms as $term ) {
			$slugs[ (int) $term->term_id ] = (string) $term->slug;
		}

		foreach ( $terms as $term ) {
			$css = (string) get_term_meta( (int) $term->term_id, self::CSS, true );

			$kinds[ (string) $term->slug ] = array(
				'slug'    => (string) $term->slug,
				'label'   => (string) $term->name,
				'parent'  => $slugs[ (int) $term->parent ] ?? '',
				'css'     => '' === $css ? (string) $term->slug : $css,
				'builtin' => '1' === (string) get_term_meta( (int) $term->term_id, self::BUILTIN, true ),
			);
		}

		return $kinds;
	}

	/**
	 * Adds a kind.
	 *
	 * @param string $slug    Slug.
	 * @param string $label   Label.
	 * @param string $parent  Broader kind.
	 * @param string $css     CSS suffix.
	 * @param bool   $builtin Built-in kind.
	 * @return true|string
	 */
	public function add( $slug, $label, $parent, $css, $builtin ) {
		$parent_id = '' === $parent ? 0 : $this->id( $parent );
		$inserted  = wp_insert_term(
			$label,
			self::TAXONOMY,
			array(
				'slug'   => $slug,
				'parent' => (int) $parent_id,
			)
		);

		if ( is_wp_error( $inserted ) ) {
			return $inserted->get_error_message();
		}

		update_term_meta( (int) $inserted['term_id'], self::CSS, $css );

		if ( $builtin ) {
			update_term_meta( (int) $inserted['term_id'], self::BUILTIN, '1' );
		}

		return true;
	}

	/**
	 * Changes the label and the CSS class.
	 *
	 * @param string $slug  Slug.
	 * @param string $label Label.
	 * @param string $css   CSS suffix.
	 * @return true|string
	 */
	public function update( $slug, $label, $css ) {
		$id = $this->id( $slug );

		if ( 0 === $id ) {
			return 'No such kind: ' . $slug . '.';
		}

		$updated = wp_update_term( $id, self::TAXONOMY, array( 'name' => $label ) );

		if ( is_wp_error( $updated ) ) {
			return $updated->get_error_message();
		}

		update_term_meta( $id, self::CSS, $css );

		return true;
	}

	/**
	 * Moves a kind.
	 *
	 * @param string $slug   Slug.
	 * @param string $parent New broader kind.
	 * @return true|string
	 */
	public function move( $slug, $parent ) {
		$id = $this->id( $slug );

		if ( 0 === $id ) {
			return 'No such kind: ' . $slug . '.';
		}

		$updated = wp_update_term( $id, self::TAXONOMY, array( 'parent' => '' === $parent ? 0 : $this->id( $parent ) ) );

		return is_wp_error( $updated ) ? $updated->get_error_message() : true;
	}

	/**
	 * Deletes a kind.
	 *
	 * @param string $slug Slug.
	 * @return true|string
	 */
	public function delete( $slug ) {
		$id = $this->id( $slug );

		if ( 0 === $id ) {
			return 'No such kind: ' . $slug . '.';
		}

		$deleted = wp_delete_term( $id, self::TAXONOMY );

		return true === $deleted ? true : 'The kind could not be deleted.';
	}

	/**
	 * How many terms and entries have a kind.
	 *
	 * @param string $slug Slug.
	 * @return array{terms:int,entries:int}
	 */
	public function usage( $slug ) {
		$usage = array(
			'terms'   => 0,
			'entries' => 0,
		);

		foreach ( $this->holders( $slug ) as $row ) {
			if ( TermEntries::TAXONOMY === $row['taxonomy'] ) {
				++$usage['entries'];
			} else {
				++$usage['terms'];
			}
		}

		return $usage;
	}

	/**
	 * Gives the terms and entries of a kind to another.
	 *
	 * @param string $from Slug.
	 * @param string $to   Slug.
	 * @return int
	 */
	public function reassign( $from, $to ) {
		$rows = $this->holders( $from );

		foreach ( $rows as $row ) {
			update_term_meta( (int) $row['term_id'], TermEntries::KIND, $to );
		}

		return count( $rows );
	}

	/**
	 * The term of a kind.
	 *
	 * @param string $slug Slug.
	 * @return int 0 when there is none.
	 */
	private function id( $slug ) {
		$term = get_term_by( 'slug', $slug, self::TAXONOMY );

		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * The terms and entries that have a kind.
	 *
	 * @param string $slug Slug.
	 * @return array<int, array{term_id:int,taxonomy:string}>
	 */
	private function holders( $slug ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.term_id, t.taxonomy FROM {$wpdb->termmeta} m INNER JOIN {$wpdb->term_taxonomy} t ON t.term_id = m.term_id WHERE m.meta_key = %s AND m.meta_value = %s",
				TermEntries::KIND,
				(string) $slug
			),
			ARRAY_A
		);

		return array_map(
			static fn( $row ) => array(
				'term_id'  => (int) $row['term_id'],
				'taxonomy' => (string) $row['taxonomy'],
			),
			(array) $rows
		);
	}
}
