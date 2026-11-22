<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Where the topics are kept.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The store of the topics (slice 600). A topic is a record: id, label, kind, parent, status, keys. The WordPress implementation keeps them
 * as terms of the taxonomy `index_topic`; the tests use one in memory.
 */
interface Topics {
	public const APPROVED  = 'approved';
	public const CANDIDATE = 'candidate';
	public const REFUSED   = 'refused';

	/**
	 * The ids of the topics that hold a key.
	 *
	 * @param string $key Key.
	 * @return int[]
	 */
	public function with_key( $key );

	/**
	 * Creates a topic.
	 *
	 * @param string   $label  Label.
	 * @param string   $kind   Kind.
	 * @param int      $parent Parent topic, 0 for none.
	 * @param string   $status Status.
	 * @param string[] $keys   Keys.
	 * @return int The id.
	 */
	public function create( $label, $kind, $parent, $status, array $keys );

	/**
	 * Adds keys to a topic (permanent: this is what linking is).
	 *
	 * @param int      $id   Topic.
	 * @param string[] $keys Keys.
	 * @return void
	 */
	public function add_keys( $id, array $keys );

	/**
	 * Sets the kind of a topic.
	 *
	 * @param int    $id      Topic.
	 * @param string $kind    Kind.
	 * @param bool   $by_hand Whether a person chose it: it is then never overwritten by an automatic change.
	 * @return void
	 */
	public function set_kind( $id, $kind, $by_hand );

	/**
	 * Changes the label of a topic (its slug and keys stay).
	 *
	 * @param int    $id    Topic.
	 * @param string $label Label.
	 * @return void
	 */
	public function set_label( $id, $label );

	/**
	 * The state of the Wikidata enrichment of a topic (slice 604): `state`, `date`, `due`, `qid`, `note`; empty when never looked at.
	 *
	 * @param int $id Topic.
	 * @return array<string, mixed>
	 */
	public function enrich_state( $id );

	/**
	 * Keeps the state of the enrichment of a topic.
	 *
	 * @param int                  $id    Topic.
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	public function set_enrich_state( $id, array $state );

	/**
	 * Deletes a topic.
	 *
	 * @param int $id Topic.
	 * @return void
	 */
	public function delete( $id );

	/**
	 * Reads a topic.
	 *
	 * @param int $id Topic.
	 * @return array{id:int,label:string,kind:string,parent:int,status:string,keys:string[],kind_by_hand:bool,label_by_hand:bool}|null
	 */
	public function get( $id );

	/**
	 * The topics, filtered.
	 *
	 * @param array{kind?:string,status?:string,parent?:int} $filters Filters.
	 * @return array<int, array{id:int,label:string,kind:string,parent:int,status:string,keys:string[],kind_by_hand:bool}>
	 */
	public function all( array $filters = array() );
}
