<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Scratch site, must-use plugin: registers the predicates of the usage examples (media/illustrated-by, modes/mode, books/contains) and the entity type "mode", as the future modules will.
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- run on a scratch WordPress, outside the quality rules of the plugin.
// Scratch: registers the predicates of the usage examples, as the Modes, Books and Media Helper modules will.
use Otherguise\Triples\Entity\EntityType;
use Otherguise\Triples\Predicate\PredicateDefinition;

add_action( 'triples_register_entity_types', function ( $types ) {
	$types->register( new EntityType( 'mode', 'Mode', fn( $id ) => 1 === preg_match( '/^[a-z]+\z/', $id ), null, fn( $id ) => in_array( $id, array( 'web', 'print' ), true ) ) );
} );
add_action( 'triples_register_predicates', function ( $r ) {
	foreach ( array(
		array( 'slug' => 'media/illustrated-by', 'label' => 'Illustrated by', 'inverse_label' => 'Illustrates', 'subject_types' => array( 'post' ), 'object_types' => array( 'attachment' ), 'qualified_by' => array( 'modes/mode' ) ),
		array( 'slug' => 'modes/mode', 'label' => 'In mode', 'subject_types' => array( 'statement' ), 'object_types' => array( 'mode' ) ),
		array( 'slug' => 'books/contains', 'label' => 'Contains', 'inverse_label' => 'Part of', 'subject_types' => array( 'post' ), 'object_types' => array( 'post' ), 'qualified_by' => array( 'modes/mode' ) ),
	) as $d ) {
		$r->register( PredicateDefinition::from_array( $d ) );
	}
} );
