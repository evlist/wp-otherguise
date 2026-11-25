<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The predicates of the module.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

use Otherguise\Triples\Predicate\PredicateDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The predicates that the module registers in Triples: the mentions (derived), the observations and the assignments of the motifs (slice 626), and
 * the qualifiers that they share.
 */
final class Predicates {
	/**
	 * Registers the predicates.
	 *
	 * @param \Otherguise\Triples\Predicate\PredicateRegistry $predicates The registry.
	 * @return void
	 */
	public static function register( $predicates ) {
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => Mentions::MENTIONS,
					'label'         => __( 'Mentions', 'otherguise' ),
					'inverse_label' => __( 'Is mentioned by', 'otherguise' ),
					'subject_types' => array( 'post' ),
					'object_types'  => array( 'term' ),
					'qualified_by'  => array( Mentions::SOURCE, Mentions::STRENGTH, Mentions::ROLE, Mentions::ANCHOR, Mentions::VIA, Mentions::VISIBLE ),
				)
			)
		);
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => Observations::OBSERVES,
					'label'         => __( 'Observes', 'otherguise' ),
					'inverse_label' => __( 'Is observed in', 'otherguise' ),
					'subject_types' => array( 'post' ),
					'object_types'  => array( 'term' ),
					'qualified_by'  => array( Mentions::SOURCE, Mentions::STRENGTH, Mentions::VIA, Mentions::VISIBLE ),
				)
			)
		);
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => Observations::DESIGNATES,
					'label'         => __( 'Is assigned to', 'otherguise' ),
					'inverse_label' => __( 'Has the motif', 'otherguise' ),
					'subject_types' => array( 'term' ),
					'object_types'  => array( 'term' ),
					'qualified_by'  => array( Observations::BY ),
				)
			)
		);
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => Observations::BY,
					'label'         => __( 'Decided by', 'otherguise' ),
					'subject_types' => array( 'statement' ),
					'object_types'  => array( 'string' ),
					'qualifies'     => array( Observations::DESIGNATES ),
				)
			)
		);

		foreach ( array(
			Mentions::SOURCE   => __( 'Said by the source', 'otherguise' ),
			Mentions::STRENGTH => __( 'Strength', 'otherguise' ),
			Mentions::ROLE     => __( 'Role', 'otherguise' ),
			Mentions::ANCHOR   => __( 'Anchor', 'otherguise' ),
			Mentions::VIA      => __( 'Produced by', 'otherguise' ),
			Mentions::VISIBLE  => __( 'Shown in the mode', 'otherguise' ),
		) as $slug => $label ) {
			$predicates->register(
				PredicateDefinition::from_array(
					array(
						'slug'          => $slug,
						'label'         => $label,
						'subject_types' => array( 'statement' ),
						'object_types'  => array( 'string' ),
						'qualifies'     => array( Mentions::MENTIONS, Observations::OBSERVES ),
					)
				)
			);
		}
	}
}
