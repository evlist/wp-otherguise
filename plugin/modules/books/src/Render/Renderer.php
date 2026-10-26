<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What a PDF rendering engine offers.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Render;

defined( 'ABSPATH' ) || exit;

/**
 * A rendering engine (slice 220): converts the address of a page of the site to PDF. Gotenberg is the first one; another engine would
 * implement the same interface.
 */
interface Renderer {
	/**
	 * Converts a page to PDF.
	 *
	 * @param string               $url     Address of the page: only an address of the site, given by WordPress.
	 * @param array<string, float> $format  Format of the page: `width_mm`, `height_mm`, `margin_mm`.
	 * @param array<string, mixed> $options Options: `wait_delay` (seconds), `wait_expression`, `wait_selector`, `media`, `timeout` (seconds).
	 * @return string|\WP_Error The bytes of the PDF, or an error.
	 */
	public function convert_url( $url, array $format, array $options = array() );

	/**
	 * Converts a page to PDF and writes the PDF in a file, without keeping it in memory (a long page gives a PDF of hundreds of megabytes).
	 *
	 * @param string               $url     Address of the page: only an address of the site, given by WordPress.
	 * @param array<string, float> $format  Format of the page.
	 * @param array<string, mixed> $options Options, as for `convert_url()`.
	 * @param string               $path    Path of the file to write.
	 * @return true|\WP_Error True when the file holds a PDF; an error otherwise (the file is deleted).
	 */
	public function convert_url_to_file( $url, array $format, array $options, $path );

	/**
	 * Gives the number of pages of a PDF, when the engine can tell.
	 *
	 * @param string $pdf Bytes of the PDF.
	 * @return int|null
	 */
	public function page_count( $pdf );

	/**
	 * Checks that the server answers.
	 *
	 * @return array{up:bool, message:string}
	 */
	public function health();
}
