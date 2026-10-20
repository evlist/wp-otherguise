<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * No srcset in the print mode.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Images;

defined( 'ABSPATH' ) || exit;

/**
 * In the print mode the images have no `srcset` (slice 221): WordPress puts in it every size with the same ratio as the one asked for,
 * and Chromium at a pixel ratio of 1 takes the smallest candidate that covers the slot, which defeats the size of 300 ppi that the theme
 * asked for by its name. The print modes are those of the filter `books_preview_frame_modes` (`print` by default).
 */
final class PrintSrcset {
	/**
	 * Gives the slug of the active mode.
	 *
	 * @var callable
	 */
	private $active_mode;

	/**
	 * Gives the slugs of the print modes.
	 *
	 * @var callable
	 */
	private $print_modes;

	/**
	 * Builds the filter.
	 *
	 * @param callable|null $active_mode Defaults to the filter `modes_active_mode` of the Modes module, `web` when it is not there.
	 * @param callable|null $print_modes Defaults to the filter `books_preview_frame_modes`, `print` by default.
	 */
	public function __construct( $active_mode = null, $print_modes = null ) {
		$this->active_mode = $active_mode ?? static function () {
			return apply_filters( 'modes_active_mode', 'web' );
		};
		$this->print_modes = $print_modes ?? static function () {
			return apply_filters( 'books_preview_frame_modes', array( 'print' ) );
		};
	}

	/**
	 * Removes the `srcset` in a print mode. Filter `wp_calculate_image_srcset`.
	 *
	 * @param mixed $sources Sources of the `srcset`, or false.
	 * @return mixed
	 */
	public function filter( $sources ) {
		$mode  = ( $this->active_mode )();
		$modes = ( $this->print_modes )();

		return is_string( $mode ) && is_array( $modes ) && in_array( $mode, $modes, true ) ? false : $sources;
	}
}
