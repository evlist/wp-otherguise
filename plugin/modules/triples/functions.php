<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Public functions of the Triples module.
 *
 * @package Otherguise
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'triples_statements' ) ) {
	/**
	 * Returns the service that creates and reads statements: the way for another plugin to use the module.
	 *
	 * Call it from `plugins_loaded` (priority 10 or later), or later: the module boots on `plugins_loaded`. To register entity types,
	 * datatypes and predicates, use the actions `triples_register_entity_types`, `triples_register_datatypes` and
	 * `triples_register_predicates` instead.
	 *
	 * @return \Otherguise\Triples\Statements|null Null when the plugin or the module is not enabled yet.
	 */
	function triples_statements() {
		$module = \Otherguise\Core\Modules::get( 'triples' );

		return $module instanceof \Otherguise\Triples\Module ? $module->statements() : null;
	}
}
