<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Registrations and objects shared by the tests of the statement service.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityType;
use Otherguise\Triples\Module;
use Otherguise\Triples\Predicate\PredicateDefinition;
use Otherguise\Triples\Service\InvalidStatementException;

/**
 * Builds a module with the predicates of the examples of the documentation, and the WordPress objects they use.
 */
final class Otherguise_Test_Fixtures {
	/**
	 * Declares the WordPress objects: posts 12 and 13, attachments 88, 90, 91 and 94, users 3, 4 and 5, term 7.
	 *
	 * @return void
	 */
	public static function objects() {
		otherguise_test_wp_objects(
			array(
				new WP_Post( 12 ),
				new WP_Post( 13 ),
				new WP_Post( 88, 'attachment' ),
				new WP_Post( 90, 'attachment' ),
				new WP_Post( 91, 'attachment' ),
				new WP_Post( 94, 'attachment' ),
				new WP_User( 3 ),
				new WP_User( 4 ),
				new WP_User( 5 ),
				new WP_Term( 7 ),
			)
		);
	}

	/**
	 * Builds a module on a database object, with a mode entity type and the predicates of the examples.
	 *
	 * @param object $wpdb Database object.
	 * @return Module
	 */
	public static function module( $wpdb ) {
		self::objects();

		return new Module(
			static function ( $hook, $registry ) {
				if ( 'triples_register_entity_types' === $hook ) {
					$modes = array( 'web', 'print', 'book' );

					$registry->register(
						new EntityType(
							'mode',
							'Mode',
							static function ( $id ) {
								return 1 === preg_match( '/^[a-z]+\z/', $id );
							},
							null,
							static function ( $id ) use ( $modes ) {
								return in_array( $id, $modes, true );
							}
						)
					);
				}

				if ( 'triples_register_predicates' === $hook ) {
					foreach ( self::predicates() as $definition ) {
						$registry->register( PredicateDefinition::from_array( $definition ) );
					}
				}
			},
			$wpdb
		);
	}

	/**
	 * Returns the definitions of the predicates of the examples.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function predicates() {
		return array(
			array(
				'slug'          => 'media/illustrated-by',
				'label'         => 'Illustrated by',
				'subject_types' => array( 'post' ),
				'object_types'  => array( 'attachment' ),
				'qualified_by'  => array( 'modes/mode' ),
			),
			array(
				'slug'          => 'modes/mode',
				'label'         => 'In mode',
				'subject_types' => array( 'statement' ),
				'object_types'  => array( 'mode' ),
			),
			array(
				'slug'          => 'books/contains',
				'label'         => 'Contains',
				'subject_types' => array( 'post' ),
				'object_types'  => array( 'post' ),
				'qualified_by'  => array( 'modes/mode', 'books/pages' ),
			),
			array(
				'slug'                    => 'books/pages',
				'label'                   => 'Pages',
				'subject_types'           => array( 'statement' ),
				'object_types'            => array( 'integer' ),
				'max_objects_per_subject' => 1,
			),
			array(
				'slug'                    => 'test/friend',
				'label'                   => 'Friend of',
				'subject_types'           => array( 'user' ),
				'object_types'            => array( 'user' ),
				'symmetric'               => true,
				'max_objects_per_subject' => 2,
			),
			array(
				'slug'                    => 'test/owner',
				'label'                   => 'Owner',
				'subject_types'           => array( 'post' ),
				'object_types'            => array( 'user' ),
				'max_objects_per_subject' => 1,
				'max_subjects_per_object' => 2,
			),
			array(
				'slug'          => 'test/note',
				'label'         => 'Note',
				'subject_types' => array( 'post' ),
				'object_types'  => array( 'string', 'integer' ),
			),
			array(
				'slug'          => 'test/tag',
				'label'         => 'Tag',
				'subject_types' => array( 'post' ),
				'object_types'  => array( 'string' ),
			),
			array(
				'slug'          => 'test/anything',
				'label'         => 'Anything',
				'subject_types' => array(),
				'object_types'  => array(),
			),
		);
	}

	/**
	 * Returns the error code of the refusal a call raises, or null when nothing is raised.
	 *
	 * @param callable $call Call.
	 * @return string|null
	 */
	public static function refusal( $call ) {
		try {
			$call();
		} catch ( InvalidStatementException $problem ) {
			return $problem->error_code();
		}

		return null;
	}
}
