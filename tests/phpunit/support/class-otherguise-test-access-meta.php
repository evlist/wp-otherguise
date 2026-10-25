<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A double of the post metas, for the tests of the private links.
 *
 * @package Otherguise
 */

/**
 * Post metas in memory, with the methods of `Otherguise\\Access\\Link\\PostMeta`.
 */
final class Otherguise_Test_Access_Meta {

	/**
	 * Values by post id and key.
	 *
	 * @var array<int, array<string, string>>
	 */
	public $values = array();

	/**
	 * Reads a meta.
	 *
	 * @param int    $id  Id.
	 * @param string $key Key.
	 * @return string
	 */
	public function get( $id, $key ) {
		return $this->values[ $id ][ $key ] ?? '';
	}

	/**
	 * Writes a meta.
	 *
	 * @param int    $id    Id.
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @return void
	 */
	public function set( $id, $key, $value ) {
		$this->values[ $id ][ $key ] = $value;
	}

	/**
	 * Deletes a meta.
	 *
	 * @param int    $id  Id.
	 * @param string $key Key.
	 * @return void
	 */
	public function delete( $id, $key ) {
		unset( $this->values[ $id ][ $key ] );
	}

	/**
	 * The ids of the posts that have a meta, with a value or not.
	 *
	 * @param string      $key   Key.
	 * @param string|null $value Value, or null for any.
	 * @return int[]
	 */
	public function find( $key, $value = null ) {
		$ids = array();

		foreach ( $this->values as $id => $metas ) {
			if ( isset( $metas[ $key ] ) && ( null === $value || $metas[ $key ] === $value ) ) {
				$ids[] = $id;
			}
		}

		sort( $ids );

		return $ids;
	}
}
