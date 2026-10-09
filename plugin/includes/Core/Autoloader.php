<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * PSR-4 style autoloader for the core and the modules.
 *
 * @package Otherguise
 */

namespace Otherguise\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Maps class names to files without Composer.
 *
 * - `Otherguise\Core\Foo\Bar` is `includes/Core/Foo/Bar.php`;
 * - `Otherguise\<Module>\Foo\Bar` is `modules/<module>/src/Foo/Bar.php`, for every module.
 *
 * A module can be lifted out of the plugin together with its directory.
 */
final class Autoloader {
	/**
	 * Registers the autoloader.
	 *
	 * @param string $plugin_dir Plugin directory, with a trailing slash.
	 * @return void
	 */
	public static function register( $plugin_dir ) {
		spl_autoload_register(
			static function ( $class_name ) use ( $plugin_dir ) {
				$file = self::resolve( $class_name, $plugin_dir );

				if ( null !== $file && is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	/**
	 * Returns the file that should declare a class, or null when the class is not ours.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @param string $plugin_dir Plugin directory, with a trailing slash.
	 * @return string|null
	 */
	public static function resolve( $class_name, $plugin_dir ) {
		if ( 1 !== preg_match( '/^Otherguise\\\\([A-Za-z0-9]+)((?:\\\\[A-Za-z0-9_]+)+)$/', $class_name, $matches ) ) {
			return null;
		}

		$relative = str_replace( '\\', '/', $matches[2] );

		if ( 'Core' === $matches[1] ) {
			return $plugin_dir . 'includes/Core' . $relative . '.php';
		}

		return $plugin_dir . 'modules/' . strtolower( $matches[1] ) . '/src' . $relative . '.php';
	}
}
