<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Scratch site, must-use plugin: registers the predicates of the usage examples (media/illustrated-by, books/contains), as the Media Helper integration and the Books module will. The entity type "mode" and the predicate modes/mode come from the Modes module.
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- run on a scratch WordPress, outside the quality rules of the plugin.
// Scratch: registers the predicates of the usage examples, as the Media Helper integration and the Books module will.
use Otherguise\Triples\Predicate\PredicateDefinition;

add_action( 'triples_register_predicates', function ( $r ) {
	foreach ( array(
		array( 'slug' => 'media/illustrated-by', 'label' => 'Illustrated by', 'inverse_label' => 'Illustrates', 'subject_types' => array( 'post' ), 'object_types' => array( 'attachment' ), 'qualified_by' => array( 'modes/mode' ) ),
		array( 'slug' => 'books/contains', 'label' => 'Contains', 'inverse_label' => 'Part of', 'subject_types' => array( 'post' ), 'object_types' => array( 'post' ), 'qualified_by' => array( 'modes/mode' ) ),
	) as $d ) {
		$r->register( PredicateDefinition::from_array( $d ) );
	}
} );
