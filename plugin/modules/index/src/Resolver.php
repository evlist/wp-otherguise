<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ties candidates to entries.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The resolver (slice 600). A candidate is looked up **by its keys** among the keys of the entries:
 *
 * - the keys are tried most reliable first, the label key last: the **first key that matches** decides;
 * - one entry: it is that entry (the other keys of the candidate are added to it, so that a later source finds it by any of them);
 * - none: an entry is created, `approved` when the source is sure, `candidate` otherwise;
 * - several entries: the candidate is **ambiguous**, recorded as a `candidate` entry that carries its keys, and not mentioned until a
 *   person links it (adding a key to an entry is what linking is).
 *
 * The label key (`label:<folded label>`) is a key of every entry, scoped by the parent (`label:<parent id>/<folded label>`), so that two
 * homonyms with two parents stay two entries. A candidate with no key at all is looked up by its label key only.
 */
final class Resolver {
	public const RESOLVED  = 'resolved';
	public const CREATED   = 'created';
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * The store.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Builds the resolver.
	 *
	 * @param Entries $entries The store.
	 */
	public function __construct( Entries $entries ) {
		$this->entries = $entries;
	}

	/**
	 * Resolves a candidate.
	 *
	 * @param Candidate $candidate The candidate.
	 * @param bool      $sure      Whether its source is sure (a new entry is then approved).
	 * @param bool      $dry       Write nothing: say what would happen (an entry that would be created has the id 0).
	 * @return array{result:string,entry:int,new:int} `entry` is 0 when ambiguous; `new` counts the entries created, the broader ones included.
	 */
	public function resolve( Candidate $candidate, $sure = true, $dry = false ) {
		$parent = 0;
		$above  = array( 'new' => 0 );

		if ( null !== $candidate->parent ) {
			$above  = $this->resolve( $candidate->parent, $sure, $dry );
			$parent = $above['entry'];

			// An ambiguous parent makes the child ambiguous; a parent that would be created makes the child new.
			if ( self::AMBIGUOUS === $above['result'] || ( $dry && 0 === $parent ) ) {
				return array(
					'result' => self::AMBIGUOUS === $above['result'] ? self::AMBIGUOUS : self::CREATED,
					'entry'  => 0,
					'new'    => $above['new'] + ( self::AMBIGUOUS === $above['result'] ? 0 : 1 ),
				);
			}
		}

		$label = self::label_key( $parent, $candidate->label );
		$keys  = array_merge( $candidate->keys, array( $label ) );
		$found = array();

		// The first key that matches decides: the keys come most reliable first and the label comes last.
		foreach ( $keys as $key ) {
			$found = $this->without_refused( $this->entries->with_key( $key ) );

			if ( array() !== $found ) {
				if ( $key === $label && 1 === count( $found ) && $this->conflicts( (int) reset( $found ), $candidate ) ) {
					$found[] = 0; // Same label, another kind: a person decides.
				}

				break;
			}
		}

		if ( count( $found ) > 1 ) {
			if ( ! $dry ) {
				$this->record_ambiguous( $candidate, $parent );
			}

			return array(
				'result' => self::AMBIGUOUS,
				'entry'  => 0,
				'new'    => $above['new'],
			);
		}

		if ( 1 === count( $found ) ) {
			$id = (int) reset( $found );

			if ( ! $dry ) {
				$this->entries->add_keys( $id, $keys );
			}

			return array(
				'result' => self::RESOLVED,
				'entry'  => $id,
				'new'    => $above['new'],
			);
		}

		if ( $dry ) {
			return array(
				'result' => self::CREATED,
				'entry'  => 0,
				'new'    => $above['new'] + 1,
			);
		}

		$id = $this->entries->create( $candidate->label, $candidate->kind, $parent, $sure ? Entries::APPROVED : Entries::CANDIDATE, $keys );

		return array(
			'result' => self::CREATED,
			'entry'  => $id,
			'new'    => $above['new'] + 1,
		);
	}

	/**
	 * Whether an entry found by the label alone has a kind that cannot be the candidate's.
	 *
	 * @param int       $id        Entry.
	 * @param Candidate $candidate Candidate.
	 * @return bool
	 */
	private function conflicts( $id, Candidate $candidate ) {
		$entry = $this->entries->get( $id );

		return null !== $entry && '' !== $entry['kind'] && Kinds::OTHER !== $entry['kind'] && Kinds::OTHER !== $candidate->kind && $entry['kind'] !== $candidate->kind;
	}

	/**
	 * The key of a label under a parent.
	 *
	 * @param int    $parent Parent entry.
	 * @param string $label  Label.
	 * @return string
	 */
	public static function label_key( $parent, $label ) {
		return 'label:' . (int) $parent . '/' . Folding::key( $label );
	}

	/**
	 * Drops the refused entries.
	 *
	 * @param int[] $ids Entries.
	 * @return int[]
	 */
	private function without_refused( array $ids ) {
		return array_values(
			array_filter(
				$ids,
				function ( $id ) {
					$entry = $this->entries->get( $id );

					return null !== $entry && Entries::REFUSED !== $entry['status'];
				}
			)
		);
	}

	/**
	 * Records an ambiguous candidate once: a `candidate` entry that holds the keys it was met with, found again by its first key.
	 *
	 * @param Candidate $candidate The candidate.
	 * @param int       $parent    Parent entry.
	 * @return void
	 */
	private function record_ambiguous( Candidate $candidate, $parent ) {
		$marker = 'ambiguous:' . ( $candidate->keys[0] ?? self::label_key( $parent, $candidate->label ) );

		if ( array() === $this->entries->with_key( $marker ) ) {
			$this->entries->create( $candidate->label, $candidate->kind, 0, Entries::CANDIDATE, array( $marker ) );
		}
	}
}
