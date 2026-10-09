<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Public functions of the Modes module.
 *
 * @package Otherguise
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'modes_active_mode' ) ) {
	/**
	 * Returns the mode of the current request: the mode named by `?mode=` or by the alias of a mode (`?print`), the default mode otherwise.
	 *
	 * @return \Otherguise\Modes\Mode\ModeDefinition|null Null when the module is not enabled yet; call it after `plugins_loaded`.
	 */
	function modes_active_mode() {
		$module = \Otherguise\Core\Modules::get( 'modes' );

		return $module instanceof \Otherguise\Modes\Module ? $module->active()->mode() : null;
	}
}

if ( ! function_exists( 'modes_is_active' ) ) {
	/**
	 * Tells whether a mode is the mode of the current request.
	 *
	 * @param string $slug Slug of a mode.
	 * @return bool
	 */
	function modes_is_active( $slug ) {
		$mode = modes_active_mode();

		return null !== $mode && $mode->slug() === $slug;
	}
}
