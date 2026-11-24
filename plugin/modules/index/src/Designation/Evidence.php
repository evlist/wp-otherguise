<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What a designation keeps of what a candidate saw.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Index\Candidate;

defined( 'ABSPATH' ) || exit;

/**
 * The evidence of a designation (slices 626 and 631), and the candidate that it makes again: **every level of the chain** (its label, its kind, its keys),
 * from the broadest to the narrowest, so that a designation can be **resolved again without reading its page**: when a person chooses among the topics that a
 * level hesitated between, or when the resolver is run again. Never a URL or a precise coordinate (slice 606).
 */
final class Evidence {
	/**
	 * The most keys kept for a level.
	 */
	public const KEYS = 10;

	/**
	 * What a candidate saw.
	 *
	 * @param Candidate $candidate The candidate.
	 * @return array<string, mixed> `label`, `kind`, `keys`, `above` (the labels of the broader levels) and `levels` (every level, the broadest first).
	 */
	public static function of( Candidate $candidate ) {
		$levels = array();
		$above  = array();

		for ( $level = $candidate, $depth = 0; null !== $level && $depth < 30; $level = $level->parent, ++$depth ) {
			array_unshift(
				$levels,
				array(
					'label' => $level->label,
					'kind'  => $level->kind,
					'keys'  => array_slice( $level->keys, 0, self::KEYS ),
				)
			);

			if ( $level !== $candidate ) {
				array_unshift( $above, $level->label );
			}
		}

		return array(
			'label'  => $candidate->label,
			'kind'   => $candidate->kind,
			'keys'   => array_slice( $candidate->keys, 0, self::KEYS ),
			'above'  => $above,
			'levels' => $levels,
		);
	}

	/**
	 * The candidate that some evidence makes again. A designation read before the levels were kept has only the labels of the broader levels: they come back as
	 * levels of the kind `any`, which conflicts with none (the doubts that come from a kind are not found again, the others are).
	 *
	 * @param array<string, mixed> $evidence The evidence.
	 * @param string               $leaf     The source of the designation.
	 * @return Candidate
	 */
	public static function candidate( array $evidence, $leaf ) {
		$levels = (array) ( $evidence['levels'] ?? array() );

		if ( array() === $levels ) {
			foreach ( (array) ( $evidence['above'] ?? array() ) as $label ) {
				$levels[] = array(
					'label' => (string) $label,
					'kind'  => 'any',
					'keys'  => array(),
				);
			}

			$levels[] = array(
				'label' => (string) ( $evidence['label'] ?? '' ),
				'kind'  => (string) ( $evidence['kind'] ?? 'any' ),
				'keys'  => (array) ( $evidence['keys'] ?? array() ),
			);
		}

		$parent = null;

		foreach ( $levels as $level ) {
			$parent = new Candidate( (string) $level['label'], (string) $level['kind'], array_map( 'strval', (array) $level['keys'] ), $parent );
		}

		return null === $parent ? new Candidate( '', 'any', array() ) : $parent->from( (string) $leaf );
	}
}
