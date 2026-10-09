<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Books assembled from posts: composition, table of contents, index and PDF output.
 *
 * @package Otherguise
 */

namespace Otherguise\Books;

use Otherguise\Core\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the books module.
 */
final class Module implements ModuleInterface {
    /**
     * Returns the identifier of the module.
     *
     * @return string
     */
    public function id() {
        return 'books';
    }

    /**
     * Returns the identifiers of the modules this module needs.
     *
     * @return string[]
     */
    public function dependencies() {
        return array( 'triples', 'modes' );
    }

    /**
     * Registers the hooks of the module.
     *
     * @return void
     */
    public function boot() {
        // Nothing to register yet.
    }

    /**
     * Removes the data owned by the module.
     *
     * @return void
     */
    public function uninstall() {
        // Nothing to remove yet.
    }
}
