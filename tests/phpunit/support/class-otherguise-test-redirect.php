<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Exception raised by the test environment instead of redirecting.
 *
 * @package Otherguise
 */

/**
 * A redirection, raised instead of ending the request.
 */
class Otherguise_Test_Redirect extends RuntimeException {
	/**
	 * Target of the redirection.
	 *
	 * @var string
	 */
	public $url = '';
}
