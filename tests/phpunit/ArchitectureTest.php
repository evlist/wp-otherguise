<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Architecture tests: the dependency rules between the core and the modules.
 *
 * @package Otherguise
 */

use Otherguise\Core\ModuleInterface;
use PHPUnit\Framework\TestCase;

/**
 * A module may only use the core, itself and the modules it declares (directly or not).
 * The core never uses a module. The composition root is `plugin/includes/modules.php`.
 *
 * @coversNothing
 */
class ArchitectureTest extends TestCase {

    /**
     * Plugin directory.
     *
     * @var string
     */
    private $plugin_dir;

    protected function setUp(): void {
        $this->plugin_dir = dirname( __DIR__, 2 ) . '/plugin';
    }

    /**
     * Returns the modules declared in the composition root, by identifier.
     *
     * @return array<string, ModuleInterface>
     */
    private function declared_modules() {
        $by_id = array();

        foreach ( require $this->plugin_dir . '/includes/modules.php' as $module ) {
            $by_id[ $module->id() ] = $module;
        }

        return $by_id;
    }

    /**
     * Returns the PHP files below a directory.
     *
     * @param string $directory Directory.
     * @return string[]
     */
    private function php_files( $directory ) {
        $files    = array();
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );

        foreach ( $iterator as $file ) {
            if ( 'php' === $file->getExtension() ) {
                $files[] = $file->getPathname();
            }
        }

        sort( $files );

        return $files;
    }

    /**
     * Returns the module namespaces mentioned in a file, other than Core.
     *
     * @param string $file File path.
     * @return string[] Lower-case module identifiers.
     */
    private function referenced_modules( $file ) {
        $source = file_get_contents( $file );
        preg_match_all( '/Otherguise\\\\([A-Za-z0-9]+)\\\\/', $source, $matches );

        return array_values( array_diff( array_unique( array_map( 'strtolower', $matches[1] ) ), array( 'core' ) ) );
    }

    /**
     * Returns the identifiers a module may use: itself and everything it depends on, directly or not.
     *
     * @param string                         $id      Module identifier.
     * @param array<string, ModuleInterface> $modules Declared modules.
     * @return string[]
     */
    private function allowed( $id, array $modules ) {
        $allowed = array( $id );

        foreach ( $modules[ $id ]->dependencies() as $dependency ) {
            $allowed = array_merge( $allowed, $this->allowed( $dependency, $modules ) );
        }

        return array_unique( $allowed );
    }

    public function test_every_module_directory_is_declared_in_the_composition_root(): void {
        $directories = array_map( 'basename', glob( $this->plugin_dir . '/modules/*', GLOB_ONLYDIR ) );
        $declared    = array_keys( $this->declared_modules() );

        sort( $directories );
        sort( $declared );

        $this->assertSame( $directories, $declared );
    }

    public function test_every_module_class_lives_in_its_own_namespace_and_directory(): void {
        foreach ( $this->declared_modules() as $id => $module ) {
            $this->assertSame( 'Otherguise\\' . ucfirst( $id ) . '\Module', get_class( $module ) );
            $this->assertFileExists( $this->plugin_dir . '/modules/' . $id . '/src/Module.php' );
        }
    }

    public function test_modules_only_use_themselves_the_core_and_their_dependencies(): void {
        $modules  = $this->declared_modules();
        $problems = array();

        foreach ( array_keys( $modules ) as $id ) {
            $allowed = $this->allowed( $id, $modules );

            foreach ( $this->php_files( $this->plugin_dir . '/modules/' . $id ) as $file ) {
                foreach ( array_diff( $this->referenced_modules( $file ), $allowed ) as $forbidden ) {
                    $problems[] = str_replace( $this->plugin_dir . '/', '', $file ) . ' uses module "' . $forbidden . '"';
                }
            }
        }

        $this->assertSame( array(), $problems );
    }

    public function test_the_core_never_uses_a_module(): void {
        $problems = array();

        foreach ( $this->php_files( $this->plugin_dir . '/includes/Core' ) as $file ) {
            foreach ( $this->referenced_modules( $file ) as $module ) {
                $problems[] = str_replace( $this->plugin_dir . '/', '', $file ) . ' uses module "' . $module . '"';
            }
        }

        $this->assertSame( array(), $problems );
    }

    public function test_the_dependency_graph_has_no_cycle_and_only_known_modules(): void {
        $modules = $this->declared_modules();

        foreach ( $modules as $id => $module ) {
            foreach ( $module->dependencies() as $dependency ) {
                $this->assertArrayHasKey( $dependency, $modules, 'Module "' . $id . '" needs an unknown module.' );
                $this->assertNotContains( $id, $this->allowed( $dependency, $modules ), 'Circular dependency through "' . $id . '".' );
            }
        }
    }
}
