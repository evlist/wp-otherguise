<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The designations as terms of a taxonomy.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Designations kept as terms of the hidden taxonomy `index_designation` (slice 626): the slug is a hash of the key (a lookup by key is what a reading does
 * most), the name is the key itself for whoever looks in the database, and the source, the evidence, the state and the proposal are term meta. Not tried
 * on a real WordPress site yet; the tests use a double.
 */
final class TermDesignations implements Designations {
	public const TAXONOMY = 'index_designation';
	public const KEY      = '_index_key';
	public const SOURCE   = '_index_source';
	public const EVIDENCE = '_index_evidence';
	public const STATE    = '_index_state';
	public const PROPOSAL = '_index_proposal';
	public const ENRICH   = '_index_enrich';

	/**
	 * Registers the taxonomy: flat, not public, no screen of its own. Action `init`.
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			array(),
			array(
				'labels'            => array(
					'name'          => __( 'Index designations', 'otherguise' ),
					'singular_name' => __( 'Index designation', 'otherguise' ),
				),
				'public'            => false,
				'hierarchical'      => false,
				'show_ui'           => false,
				'show_in_rest'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'show_admin_column' => false,
			)
		);
	}

	/**
	 * The slug of a key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function slug( $key ) {
		return sha1( (string) $key );
	}

	/**
	 * The designation that has a key.
	 *
	 * @param string $key Key.
	 * @return int
	 */
	public function find( $key ) {
		$term = get_term_by( 'slug', self::slug( $key ), self::TAXONOMY );

		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * Creates a designation.
	 *
	 * @param string               $key      Key.
	 * @param string               $source   Source.
	 * @param array<string, mixed> $evidence Evidence.
	 * @return int
	 */
	public function create( $key, $source, array $evidence ) {
		$made = wp_insert_term(
			(string) $key,
			self::TAXONOMY,
			array( 'slug' => self::slug( $key ) )
		);

		if ( is_wp_error( $made ) ) {
			return 0;
		}

		$id = (int) $made['term_id'];

		update_term_meta( $id, self::KEY, wp_slash( (string) $key ) );
		update_term_meta( $id, self::SOURCE, (string) $source );
		update_term_meta( $id, self::EVIDENCE, wp_slash( (string) wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		update_term_meta( $id, self::STATE, self::PENDING );

		return $id;
	}

	/**
	 * A designation.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( $id ) {
		$term = get_term( (int) $id, self::TAXONOMY );

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$evidence = json_decode( (string) get_term_meta( (int) $id, self::EVIDENCE, true ), true );
		$proposal = json_decode( (string) get_term_meta( (int) $id, self::PROPOSAL, true ), true );

		return array(
			'id'       => (int) $id,
			'key'      => (string) get_term_meta( (int) $id, self::KEY, true ),
			'source'   => (string) get_term_meta( (int) $id, self::SOURCE, true ),
			'evidence' => is_array( $evidence ) ? $evidence : array(),
			'state'    => (string) get_term_meta( (int) $id, self::STATE, true ),
			'proposal' => array(
				'topic' => (int) ( is_array( $proposal ) ? ( $proposal['topic'] ?? 0 ) : 0 ),
				'why'   => (string) ( is_array( $proposal ) ? ( $proposal['why'] ?? '' ) : '' ),
			),
		);
	}

	/**
	 * Says what the system found.
	 *
	 * @param int                         $id       Id.
	 * @param string                      $state    State.
	 * @param array{topic:int,why:string} $proposal Proposal.
	 * @return void
	 */
	public function set_state( $id, $state, array $proposal ) {
		update_term_meta( (int) $id, self::STATE, (string) $state );
		update_term_meta( (int) $id, self::PROPOSAL, wp_slash( (string) wp_json_encode( $proposal ) ) );
	}

	/**
	 * What the enrichment learnt about a designation.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>
	 */
	public function enrichment( $id ) {
		$state = json_decode( (string) get_term_meta( (int) $id, self::ENRICH, true ), true );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Keeps what the enrichment learnt.
	 *
	 * @param int                  $id    Id.
	 * @param array<string, mixed> $state What it learnt.
	 * @return void
	 */
	public function set_enrichment( $id, array $state ) {
		update_term_meta( (int) $id, self::ENRICH, wp_slash( (string) wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
	}

	/**
	 * Deletes a designation.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public function delete( $id ) {
		wp_delete_term( (int) $id, self::TAXONOMY );
	}

	/**
	 * The designations.
	 *
	 * @param array $filters Filters.
	 * @return array<int, array>
	 */
	public function all( array $filters = array() ) {
		$found = array();
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);
		$ids   = is_array( $terms ) ? array_map( static fn( $term ) => (int) $term->term_id, $terms ) : array();

		// The terms are in the cache with `get_terms`; the meta of all of them in one query, not six for each (a screen lists thousands).
		if ( array() !== $ids ) {
			update_termmeta_cache( $ids );
		}

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$one = $this->get( (int) $id );

			if ( null === $one ) {
				continue;
			}

			if ( isset( $filters['source'] ) && ! Sources::is_a( $one['source'], (string) $filters['source'] ) ) {
				continue;
			}

			if ( isset( $filters['state'] ) && $one['state'] !== (string) $filters['state'] ) {
				continue;
			}

			$found[ (int) $id ] = $one;
		}

		return $found;
	}
}
