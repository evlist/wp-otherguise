<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The entries of the index, in memory.
 *
 * @package Otherguise
 */

use Otherguise\Index\Entries;

/**
 * Stands for the taxonomy `index_entry`. Each entry created is also declared as a `WP_Term` of the stubs, so that a mention can point at it.
 */
final class Otherguise_Test_Memory_Entries implements Entries {
	/**
	 * The entries by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $entries = array();

	/**
	 * The last id given.
	 *
	 * @var int
	 */
	private $last = 100;

	/**
	 * The ids of the entries that hold a key.
	 *
	 * @param string $key Key.
	 * @return int[]
	 */
	public function with_key( $key ) {
		$ids = array();

		foreach ( $this->entries as $id => $entry ) {
			if ( in_array( $key, $entry['keys'], true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Creates an entry.
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

		$this->entries[ $id ] = array(
			'id'           => $id,
			'label'        => $label,
			'kind'         => $kind,
			'parent'       => (int) $parent,
			'status'       => $status,
			'keys'         => array_values( array_unique( $keys ) ),
			'kind_by_hand' => false,
		);

		$GLOBALS['otherguise_test_objects']['WP_Term'][ $id ] = new WP_Term( $id, $label );

		return $id;
	}

	/**
	 * Adds keys.
	 *
	 * @param int      $id   Entry.
	 * @param string[] $keys Keys.
	 * @return void
	 */
	public function add_keys( $id, array $keys ) {
		$this->entries[ $id ]['keys'] = array_values( array_unique( array_merge( $this->entries[ $id ]['keys'], $keys ) ) );
	}

	/**
	 * Sets a kind.
	 *
	 * @param int    $id      Entry.
	 * @param string $kind    Kind.
	 * @param bool   $by_hand By hand.
	 * @return void
	 */
	public function set_kind( $id, $kind, $by_hand ) {
		$this->entries[ $id ]['kind'] = $kind;

		if ( $by_hand ) {
			$this->entries[ $id ]['kind_by_hand'] = true;
		}
	}

	/**
	 * Deletes an entry.
	 *
	 * @param int $id Entry.
	 * @return void
	 */
	public function delete( $id ) {
		unset( $this->entries[ $id ], $GLOBALS['otherguise_test_objects']['WP_Term'][ $id ] );
	}

	/**
	 * Reads an entry.
	 *
	 * @param int $id Entry.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		return $this->entries[ $id ] ?? null;
	}

	/**
	 * The entries, filtered.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( array $filters = array() ) {
		return array_filter(
			$this->entries,
			static function ( $entry ) use ( $filters ) {
				foreach ( array( 'kind', 'status', 'parent' ) as $name ) {
					if ( isset( $filters[ $name ] ) && (string) $entry[ $name ] !== (string) $filters[ $name ] ) {
						return false;
					}
				}

				return true;
			}
		);
	}
}
