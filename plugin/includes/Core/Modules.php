<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Access to the modules that were booted.
 *
 * @package Otherguise
 */

namespace Otherguise\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the loader of the request so that a module, or the public functions of a module, can reach another module by its identifier.
 *
 * It knows no module by name: a caller asks for an identifier and checks the class of what it gets. A module that is not enabled gives
 * null. The loader is registered before the modules boot, so a module can ask for the modules it depends on in its own `boot()`.
 */
final class Modules {
	/**
	 * Loader of the request, or null before the plugin has booted.
	 *
	 * @var ModuleLoader|null
	 */
	private static $loader = null;

	/**
	 * Registers the loader.
	 *
	 * @param ModuleLoader|null $loader Loader, or null to forget it.
	 * @return void
	 */
	public static function set( $loader ) {
		self::$loader = $loader;
	}

	/**
	 * Returns an enabled module.
	 *
	 * @param string $id Module identifier.
	 * @return ModuleInterface|null
	 */
	public static function get( $id ) {
		return null === self::$loader ? null : self::$loader->module( $id );
	}
}
