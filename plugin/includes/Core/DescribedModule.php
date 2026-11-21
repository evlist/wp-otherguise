<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A module that says what it is, for the switches of the overview.
 *
 * @package Otherguise
 */

namespace Otherguise\Core;

defined( 'ABSPATH' ) || exit;

/**
 * What the overview of the menu Otherguise shows next to the switch of a module.
 */
interface DescribedModule {
	/**
	 * The name of the module, translated.
	 *
	 * @return string
	 */
	public function title();

	/**
	 * One sentence that says what the module does, translated.
	 *
	 * @return string
	 */
	public function description();
}
