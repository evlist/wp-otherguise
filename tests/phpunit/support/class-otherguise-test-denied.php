<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Exception raised by the test environment instead of refusing.
 *
 * @package Otherguise
 */

/**
 * A refusal, raised instead of ending the request.
 */
class Otherguise_Test_Denied extends RuntimeException {
}
