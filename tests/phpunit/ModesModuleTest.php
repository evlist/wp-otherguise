<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the Modes module and of the public functions of the Triples and Modes modules.
 *
 * @package Otherguise
 */

use Otherguise\Core\ModuleLoader;
use Otherguise\Core\Modules;
use Otherguise\Modes\Mode\ModeDefinition;
use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Modes\Module;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-wpdb.php';

/**
 * No database and no WordPress: the actions and the query string are replaced by closures.
 *
 * @covers \Otherguise\Modes\Module
 * @covers \Otherguise\Modes\Integration\TriplesIntegration
 */
class ModesModuleTest extends TestCase {

	/**
	 * Forgets the loader.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Modules::set( null );
	}

	/**
	 * Builds a module that records its hooks and the actions it fires.
	 *
	 * @param array $query   Query string the module reads.
	 * @param array $hooks   Receives the hooks added, by name.
	 * @param array $actions Receives the names of the actions fired.
	 * @param array $filters Values returned by filters, by hook.
	 * @return Module
	 */
	private function module( array $query = array(), array &$hooks = array(), array &$actions = array(), array $filters = array() ) {
		return new Module(
			static function ( $hook, $registry ) use ( &$actions ) {
				$actions[] = $hook;

				if ( $registry instanceof ModeRegistry && in_array( 'declare', $actions, true ) ) {
					$registry->register( new ModeDefinition( 'cover', 'Cover' ) );
				}
			},
			static function ( $hook, $callback, $priority = 10, $accepted = 1 ) use ( &$hooks ) {
				$hooks[ $hook ][] = array( get_class( $callback[0] ), $callback[1], $priority, $accepted );
			},
			static fn() => $query,
			static fn( $hook, $value ) => $filters[ $hook ] ?? $value
		);
	}

	/**
	 * The module needs Triples, stores nothing, and has an identifier.
	 *
	 * @return void
	 */
	public function test_identity(): void {
		$module = $this->module();

		$this->assertSame( 'modes', $module->id() );
		$this->assertSame( array( 'triples' ), $module->dependencies() );

		$module->activate();
		$module->uninstall();
	}

	/**
	 * Booting adds the registrations in Triples, the class of the body and the translations, at explicit priorities.
	 *
	 * @return void
	 */
	public function test_boot_hooks(): void {
		$hooks = array();

		$this->module( array(), $hooks )->boot();

		$this->assertSame(
			array(
				'triples_register_entity_types' => array(
					array( 'Otherguise\\Modes\\Integration\\TriplesIntegration', 'register_entity_type', 10, 1 ),
					array( 'Otherguise\\Modes\\Integration\\TemplateIntegration', 'register_entity_types', 10, 1 ),
				),
				'triples_register_predicates'   => array(
					array( 'Otherguise\\Modes\\Integration\\TriplesIntegration', 'register_predicate', 10, 1 ),
					array( 'Otherguise\\Modes\\Integration\\TemplateIntegration', 'register_predicates', 10, 1 ),
				),
				'body_class'                    => array( array( Module::class, 'body_class', 10, 1 ) ),
				'init'                          => array(
					array( Module::class, 'load_textdomain', 10, 1 ),
					array( Module::class, 'register_variant_filters', 20, 0 ),
				),
			),
			$hooks
		);
	}

	/**
	 * The built-in modes, and the action that lets others declare theirs.
	 *
	 * @return void
	 */
	public function test_modes_and_the_registration_action(): void {
		$hooks   = array();
		$actions = array();
		$module  = $this->module( array(), $hooks, $actions );

		$this->assertSame( array( 'web', 'print' ), array_keys( $module->modes()->all() ) );
		$this->assertSame( array( 'modes_register_modes' ), $actions );
		$this->assertSame( 'print', $module->modes()->get( 'print' )->alias() );
		$this->assertNull( $module->modes()->get( 'web' )->alias() );
	}

	/**
	 * A mode declared through the action is known, and can be selected.
	 *
	 * @return void
	 */
	public function test_a_mode_declared_by_a_plugin(): void {
		$hooks   = array();
		$actions = array( 'declare' );
		$module  = $this->module( array( 'mode' => 'cover' ), $hooks, $actions );

		$this->assertSame( 'cover', $module->active()->mode()->slug() );
	}

	/**
	 * The filter changes the default mode.
	 *
	 * @return void
	 */
	public function test_the_default_mode_is_filterable(): void {
		$this->assertSame( 'web', $this->module()->active()->mode()->slug() );
		$hooks   = array();
		$actions = array();

		$this->assertSame( 'print', $this->module( array(), $hooks, $actions, array( 'modes_default_mode' => 'print' ) )->active()->mode()->slug() );
	}

	/**
	 * The body gets the class of the mode, whatever the other classes are.
	 *
	 * @return void
	 */
	public function test_body_class(): void {
		$this->assertSame( array( 'home', 'modes-mode-web' ), $this->module()->body_class( array( 'home' ) ) );
		$this->assertSame( array( 'modes-mode-print' ), $this->module( array( 'print' => '' ) )->body_class( array() ) );
		$this->assertSame( array( 'modes-mode-print' ), $this->module( array( 'mode' => 'print' ) )->body_class( 'not an array' ) );
	}

	/**
	 * Outside WordPress the request has no query string to read.
	 *
	 * @return void
	 */
	public function test_reading_the_query_string_outside_a_request(): void {
		$this->assertSame( array(), ( new Module( static function () {} ) )->read_query() );
	}

	/**
	 * The public functions reach the booted modules through the registry of modules.
	 *
	 * @return void
	 */
	public function test_public_functions(): void {
		$triples = new \Otherguise\Triples\Module( static function () {}, new Otherguise_Test_Wpdb( 'wp_' ) );
		$modes   = $this->module( array( 'print' => '' ) );

		$triples->boot();
		$modes->boot();

		$this->assertNull( triples_statements() );
		$this->assertNull( modes_active_mode() );
		$this->assertFalse( modes_is_active( 'print' ) );

		Modules::set( new ModuleLoader( array( $triples, $modes ) ) );

		$this->assertInstanceOf( \Otherguise\Triples\Statements::class, triples_statements() );
		$this->assertSame( triples_statements(), triples_statements(), 'The same service on every call.' );
		$this->assertSame( 'print', modes_active_mode()->slug() );
		$this->assertTrue( modes_is_active( 'print' ) );
		$this->assertFalse( modes_is_active( 'web' ) );

		Modules::set( new ModuleLoader( array( $triples, $modes ), array( 'triples' ) ) );

		$this->assertNull( modes_active_mode(), 'A module that is not enabled gives nothing.' );
	}
}
