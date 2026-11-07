<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Marks a module that is off until a site switches it on.
 *
 * @package Otherguise
 */

namespace Otherguise\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A module that implements this interface is **disabled by default**: a site enables it with the switch of the overview of the menu
 * Otherguise (or with the filter `otherguise_enabled_modules`). It is for a module that changes what WordPress does for content the site
 * already has, such as the wiki, which gives the pages addresses made of their titles.
 */
interface DisabledByDefault {
}
