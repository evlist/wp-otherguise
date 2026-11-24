<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The designations of the index, in memory.
 *
 * @package Otherguise
 */

use Otherguise\Index\Designations;
use Otherguise\Index\Sources;

/**
 * Stands for the taxonomy `index_designation`. Each designation created is also declared as a `WP_Term` of the stubs, so that an observation can point at it.
 */
final class Otherguise_Test_Memory_Designations implements Designations {
	/**
	 * The designations by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $designations = array();

	/**
	 * The last id given.
	 *
	 * @var int
	 */
	private $last = 5000;

	/**
	 * The designation that has a key.
	 *
	 * @param string $key Key.
	 * @return int
	 */
	public function find( $key ) {
		foreach ( $this->designations as $id => $one ) {
			if ( $one['key'] === $key ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Creates a designation.
	 *
	 * @param string $key      Key.
	 * @param string $source   Source.
	 * @param array  $evidence Evidence.
	 * @return int
	 */
	public function create( $key, $source, array $evidence ) {
		$id = ++$this->last;

		$this->designations[ $id ] = array(
			'id'       => $id,
			'key'      => $key,
			'source'   => $source,
			'evidence' => $evidence,
			'state'    => Designations::PENDING,
			'proposal' => array(
				'topic' => 0,
				'why'   => '',
			),
		);

		$GLOBALS['otherguise_test_objects']['WP_Term'][ $id ] = new WP_Term( $id, $key );

		return $id;
	}

	/**
	 * A designation.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( $id ) {
		return $this->designations[ (int) $id ] ?? null;
	}

	/**
	 * Says what the system found.
	 *
	 * @param int    $id       Id.
	 * @param string $state    State.
	 * @param array  $proposal Proposal.
	 * @return void
	 */
	public function set_state( $id, $state, array $proposal ) {
		$this->designations[ (int) $id ]['state']    = $state;
		$this->designations[ (int) $id ]['proposal'] = $proposal;
	}

	/**
	 * Deletes a designation.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public function delete( $id ) {
		unset( $this->designations[ (int) $id ], $GLOBALS['otherguise_test_objects']['WP_Term'][ (int) $id ] );
	}

	/**
	 * The designations.
	 *
	 * @param array $filters Filters.
	 * @return array<int, array>
	 */
	public function all( array $filters = array() ) {
		return array_filter(
			$this->designations,
			static fn( $one ) => ( ! isset( $filters['source'] ) || Sources::is_a( $one['source'], (string) $filters['source'] ) )
				&& ( ! isset( $filters['state'] ) || $one['state'] === $filters['state'] )
		);
	}
}
