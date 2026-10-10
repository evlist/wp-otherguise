<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Scratch site, must-use plugin: registers the predicates of the usage examples that no module declares yet (books/contains). The entity type "mode" and the predicate modes/mode come from the Modes module, media/illustrated-by from the Media module.
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- run on a scratch WordPress, outside the quality rules of the plugin.
// Scratch: registers the predicates of the usage examples, as the Media Helper integration and the Books module will.
use Otherguise\Triples\Predicate\PredicateDefinition;

add_action( 'triples_register_predicates', function ( $r ) {
	foreach ( array(
		array( 'slug' => 'books/contains', 'label' => 'Contains', 'inverse_label' => 'Part of', 'subject_types' => array( 'post' ), 'object_types' => array( 'post' ), 'qualified_by' => array( 'modes/mode' ) ),
	) as $d ) {
		$r->register( PredicateDefinition::from_array( $d ) );
	}
} );
