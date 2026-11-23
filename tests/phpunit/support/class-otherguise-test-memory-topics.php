<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The topics of the index, in memory.
 *
 * @package Otherguise
 */

use Otherguise\Index\Topics;

/**
 * Stands for the taxonomy `index_topic`. Each topic created is also declared as a `WP_Term` of the stubs, so that a mention can point at it.
 */
final class Otherguise_Test_Memory_Topics implements Topics {
	/**
	 * The topics by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $topics = array();

	/**
	 * The last id given.
	 *
	 * @var int
	 */
	private $last = 100;

	/**
	 * The ids of the topics that hold a key.
	 *
	 * @param string $key Key.
	 * @return int[]
	 */
	public function with_key( $key ) {
		$ids = array();

		foreach ( $this->topics as $id => $topic ) {
			if ( in_array( $key, $topic['keys'], true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Creates a topic.
	 *
	 * @param string   $label  Label.
	 * @param string   $kind   Kind.
	 * @param int      $parent Parent.
	 * @param string   $status Status.
	 * @param string[] $keys   Keys.
	 * @return int
	 */
	public function create( $label, $kind, $parent, $status, array $keys ) {
		$id = ++$this->last;

		$this->topics[ $id ] = array(
			'id'           => $id,
			'label'        => $label,
			'kind'         => $kind,
			'parent'       => (int) $parent,
			'status'       => $status,
			'keys'         => array_values( array_unique( $keys ) ),
			'kind_by_hand' => false,
			'label_by_hand' => false,
			'enrich'       => array(),
		);

		$GLOBALS['otherguise_test_objects']['WP_Term'][ $id ] = new WP_Term( $id, $label );

		return $id;
	}

	/**
	 * Adds keys.
	 *
	 * @param int      $id   Topic.
	 * @param string[] $keys Keys.
	 * @return void
	 */
	public function add_keys( $id, array $keys ) {
		$this->topics[ $id ]['keys'] = array_values( array_unique( array_merge( $this->topics[ $id ]['keys'], $keys ) ) );
	}

	/**
	 * Sets a kind.
	 *
	 * @param int    $id      Topic.
	 * @param string $kind    Kind.
	 * @param bool   $by_hand By hand.
	 * @return void
	 */
	public function set_kind( $id, $kind, $by_hand ) {
		$this->topics[ $id ]['kind'] = $kind;

		if ( $by_hand ) {
			$this->topics[ $id ]['kind_by_hand'] = true;
		}
	}

	/**
	 * Puts a topic under another.
	 *
	 * @param int $id     Topic.
	 * @param int $parent Parent.
	 * @return void
	 */
	public function set_parent( $id, $parent ) {
		$this->topics[ $id ]['parent'] = (int) $parent;
	}

	/**
	 * Changes a label.
	 *
	 * @param int    $id    Topic.
	 * @param string $label Label.
	 * @return void
	 */
	public function set_label( $id, $label ) {
		$this->topics[ $id ]['label'] = $label;
	}

	/**
	 * The state of the enrichment.
	 *
	 * @param int $id Topic.
	 * @return array<string, mixed>
	 */
	public function enrich_state( $id ) {
		return $this->topics[ $id ]['enrich'] ?? array();
	}

	/**
	 * Keeps the state of the enrichment.
	 *
	 * @param int                  $id    Topic.
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	public function set_enrich_state( $id, array $state ) {
		$this->topics[ $id ]['enrich'] = $state;
	}

	/**
	 * Deletes a topic.
	 *
	 * @param int $id Topic.
	 * @return void
	 */
	public function delete( $id ) {
		unset( $this->topics[ $id ], $GLOBALS['otherguise_test_objects']['WP_Term'][ $id ] );
	}

	/**
	 * Reads a topic.
	 *
	 * @param int $id Topic.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		return $this->topics[ $id ] ?? null;
	}

	/**
	 * The topics, filtered.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( array $filters = array() ) {
		return array_filter(
			$this->topics,
			static function ( $topic ) use ( $filters ) {
				foreach ( array( 'kind', 'status', 'parent' ) as $name ) {
					if ( isset( $filters[ $name ] ) && (string) $topic[ $name ] !== (string) $filters[ $name ] ) {
						return false;
					}
				}

				return true;
			}
		);
	}
}
