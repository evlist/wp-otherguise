<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The format of the page of a book.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The option `books_page_format`: the width and the height of the page and its margins, in millimetres (slice 219). The default is A5
 * (148 x 210 mm) with margins of 10 mm. A value that is not a number, or is out of bounds, is replaced by the default of that field; the
 * margin must leave something of the page, so it is less than half of the smaller side.
 */
final class PageFormat {
	/**
	 * Name of the option.
	 */
	public const OPTION = 'books_page_format';

	/**
	 * Key of the width.
	 */
	public const WIDTH = 'width_mm';

	/**
	 * Key of the height.
	 */
	public const HEIGHT = 'height_mm';

	/**
	 * Key of the margin.
	 */
	public const MARGIN = 'margin_mm';

	/**
	 * Smallest side of a page, in millimetres.
	 */
	public const MIN_SIDE = 50.0;

	/**
	 * Largest side of a page, in millimetres.
	 */
	public const MAX_SIDE = 1000.0;

	/**
	 * Default values.
	 *
	 * @var array<string, float>
	 */
	private const DEFAULTS = array(
		self::WIDTH  => 148.0,
		self::HEIGHT => 210.0,
		self::MARGIN => 10.0,
	);

	/**
	 * Returns the default format.
	 *
	 * @return array<string, float>
	 */
	public static function defaults() {
		return self::DEFAULTS;
	}

	/**
	 * Returns the format stored, with the default for each field that is missing or not valid.
	 *
	 * @return array<string, float>
	 */
	public function get() {
		$stored = get_option( self::OPTION, array() );

		return $this->normalize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Sanitizes the option when it is saved. Unknown keys of the form are dropped.
	 *
	 * @param mixed $input Value posted.
	 * @return array<string, float>
	 */
	public function sanitize( $input ) {
		return $this->normalize( is_array( $input ) ? $input : array() );
	}

	/**
	 * Keeps the valid numbers and replaces the others by the defaults.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return array<string, float>
	 */
	private function normalize( array $values ) {
		$width  = $this->number( $values, self::WIDTH, self::MIN_SIDE, self::MAX_SIDE );
		$height = $this->number( $values, self::HEIGHT, self::MIN_SIDE, self::MAX_SIDE );
		$limit  = min( $width, $height ) / 2;
		$margin = $this->number( $values, self::MARGIN, 0.0, $limit );

		// The upper bound is exclusive: a margin of half the side would leave no page.
		if ( $margin >= $limit ) {
			$margin = min( self::DEFAULTS[ self::MARGIN ], $limit / 2 );
		}

		return array(
			self::WIDTH  => $width,
			self::HEIGHT => $height,
			self::MARGIN => $margin,
		);
	}

	/**
	 * Reads one number of the values, rounded to a tenth of a millimetre, or the default when it is missing or out of bounds.
	 *
	 * @param array<string, mixed> $values Values.
	 * @param string               $key    Key.
	 * @param float                $min    Smallest value, included.
	 * @param float                $max    Largest value, included.
	 * @return float
	 */
	private function number( array $values, $key, $min, $max ) {
		$value = $values[ $key ] ?? null;

		if ( is_string( $value ) ) {
			$value = str_replace( ',', '.', trim( $value ) );
		}

		if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
			return self::DEFAULTS[ $key ];
		}

		$number = round( (float) $value, 1 );

		return is_finite( $number ) && $number >= $min && $number <= $max ? $number : self::DEFAULTS[ $key ];
	}
}
