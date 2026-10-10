<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Settings of the Modes module.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The option `modes_settings`. One setting for now: whether the modes are enabled on the site.
 *
 * When they are not, the site behaves as if the module did not change anything: `?mode=` and aliases such as `?print` are ignored, the
 * request is in the default mode, no variant is applied to the templates or the template parts, and the body gets no mode class. The
 * data (the modes, the variants) and the administration screen stay, so that the modes can be set up and enabled again. They are
 * **disabled by default**: they do nothing until variants or stylesheets are declared, so an administrator enables them on purpose.
 */
final class Settings {
	/**
	 * Name of the option.
	 */
	public const OPTION = 'modes_settings';

	/**
	 * Key of the setting that enables the modes.
	 */
	public const ENABLED = 'enabled';

	/**
	 * Tells whether the modes are enabled on this site.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = get_option( self::OPTION, array() );

		return is_array( $settings ) && ! empty( $settings[ self::ENABLED ] );
	}

	/**
	 * Sanitizes the option when it is saved: the setting becomes a boolean, other keys already stored are kept, unknown keys of the form
	 * are dropped.
	 *
	 * @param mixed $input Value posted.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$input  = is_array( $input ) ? $input : array();

		$stored[ self::ENABLED ] = ! empty( $input[ self::ENABLED ] );

		return $stored;
	}
}
