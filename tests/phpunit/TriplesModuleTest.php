<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the wiring of the Triples module.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Datatype\DatatypeInterface;
use Otherguise\Triples\Entity\EntityType;
use Otherguise\Triples\Module;
use Otherguise\Triples\Predicate\PredicateDefinition;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-wpdb.php';

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
		$this->assertSame( array( 'triples_register_datatypes', 'triples_register_entity_types', 'triples_register_predicates' ), $fired, 'The types are registered before the callbacks of the predicates run.' );
	}

	/**
	 * The position qualifier is built in.
	 *
	 * @return void
	 */
	public function test_the_position_qualifier_is_built_in(): void {
		$module   = new Module( static function () {} );
		$position = $module->predicates()->get( 'triples/position' );

		$this->assertSame( array( 'statement' ), $position->subject_types() );
		$this->assertSame( array( 'integer' ), $position->object_types() );
		$this->assertSame( 1, $position->max_objects_per_subject() );
		$this->assertSame( array( '*' ), $position->qualifies() );
		$this->assertTrue( $module->predicates()->can_qualify( 'triples/position', 'triples/position' ) );
	}

	/**
	 * A module can register a datatype.
	 *
	 * @return void
	 */
	public function test_a_module_can_register_a_datatype(): void {
		$module = new Module(
			static function ( $hook, $registry ) {
				if ( 'triples_register_datatypes' === $hook ) {
					$registry->register(
						new class() implements DatatypeInterface {
							/**
							 * Name.
							 *
							 * @return string
							 */
							public function name() {
								return 'year';
							}

							/**
							 * XSD name.
							 *
							 * @return string
							 */
							public function datatype() {
								return 'xsd:gYear';
							}

							/**
							 * Validation.
							 *
							 * @param mixed $value Value.
							 * @return bool
							 */
							public function validate( $value ) {
								return 1 === preg_match( '/^[0-9]{4}\z/', (string) $value );
							}

							/**
							 * Normalization.
							 *
							 * @param mixed $value Value.
							 * @return string
							 */
							public function normalize( $value ) {
								return (string) $value;
							}
						}
					);
				}
			}
		);

		$this->assertTrue( $module->datatypes()->has( 'year' ) );
		$this->assertTrue( $module->datatypes()->get( 'year' )->validate( '2026' ) );
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

	/**
	 * Activation and boot create the table once and record the version.
	 *
	 * @return void
	 */
	public function test_the_table_is_created_on_activation_and_checked_on_boot(): void {
		otherguise_test_reset();

		$wpdb   = new Otherguise_Test_Wpdb( 'wp_' );
		$module = new Module( static function () {}, $wpdb );

		$module->activate();
		$module->boot();

		$this->assertCount( 1, $GLOBALS['otherguise_test_dbdelta'], 'The second call finds the schema up to date.' );
		$this->assertSame( 1, get_option( 'triples_schema_version' ) );
	}

	/**
	 * The data is removed on uninstall only when the administrator asked.
	 *
	 * @return void
	 */
	public function test_the_uninstall_removes_the_table_only_when_asked(): void {
		otherguise_test_reset();

		$wpdb   = new Otherguise_Test_Wpdb( 'wp_' );
		$module = new Module( static function () {}, $wpdb );

		$module->uninstall();
		$this->assertSame( array(), $wpdb->queries );

		update_option( 'triples_settings', array( 'delete_data_on_uninstall' => true ) );
		$module->uninstall();

		$this->assertSame( array( 'DROP TABLE IF EXISTS `wp_triples_statements`' ), $wpdb->queries );
	}

	/**
	 * The module hands out a store bound to its datatypes.
	 *
	 * @return void
	 */
	public function test_the_module_provides_the_statement_store(): void {
		$module = new Module( static function () {}, new Otherguise_Test_Wpdb( 'wp_' ) );

		$this->assertInstanceOf( \Otherguise\Triples\Statements::class, $module->statements() );
		$this->assertInstanceOf( \Otherguise\Triples\Storage\StatementStore::class, $module->store() );
	}
}
