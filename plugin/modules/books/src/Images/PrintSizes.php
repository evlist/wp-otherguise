<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The sizes of the images for the print.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Images;

use Otherguise\Books\Settings\PageFormat;

defined( 'ABSPATH' ) || exit;

/**
 * Computes and registers the image sizes of the print (slice 221). The theme or the site declares them with the filter
 * `books_print_sizes` as fractions of the usable width of the page (the page minus its margins) and an optional ratio; this class turns
 * them into pixels for a target resolution (300 ppi by default) from the format of the page of the book, and registers them with
 * `add_image_size()`, so that Media Helper makes them for every photo. A name is `books-print-` followed by lower case letters, digits
 * and hyphens.
 */
final class PrintSizes {
	/**
	 * Prefix of the names.
	 */
	public const PREFIX = 'books-print-';

	/**
	 * Default target resolution, in pixels per inch.
	 */
	public const PPI = 300;

	/**
	 * Format of the page.
	 *
	 * @var PageFormat
	 */
	private $format;

	/**
	 * Applies a filter: receives its name and a value.
	 *
	 * @var callable
	 */
	private $filter;

	/**
	 * Registers a size: `add_image_size`.
	 *
	 * @var callable
	 */
	private $add_size;

	/**
	 * Builds the sizes.
	 *
	 * @param PageFormat    $format   Format of the page.
	 * @param callable|null $filter   Receives the name of a filter and a value; defaults to `apply_filters`.
	 * @param callable|null $add_size Receives the name, the width, the height and the crop; defaults to `add_image_size`.
	 */
	public function __construct( PageFormat $format, $filter = null, $add_size = null ) {
		$this->format   = $format;
		$this->filter   = $filter ?? 'apply_filters';
		$this->add_size = $add_size ?? 'add_image_size';
	}

	/**
	 * The target resolution: 300 ppi, changed by the filter `books_print_ppi`, between 72 and 1200.
	 *
	 * @return int
	 */
	public function ppi() {
		$ppi = ( $this->filter )( 'books_print_ppi', self::PPI );

		return is_int( $ppi ) && $ppi >= 72 && $ppi <= 1200 ? $ppi : self::PPI;
	}

	/**
	 * The sizes declared by the theme or the site that are valid; the others are ignored.
	 *
	 * @return array<string, array{fraction:float, ratio:float|null}>
	 */
	public function declared() {
		$declared = ( $this->filter )( 'books_print_sizes', array() );
		$valid    = array();

		if ( ! is_array( $declared ) ) {
			return $valid;
		}

		foreach ( $declared as $name => $definition ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '[a-z0-9]+(-[a-z0-9]+)*$/', $name ) || ! is_array( $definition ) ) {
				continue;
			}

			$fraction = $definition['fraction'] ?? null;

			if ( ! ( is_int( $fraction ) || is_float( $fraction ) ) || $fraction < 0.05 || $fraction > 1 ) {
				continue;
			}

			$ratio = null;

			if ( isset( $definition['ratio'] ) ) {
				$ratio = self::ratio( $definition['ratio'] );

				if ( null === $ratio ) {
					continue;
				}
			}

			$valid[ $name ] = array(
				'fraction' => (float) $fraction,
				'ratio'    => $ratio,
			);
		}

		return $valid;
	}

	/**
	 * The sizes in pixels, for the format of the page: the width, the height (0 when it is open, the image keeping its proportions) and
	 * whether it is cropped.
	 *
	 * @return array<string, array{width:int, height:int, crop:bool, fraction:float, ratio:float|null}>
	 */
	public function sizes() {
		$page   = $this->format->get();
		$usable = $page[ PageFormat::WIDTH ] - 2 * $page[ PageFormat::MARGIN ];
		$ppi    = $this->ppi();
		$sizes  = array();

		foreach ( $this->declared() as $name => $definition ) {
			$width = (int) ceil( $usable * $definition['fraction'] / 25.4 * $ppi );

			$sizes[ $name ] = array(
				'width'    => $width,
				'height'   => null === $definition['ratio'] ? 0 : (int) round( $width / $definition['ratio'] ),
				'crop'     => null !== $definition['ratio'],
				'fraction' => $definition['fraction'],
				'ratio'    => $definition['ratio'],
			);
		}

		return $sizes;
	}

	/**
	 * Registers the sizes with WordPress. Action `init`, late, so that the theme has declared its sizes.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( $this->sizes() as $name => $size ) {
			( $this->add_size )( $name, $size['width'], 0 === $size['height'] ? 9999 : $size['height'], $size['crop'] );
		}
	}

	/**
	 * Reads a ratio: `W:H` (`16:9`) or a number (the width divided by the height). Null when it is not one.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	public static function ratio( $value ) {
		if ( is_string( $value ) && 1 === preg_match( '/^([0-9]+(?:\.[0-9]+)?):([0-9]+(?:\.[0-9]+)?)$/', trim( $value ), $parts ) ) {
			$value = (float) $parts[2] > 0 ? (float) $parts[1] / (float) $parts[2] : 0;
		}

		return ( is_int( $value ) || is_float( $value ) ) && $value >= 0.2 && $value <= 5 ? (float) $value : null;
	}
}
