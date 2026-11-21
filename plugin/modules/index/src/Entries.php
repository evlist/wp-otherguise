<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Where the entries are kept.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The store of the entries (slice 600). An entry is a record: id, label, kind, parent, status, keys. The WordPress implementation keeps them
 * as terms of the taxonomy `index_entry`; the tests use one in memory.
 */
interface Entries {
	public const APPROVED  = 'approved';
	public const CANDIDATE = 'candidate';
	public const REFUSED   = 'refused';

	/**
	 * The ids of the entries that hold a key.
	 *
	 * @param string $key Key.
	 * @return int[]
	 */
	public function with_key( $key );

	/**
	 * Creates an entry.
	 *
	 * @param string   $label  Label.
	 * @param string   $kind   Kind.
	 * @param int      $parent Parent entry, 0 for none.
	 * @param string   $status Status.
	 * @param string[] $keys   Keys.
	 * @return int The id.
	 */
	public function create( $label, $kind, $parent, $status, array $keys );

	/**
	 * Adds keys to an entry (permanent: this is what linking is).
	 *
	 * @param int      $id   Entry.
	 * @param string[] $keys Keys.
	 * @return void
	 */
	public function add_keys( $id, array $keys );

	/**
	 * Sets the kind of an entry.
	 *
	 * @param int    $id      Entry.
	 * @param string $kind    Kind.
	 * @param bool   $by_hand Whether a person chose it: it is then never overwritten by an automatic change.
	 * @return void
	 */
	public function set_kind( $id, $kind, $by_hand );

	/**
	 * Deletes an entry.
	 *
	 * @param int $id Entry.
	 * @return void
	 */
	public function delete( $id );

	/**
	 * Reads an entry.
	 *
	 * @param int $id Entry.
	 * @return array{id:int,label:string,kind:string,parent:int,status:string,keys:string[],kind_by_hand:bool}|null
	 */
	public function get( $id );

	/**
	 * The entries, filtered.
	 *
	 * @param array{kind?:string,status?:string,parent?:int} $filters Filters.
	 * @return array<int, array{id:int,label:string,kind:string,parent:int,status:string,keys:string[],kind_by_hand:bool}>
	 */
	public function all( array $filters = array() );
}
