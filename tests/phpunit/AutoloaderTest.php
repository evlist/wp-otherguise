<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the autoloader.
 *
 * @package Otherguise
 */

use Otherguise\Core\Autoloader;
use PHPUnit\Framework\TestCase;

/**
 * Tests of Autoloader.
 *
 * @covers \Otherguise\Core\Autoloader
 */
class AutoloaderTest extends TestCase {

	/**
	 * Core classes live in includes core.
	 *
	 * @return void
	 */
	public function test_core_classes_live_in_includes_core(): void {
		$this->assertSame( '/p/includes/Core/ModuleLoader.php', Autoloader::resolve( 'Otherguise\Core\ModuleLoader', '/p/' ) );
		$this->assertSame( '/p/includes/Core/Sub/Thing.php', Autoloader::resolve( 'Otherguise\Core\Sub\Thing', '/p/' ) );
	}

	/**
	 * Module classes live in the src directory of their module.
	 *
	 * @return void
	 */
	public function test_module_classes_live_in_the_src_directory_of_their_module(): void {
		$this->assertSame( '/p/modules/triples/src/Module.php', Autoloader::resolve( 'Otherguise\Triples\Module', '/p/' ) );
		$this->assertSame( '/p/modules/books/src/Storage/Table.php', Autoloader::resolve( 'Otherguise\Books\Storage\Table', '/p/' ) );
	}

	/**
	 * Foreign or malformed names are ignored.
	 *
	 * @return void
	 */
	public function test_foreign_or_malformed_names_are_ignored(): void {
		$this->assertNull( Autoloader::resolve( 'Other\Core\Thing', '/p/' ) );
		$this->assertNull( Autoloader::resolve( 'Otherguise\Core', '/p/' ) );
		$this->assertNull( Autoloader::resolve( 'Otherguise\Core\..\..\evil', '/p/' ) );
		$this->assertNull( Autoloader::resolve( 'Otherguise', '/p/' ) );
	}

	/**
	 * The registered autoloader loads a real class.
	 *
	 * @return void
	 */
	public function test_the_registered_autoloader_loads_a_real_class(): void {
		$this->assertTrue( class_exists( 'Otherguise\Triples\Module' ) );
	}
}
