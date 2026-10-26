<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The rules of a printer.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Project;

defined( 'ABSPATH' ) || exit;

/**
 * What a printer asks for a book, as a list of fields with a default each (slice 230): the format and the margins of the page, the number of
 * pages, the blank pages, the images and the weight. `normalize()` keeps the valid values and replaces the others by the defaults, so what is
 * read from a post is always usable. The values are the printer's recommendations as the author copied them, not measured limits.
 */
final class PrinterProfile {
	/**
	 * The values of the field `folio`.
	 */
	public const FOLIOS = array( 'none', 'center', 'outside' );

	/**
	 * The values of the field `cover`.
	 */
	public const COVERS = array( 'none', 'separate', 'first_last', 'combined' );

	/**
	 * The values of the field `colour`.
	 */
	public const COLOURS = array( 'rgb', 'cmyk' );

	/**
	 * Smallest side of a page, in millimetres.
	 */
	public const MIN_SIDE = 50.0;

	/**
	 * Largest side of a page, in millimetres.
	 */
	public const MAX_SIDE = 1000.0;

	/**
	 * The defaults: an A5 page with margins of 10 mm and no rule of a printer.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'width_mm'       => 148.0,
			'height_mm'      => 210.0,
			'margin_mm'      => 10.0,
			'gutter_mm'      => 10.0,
			'safe_mm'        => 10.0,
			'bleed_mm'       => 0.0,
			'folio'          => 'none',
			'pages_min'      => 0,
			'pages_max'      => 0,
			'pages_multiple' => 2,
			'final_blank'    => false,
			'chapter_recto'  => false,
			'cover'          => 'none',
			'min_ppi'        => 300,
			'colour'         => 'rgb',
			'max_mb'         => 0,
			'url'            => '',
			'read_on'        => '',
		);
	}

	/**
	 * Keeps the valid values and replaces the others by the defaults. A gutter that is not given is the margin.
	 *
	 * @param mixed $input Values (an array, anything else gives the defaults).
	 * @return array<string, mixed>
	 */
	public static function normalize( $input ) {
		$in       = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$width    = self::number( $in, 'width_mm', self::MIN_SIDE, self::MAX_SIDE, $defaults['width_mm'] );
		$height   = self::number( $in, 'height_mm', self::MIN_SIDE, self::MAX_SIDE, $defaults['height_mm'] );
		$side     = min( $width, $height ) / 2;
		$margin   = self::number( $in, 'margin_mm', 0.0, $side, min( $defaults['margin_mm'], $side / 2 ) );
		$margin   = $margin >= $side ? min( $defaults['margin_mm'], $side / 2 ) : $margin;
		$gutter   = array_key_exists( 'gutter_mm', $in ) && '' !== $in['gutter_mm']
			? self::number( $in, 'gutter_mm', 0.0, $width / 2, $margin )
			: $margin;
		$gutter   = $gutter >= $width / 2 ? $margin : $gutter;

		return array(
			'width_mm'       => $width,
			'height_mm'      => $height,
			'margin_mm'      => $margin,
			'gutter_mm'      => $gutter,
			'safe_mm'        => self::number( $in, 'safe_mm', 0.0, 100.0, $defaults['safe_mm'] ),
			'bleed_mm'       => self::number( $in, 'bleed_mm', 0.0, 20.0, $defaults['bleed_mm'] ),
			'folio'          => self::choice( $in, 'folio', self::FOLIOS, $defaults['folio'] ),
			'pages_min'      => self::integer( $in, 'pages_min', 0, 5000, $defaults['pages_min'] ),
			'pages_max'      => self::integer( $in, 'pages_max', 0, 5000, $defaults['pages_max'] ),
			'pages_multiple' => self::integer( $in, 'pages_multiple', 1, 16, $defaults['pages_multiple'] ),
			'final_blank'    => self::flag( $in, 'final_blank' ),
			'chapter_recto'  => self::flag( $in, 'chapter_recto' ),
			'cover'          => self::choice( $in, 'cover', self::COVERS, $defaults['cover'] ),
			'min_ppi'        => self::integer( $in, 'min_ppi', 0, 1200, $defaults['min_ppi'] ),
			'colour'         => self::choice( $in, 'colour', self::COLOURS, $defaults['colour'] ),
			'max_mb'         => self::integer( $in, 'max_mb', 0, 100000, $defaults['max_mb'] ),
			'url'            => self::address( $in ),
			'read_on'        => self::date( $in ),
		);
	}

