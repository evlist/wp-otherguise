<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the access to the booted modules.
 *
 * @package Otherguise
 */

use Otherguise\Core\ModuleLoader;
use Otherguise\Core\Modules;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-module.php';

/**
 * Tests of Modules and of ModuleLoader::module().
 *
 * @covers \Otherguise\Core\Modules
 * @covers \Otherguise\Core\ModuleLoader
 */
class ModulesRegistryTest extends TestCase {

	/**
	 * Forgets the loader.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Modules::set( null );
	}

	/**
	 * Before the plugin has booted nothing is available.
	 *
	 * @return void
	 */
	public function test_nothing_is_available_before_the_loader_is_registered(): void {
		Modules::set( null );

		$this->assertNull( Modules::get( 'triples' ) );
	}

	/**
	 * An enabled module is found by its identifier; a module that is not enabled, or unknown, gives null.
	 *
	 * @return void
	 */
	public function test_an_enabled_module_is_found_by_its_identifier(): void {
		$log     = array();
		$modules = array(
			new Otherguise_Test_Module( 'a', array(), $log ),
			new Otherguise_Test_Module( 'b', array( 'a' ), $log ),
			new Otherguise_Test_Module( 'c', array(), $log ),
		);

		Modules::set( new ModuleLoader( $modules, array( 'a', 'b' ) ) );

		$this->assertSame( $modules[1], Modules::get( 'b' ) );
		$this->assertSame( $modules[0], Modules::get( 'a' ) );
		$this->assertNull( Modules::get( 'c' ), 'Not enabled.' );
		$this->assertNull( Modules::get( 'nope' ) );
	}
}
