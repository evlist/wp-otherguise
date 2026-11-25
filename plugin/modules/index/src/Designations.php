<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The store of the motifs.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The motifs (slice 625): **the way a source designates something, wherever it is found**. A motif is what is assigned to a topic; its
 * observations are the places where it was found. The assignment itself is a statement of Triples (`Observations`); the store keeps the motif,
 * its evidence, its state and the topic the system proposed.
 */
interface Designations {
	public const PENDING   = 'pending';
	public const AMBIGUOUS = 'ambiguous';
	public const ASSIGNED  = 'assigned';

	/**
	 * A person decided that the motif is not for the index (slice 635): no topic, and a reading leaves it alone.
	 */
	public const EXCLUDED = 'excluded';

	/**
	 * The motif that has a key.
	 *
	 * @param string $key Key.
	 * @return int The id, or 0.
	 */
	public function find( $key );

	/**
	 * Creates a motif.
	 *
	 * @param string               $key      Key.
	 * @param string               $source   The leaf of the registry of the sources (`post/link`).
	 * @param array<string, mixed> $evidence What the source saw (never a URL or a precise coordinate).
	 * @return int The id.
	 */
	public function create( $key, $source, array $evidence );

	/**
	 * A motif.
	 *
	 * @param int $id Id.
	 * @return array{id:int,key:string,source:string,evidence:array,state:string,proposal:array{topic:int,why:string,ambiguity?:array{level:string,key:string,candidates:int[]}}}|null
	 */
	public function get( $id );

	/**
	 * Says what the system found: its state and the topic it proposed.
	 *
	 * @param int                         $id       Id.
	 * @param string                      $state    `pending`, `ambiguous` or `assigned`.
	 * @param array{topic:int,why:string} $proposal The topic proposed and why.
	 * @return void
	 */
	public function set_state( $id, $state, array $proposal );

	/**
	 * What the enrichment learnt about a motif (slice 627): `state` (`done`, `missing`, `ambiguous`, `none`), `qid`, `label`, `kind`, `date`, `due`.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed> Empty when the motif was not looked at.
	 */
	public function enrichment( $id );

	/**
	 * Keeps what the enrichment learnt.
	 *
	 * @param int                  $id    Id.
	 * @param array<string, mixed> $state What it learnt.
	 * @return void
	 */
	public function set_enrichment( $id, array $state );

	/**
	 * Deletes a motif.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public function delete( $id );

	/**
	 * The motifs, with filters.
	 *
	 * @param array{source?:string,state?:string} $filters Filters: a source (with its sub-sources) and a state.
	 * @return array<int, array> By id.
	 */
	public function all( array $filters = array() );
}
