<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Contract of a module.
 *
 * @package Otherguise
 */

namespace Otherguise\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A module is a unit of the plugin that owns its data, its names and its screens.
 */
interface ModuleInterface {
	/**
	 * Returns the identifier of the module, in lower case (for example `triples`).
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Returns the identifiers of the modules this module needs.
	 *
	 * @return string[]
	 */
	public function dependencies();

	/**
	 * Creates what the module needs when the plugin is activated (tables, options). Called dependencies first.
	 *
	 * @return void
	 */
	public function activate();

	/**
	 * Registers the hooks of the module. Called once per request, dependencies first.
	 *
	 * @return void
	 */
	public function boot();

	/**
	 * Removes the data owned by the module. Called when the plugin is deleted, dependents first.
	 *
	 * @return void
	 */
	public function uninstall();
}
