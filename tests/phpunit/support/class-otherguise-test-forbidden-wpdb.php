<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A database object that fails on any use.
 *
 * @package Otherguise
 */

/**
 * A database object that fails on any use: the tests that must stop before the store build the module on it.
 */
class Otherguise_Test_Forbidden_Wpdb {
	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'forbidden_';

	/**
	 * Fails.
	 *
	 * @param string $name      Method.
	 * @param array  $arguments Arguments.
	 * @return void
	 * @throws LogicException Always.
	 */
	public function __call( $name, $arguments ) {
		throw new LogicException( 'The store was reached through ' . esc_html( $name ) . '().' );
	}
}
