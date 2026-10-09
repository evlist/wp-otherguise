<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Composition root: the only place that knows every module.
 *
 * The core never references a module; modules are handed to the loader from here.
 *
 * @package Otherguise
 */

defined( 'ABSPATH' ) || exit;

return array(
    new \Otherguise\Triples\Module(),
    new \Otherguise\Modes\Module(),
    new \Otherguise\Books\Module(),
);
