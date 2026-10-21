<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A larger pixel ratio for the charts of a print mode.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Images;

defined( 'ABSPATH' ) || exit;

/**
 * In a print mode the page tells its scripts that the screen has a larger pixel ratio (slice 222): Chart.js reads `window.devicePixelRatio`
 * when it builds a chart and draws the canvas of the altitude profile with that many pixels per CSS pixel (582 x 450 instead of
 * 194 x 150 at a ratio of 3), which Chromium puts in the PDF as an image. The size on the page does not change. The tiles of the map
 * are not concerned (they need `detectRetina`, which was rejected, see `docs/design/books.md`).
 *
 * The script is printed in `wp_head` at priority 0, before the scripts that read the ratio. The ratio is a number from 1 to 4 given by the
 * filter `books_print_pixel_ratio` (3 by default); 1, or anything else than a number in that range, prints nothing.
 */
final class PrintPixelRatio {
	const DEFAULT_RATIO = 3;
	const MAX_RATIO     = 4;

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
	 * Gives the ratio.
	 *
	 * @var callable
	 */
	private $ratio;

	/**
	 * Builds the printer of the script.
	 *
	 * @param callable|null $active_mode Defaults to the filter `modes_active_mode` of the Modes module, `web` when it is not there.
	 * @param callable|null $print_modes Defaults to the filter `books_preview_frame_modes`, `print` by default.
	 * @param callable|null $ratio       Defaults to the filter `books_print_pixel_ratio`, 3 by default.
	 */
	public function __construct( $active_mode = null, $print_modes = null, $ratio = null ) {
		$this->active_mode = $active_mode ?? static function () {
			return apply_filters( 'modes_active_mode', 'web' );
		};
		$this->print_modes = $print_modes ?? static function () {
			return apply_filters( 'books_preview_frame_modes', array( 'print' ) );
		};
		$this->ratio       = $ratio ?? static function () {
			return apply_filters( 'books_print_pixel_ratio', self::DEFAULT_RATIO );
		};
	}

	/**
	 * Gives the text of the script, or an empty string outside a print mode or for a ratio of 1 or invalid.
	 *
	 * @return string
	 */
	public function script() {
		$mode  = ( $this->active_mode )();
		$modes = ( $this->print_modes )();
		if ( ! is_string( $mode ) || ! is_array( $modes ) || ! in_array( $mode, $modes, true ) ) {
			return '';
		}

		$ratio = ( $this->ratio )();
		if ( ! ( is_int( $ratio ) || is_float( $ratio ) ) || is_nan( (float) $ratio ) || $ratio <= 1 || $ratio > self::MAX_RATIO ) {
			return '';
		}

		// The only variable part is a number that has just been checked.
		return sprintf( "Object.defineProperty(window,'devicePixelRatio',{configurable:true,get:function(){return %s;}});", rtrim( rtrim( number_format( (float) $ratio, 2, '.', '' ), '0' ), '.' ) );
	}

	/**
	 * Prints the script. Action `wp_head`, priority 0.
	 *
	 * @return void
	 */
	public function print_script() {
		$script = $this->script();
		if ( '' !== $script ) {
			wp_print_inline_script_tag( $script );
		}
	}
}
