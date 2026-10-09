<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Every module removes the data it owns; the modules are processed dependents first.
 *
 * @package Otherguise
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/Core/Autoloader.php';

\Otherguise\Core\Autoloader::register( __DIR__ . '/' );

$otherguise_modules = require __DIR__ . '/includes/modules.php';

( new \Otherguise\Core\ModuleLoader( $otherguise_modules ) )->uninstall();
