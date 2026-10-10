<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the modes register in the Triples module.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Integration;

use Otherguise\Modes\Mode\ModeDefinition;
use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Triples\Entity\EntityType;
use Otherguise\Triples\Predicate\PredicateDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The entity type `mode` (its ids are the slugs of the registered modes, a `ModeDefinition` object stands for one) and the predicates
 * `modes/mode`, the qualifier that says in which mode a statement is shown, and `modes/stylesheet`, which gives a mode a stylesheet (a file of
 * the Media Library). Hooked to the registration actions of the Triples module.
 */
final class TriplesIntegration {
	/**
	 * Modes.
	 *
	 * @var ModeRegistry
	 */
	private $modes;

	/**
	 * Builds the integration.
	 *
	 * @param ModeRegistry $modes Modes.
	 */
	public function __construct( ModeRegistry $modes ) {
		$this->modes = $modes;
	}

	/**
	 * Registers the entity type `mode`. Action `triples_register_entity_types`.
	 *
	 * @param \Otherguise\Triples\Entity\EntityTypeRegistry $types Entity types.
	 * @return void
	 */
	public function register_entity_type( $types ) {
		$modes = $this->modes;

		$types->register(
			new EntityType(
				'mode',
				'Mode',
				static function ( $id ) {
					return 1 === preg_match( ModeDefinition::SLUG_PATTERN, $id );
				},
				null,
				static function ( $id ) use ( $modes ) {
					return $modes->has( $id );
				},
				static function ( $value ) {
					return $value instanceof ModeDefinition ? $value->slug() : null;
				},
				static function ( $id ) use ( $modes ) {
					return $modes->has( $id ) ? $modes->get( $id ) : null;
				},
				static function ( $id ) use ( $modes ) {
					return $modes->has( $id ) ? array(
						'label' => $modes->get( $id )->label(),
						'url'   => null,
					) : null;
				}
			)
		);
	}

	/**
	 * Registers the predicate `modes/mode`. Action `triples_register_predicates`.
	 *
	 * @param \Otherguise\Triples\Predicate\PredicateRegistry $predicates Predicates.
	 * @return void
	 */
	public function register_predicate( $predicates ) {
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => 'modes/mode',
					'label'         => __( 'In mode', 'otherguise' ),
					'subject_types' => array( 'statement' ),
					'object_types'  => array( 'mode' ),
				)
			)
		);
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => 'modes/stylesheet',
					'label'         => __( 'Has stylesheet', 'otherguise' ),
					'inverse_label' => __( 'Stylesheet of', 'otherguise' ),
					'subject_types' => array( 'mode' ),
					'object_types'  => array( 'attachment' ),
				)
			)
		);
	}
}
