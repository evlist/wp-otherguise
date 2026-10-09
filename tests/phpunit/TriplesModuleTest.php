<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the Triples module wiring.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityType;
use Otherguise\Triples\Module;
use Otherguise\Triples\Predicate\PredicateDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the wiring of the Triples module.
 *
 * @covers \Otherguise\Triples\Module
 */
class TriplesModuleTest extends TestCase {

	/**
	 * The registration actions receive the registries when they are first used.
	 *
	 * @return void
	 */
	public function test_the_registration_actions_receive_the_registries_when_they_are_first_used(): void {
		$fired  = array();
		$module = new Module(
			static function ( $hook, $registry ) use ( &$fired ) {
				$fired[] = $hook;

				if ( 'triples_register_entity_types' === $hook ) {
					$registry->register(
						new EntityType(
							'template',
							'Template',
							static function () {
								return true;
							}
						)
					);
				}

				if ( 'triples_register_predicates' === $hook ) {
					$registry->register(
						PredicateDefinition::from_array(
							array(
								'slug'          => 'modes/has-variant',
								'label'         => 'Has variant',
								'subject_types' => array( 'template' ),
							)
						)
					);
				}
			}
		);

		$this->assertSame( array(), $fired );
		$this->assertTrue( $module->predicates()->has( 'modes/has-variant' ) );
		$this->assertSame( array( 'triples_register_predicates', 'triples_register_entity_types' ), $fired, 'The qualifier types are read only when a predicate has qualifiers.' );
	}

	/**
	 * The qualifier type action runs when a predicate has qualifiers.
	 *
	 * @return void
	 */
	public function test_the_qualifier_type_action_runs_when_a_predicate_has_qualifiers(): void {
		$fired  = array();
		$module = new Module(
			static function ( $hook, $registry ) use ( &$fired ) {
				$fired[] = $hook;

				if ( 'triples_register_predicates' === $hook ) {
					$registry->register(
						PredicateDefinition::from_array(
							array(
								'slug'       => 'triples/related-to',
								'label'      => 'Related to',
								'qualifiers' => array(
									array(
										'name' => 'note',
										'type' => 'string',
									),
								),
							)
						)
					);
				}
			}
		);

		$module->predicates()->all();

		$this->assertSame( array( 'triples_register_predicates', 'triples_register_qualifier_types' ), $fired );
	}

	/**
	 * The module declares no dependency.
	 *
	 * @return void
	 */
	public function test_the_module_declares_no_dependency(): void {
		$module = new Module( static function () {} );

		$this->assertSame( 'triples', $module->id() );
		$this->assertSame( array(), $module->dependencies() );
	}
}
