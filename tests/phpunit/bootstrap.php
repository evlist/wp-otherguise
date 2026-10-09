<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * PHPUnit bootstrap and WordPress test stubs.
 *
 * @package Otherguise
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../../' );
}

if ( ! function_exists( 'esc_html' ) ) {
    /**
     * Stub of esc_html().
     *
     * @param string $text Text to escape.
     * @return string
     */
    function esc_html( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Core/Autoloader.php';

\Otherguise\Core\Autoloader::register( dirname( __DIR__, 2 ) . '/plugin/' );
