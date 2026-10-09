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

/**
 * Records the calls made on a module.
 */
class Otherguise_Test_Module implements ModuleInterface {
    /**
     * Shared log of "action:id" entries.
     *
     * @var string[]
     */
    private $log;

    /**
     * Module identifier.
     *
     * @var string
     */
    private $id;

    /**
     * Dependencies.
     *
     * @var string[]
     */
    private $dependencies;

    /**
     * Builds the module.
     *
     * @param string   $id           Identifier.
     * @param string[] $dependencies Dependencies.
     * @param string[] $log          Shared log, passed by reference.
     */
    public function __construct( $id, array $dependencies, array &$log ) {
        $this->id           = $id;
        $this->dependencies = $dependencies;
        $this->log          = &$log;
    }

    /**
     * Identifier.
     *
     * @return string
     */
    public function id() {
        return $this->id;
    }

    /**
     * Dependencies.
     *
     * @return string[]
     */
    public function dependencies() {
        return $this->dependencies;
    }

    /**
     * Records the boot.
     *
     * @return void
     */
    public function boot() {
        $this->log[] = 'boot:' . $this->id;
    }

    /**
     * Records the uninstall.
     *
     * @return void
     */
    public function uninstall() {
        $this->log[] = 'uninstall:' . $this->id;
    }
}

/**
 * @covers \Otherguise\Core\ModuleLoader
 */
class ModuleLoaderTest extends TestCase {

    /**
     * Shared log of calls.
     *
     * @var string[]
     */
    private $log = array();

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

    public function test_dependencies_come_first_whatever_the_registration_order(): void {
        $loader = new ModuleLoader(
            array( $this->module( 'books', array( 'triples', 'modes' ) ), $this->module( 'modes', array( 'triples' ) ), $this->module( 'triples' ) )
        );

        $this->assertSame( array( 'triples', 'modes', 'books' ), $loader->order() );
    }

    public function test_boot_runs_dependencies_first_and_uninstall_dependents_first(): void {
        $loader = new ModuleLoader( array( $this->module( 'modes', array( 'triples' ) ), $this->module( 'triples' ) ) );

        $loader->boot();
        $loader->uninstall();

        $this->assertSame( array( 'boot:triples', 'boot:modes', 'uninstall:modes', 'uninstall:triples' ), $this->log );
    }

    public function test_only_the_enabled_modules_are_loaded(): void {
        $loader = new ModuleLoader(
            array( $this->module( 'modes', array( 'triples' ) ), $this->module( 'triples' ), $this->module( 'books', array( 'triples' ) ) ),
            array( 'triples', 'books' )
        );

        $loader->boot();

        $this->assertSame( array( 'boot:triples', 'boot:books' ), $this->log );
    }

    public function test_an_enabled_module_needs_its_dependencies_to_be_enabled(): void {
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Module "modes" needs module "triples", which is not available.' );

        new ModuleLoader( array( $this->module( 'modes', array( 'triples' ) ), $this->module( 'triples' ) ), array( 'modes' ) );
    }

    public function test_an_unknown_dependency_is_rejected(): void {
        $this->expectException( InvalidArgumentException::class );

        new ModuleLoader( array( $this->module( 'modes', array( 'ghost' ) ) ) );
    }

    public function test_an_unknown_enabled_identifier_is_rejected(): void {
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Unknown module "ghost".' );

        new ModuleLoader( array( $this->module( 'triples' ) ), array( 'ghost' ) );
    }

    public function test_a_duplicate_identifier_is_rejected(): void {
        $this->expectException( InvalidArgumentException::class );

        new ModuleLoader( array( $this->module( 'triples' ), $this->module( 'triples' ) ) );
    }

    public function test_a_cycle_is_rejected(): void {
        $this->expectException( LogicException::class );

        new ModuleLoader( array( $this->module( 'a', array( 'b' ) ), $this->module( 'b', array( 'a' ) ) ) );
    }

    public function test_the_real_modules_declare_the_expected_dependencies(): void {
        $modules = require dirname( __DIR__, 2 ) . '/plugin/includes/modules.php';
        $order   = ( new ModuleLoader( array_reverse( $modules ) ) )->order();

        $this->assertSame( array( 'triples', 'modes', 'books' ), $order );
    }
}
