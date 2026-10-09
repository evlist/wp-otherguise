<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the module loader.
 *
 * @package Otherguise
 */

use Otherguise\Core\ModuleInterface;
use Otherguise\Core\ModuleLoader;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-module.php';

/**
 * Tests of ModuleLoader.
 *
 * @covers \Otherguise\Core\ModuleLoader
 */
class ModuleLoaderTest extends TestCase {

	/**
	 * Shared log of calls.
	 *
	 * @var string[]
	 */
	private $log = array();

	/**
	 * Prepares the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->log = array();
	}

	/**
	 * Builds a test module.
	 *
	 * @param string   $id           Identifier.
	 * @param string[] $dependencies Dependencies.
	 * @return Otherguise_Test_Module
	 */
	private function module( $id, array $dependencies = array() ) {
		return new Otherguise_Test_Module( $id, $dependencies, $this->log );
	}

	/**
	 * Dependencies come first whatever the registration order.
	 *
	 * @return void
	 */
	public function test_dependencies_come_first_whatever_the_registration_order(): void {
		$loader = new ModuleLoader(
			array( $this->module( 'books', array( 'triples', 'modes' ) ), $this->module( 'modes', array( 'triples' ) ), $this->module( 'triples' ) )
		);

		$this->assertSame( array( 'triples', 'modes', 'books' ), $loader->order() );
	}

	/**
	 * Boot runs dependencies first and uninstall dependents first.
	 *
	 * @return void
	 */
	public function test_boot_runs_dependencies_first_and_uninstall_dependents_first(): void {
		$loader = new ModuleLoader( array( $this->module( 'modes', array( 'triples' ) ), $this->module( 'triples' ) ) );

		$loader->boot();
		$loader->uninstall();

		$this->assertSame( array( 'boot:triples', 'boot:modes', 'uninstall:modes', 'uninstall:triples' ), $this->log );
	}

	/**
	 * The modules are activated dependencies first.
	 *
	 * @return void
	 */
	public function test_the_modules_are_activated_dependencies_first(): void {
		$loader = new ModuleLoader( array( $this->module( 'modes', array( 'triples' ) ), $this->module( 'triples' ) ) );

		$loader->activate();

		$this->assertSame( array( 'activate:triples', 'activate:modes' ), $this->log );
	}

	/**
	 * Only the enabled modules are loaded.
	 *
	 * @return void
	 */
	public function test_only_the_enabled_modules_are_loaded(): void {
		$loader = new ModuleLoader(
			array( $this->module( 'modes', array( 'triples' ) ), $this->module( 'triples' ), $this->module( 'books', array( 'triples' ) ) ),
			array( 'triples', 'books' )
		);

		$loader->boot();

		$this->assertSame( array( 'boot:triples', 'boot:books' ), $this->log );
	}

	/**
	 * An enabled module needs its dependencies to be enabled.
	 *
	 * @return void
	 */
	public function test_an_enabled_module_needs_its_dependencies_to_be_enabled(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Module "modes" needs module "triples", which is not available.' );

		new ModuleLoader( array( $this->module( 'modes', array( 'triples' ) ), $this->module( 'triples' ) ), array( 'modes' ) );
	}

	/**
	 * An unknown dependency is rejected.
	 *
	 * @return void
	 */
	public function test_an_unknown_dependency_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		new ModuleLoader( array( $this->module( 'modes', array( 'ghost' ) ) ) );
	}

	/**
	 * An unknown enabled identifier is rejected.
	 *
	 * @return void
	 */
	public function test_an_unknown_enabled_identifier_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown module "ghost".' );

		new ModuleLoader( array( $this->module( 'triples' ) ), array( 'ghost' ) );
	}

	/**
	 * A duplicate identifier is rejected.
	 *
	 * @return void
	 */
	public function test_a_duplicate_identifier_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		new ModuleLoader( array( $this->module( 'triples' ), $this->module( 'triples' ) ) );
	}

	/**
	 * A cycle is rejected.
	 *
	 * @return void
	 */
	public function test_a_cycle_is_rejected(): void {
		$this->expectException( LogicException::class );

		new ModuleLoader( array( $this->module( 'a', array( 'b' ) ), $this->module( 'b', array( 'a' ) ) ) );
	}

	/**
	 * The real modules declare the expected dependencies.
	 *
	 * @return void
	 */
	public function test_the_real_modules_declare_the_expected_dependencies(): void {
		$modules = require dirname( __DIR__, 2 ) . '/plugin/includes/modules.php';
		$order   = ( new ModuleLoader( array_reverse( $modules ) ) )->order();

		$this->assertSame( array( 'triples', 'modes', 'books' ), $order );
	}
}