	/**
	 * The format that the preview panel and the PDF job understand: the size of the page and its margin.
	 *
	 * @param array<string, mixed> $profile A normalized profile.
	 * @return array<string, float>
	 */
	public static function format( array $profile ) {
		return array(
			'width_mm'  => (float) $profile['width_mm'],
			'height_mm' => (float) $profile['height_mm'],
			'margin_mm' => (float) $profile['margin_mm'],
		);
	}

	/**
	 * Reads a number, rounded to a tenth of a millimetre.
	 *
	 * @param array<string, mixed> $in      Values.
	 * @param string               $key     Key.
	 * @param float                $min     Smallest value, included.
	 * @param float                $max     Largest value, included.
	 * @param float                $default_value Value when missing or out of bounds.
	 * @return float
	 */
	private static function number( array $in, $key, $min, $max, $default_value ) {
		$value = $in[ $key ] ?? null;

		if ( is_string( $value ) ) {
			$value = str_replace( ',', '.', trim( $value ) );
		}

		if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
			return (float) $default_value;
		}

		$number = round( (float) $value, 1 );

		return is_finite( $number ) && $number >= $min && $number <= $max ? $number : (float) $default_value;
	}

	/**
	 * Reads a whole number.
	 *
	 * @param array<string, mixed> $in            Values.
	 * @param string               $key           Key.
	 * @param int                  $min           Smallest value, included.
	 * @param int                  $max           Largest value, included.
	 * @param int                  $default_value Value when missing or out of bounds.
	 * @return int
	 */
	private static function integer( array $in, $key, $min, $max, $default_value ) {
		$value = $in[ $key ] ?? null;

		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value ) ) ) {
			return $default_value;
		}

		$number = (int) $value;

		return $number >= $min && $number <= $max ? $number : $default_value;
	}

	/**
	 * Reads a choice among a list.
	 *
	 * @param array<string, mixed> $in            Values.
	 * @param string               $key           Key.
	 * @param string[]             $allowed       Allowed values.
	 * @param string               $default_value Default.
	 * @return string
	 */
	private static function choice( array $in, $key, array $allowed, $default_value ) {
		$value = $in[ $key ] ?? null;

		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $default_value;
	}

	/**
	 * Reads a switch: true for `true`, `1`, `'1'`, `'on'` and `'yes'`.
	 *
	 * @param array<string, mixed> $in  Values.
	 * @param string               $key Key.
	 * @return bool
	 */
	private static function flag( array $in, $key ) {
		$value = $in[ $key ] ?? false;

		return true === $value || 1 === $value || ( is_string( $value ) && in_array( strtolower( $value ), array( '1', 'on', 'yes', 'true' ), true ) );
	}

	/**
	 * Reads the address of the documentation of the printer: an http or https address, else empty.
	 *
	 * @param array<string, mixed> $in Values.
	 * @return string
	 */
	private static function address( array $in ) {
		$value = $in['url'] ?? '';

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		return 1 === preg_match( '#^https?://[^\s<>"\']+$#i', $value ) && strlen( $value ) <= 500 ? $value : '';
	}

	/**
	 * Reads the date on which the documentation was read: `Y-m-d` or empty.
	 *
	 * @param array<string, mixed> $in Values.
	 * @return string
	 */
	private static function date( array $in ) {
		$value = $in['read_on'] ?? '';

		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $value ), $parts ) ) {
			return '';
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? trim( $value ) : '';
	}
}
