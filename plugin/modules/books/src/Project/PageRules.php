<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The CSS that gives the rules of a printer to the page.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Project;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the CSS of a printer profile for the print mode (slice 230): `@page` (size, margins, gutter on the binding side, bleed), the folios in
 * the margin boxes and the variables `--books-margin`, `--books-gutter`, `--books-safe` and `--books-k` (the factor of the page) that a theme can use. Nothing here needs a class of
 * the theme. Checked in Chromium 141 (`@page :left` and `:right`, margin boxes); not through Gotenberg.
 */
final class PageRules {
	/**
	 * The CSS of a profile.
	 *
	 * With a bleed, the page is made larger by the bleed on each side and every margin grows by it, so that the content stays where the profile
	 * puts it inside the trim size. The first page of a document is a right-hand page; `@page :right` has the gutter on its left.
	 *
	 * @param array<string, mixed> $profile A normalized profile.
	 * @param bool                 $folios  Whether the folios are printed (a switch of the project).
	 * @param float|array          $design  The usable area, in millimetres, of the page the templates were composed for (`Reference::usable()`:
	 *                                      `width` and `height`); a number is a width alone; 0 or nothing when it is not known.
	 * @param string               $scaling How the factor is applied: `adapt` (the variable `--books-k`, which the theme uses), `zoom` (a
	 *                                      `zoom` of the page, the variable stays 1, the margins and the folios are divided by the factor
	 *                                      because Chromium zooms them too) or `scale` (the factor is given to the print server, the
	 *                                      variable stays 1; see `factor()`).
	 * @return string
	 */
	public static function css( array $profile, $folios = true, $design = 0.0, $scaling = 'adapt' ) {
		$bleed  = (float) $profile['bleed_mm'];
		$margin = (float) $profile['margin_mm'] + $bleed;
		$gutter = (float) $profile['gutter_mm'] + $bleed;
		$width  = (float) $profile['width_mm'] + 2 * $bleed;
		$height = (float) $profile['height_mm'] + 2 * $bleed;
		$factor = self::factor( $profile, $design );
		$css    = ':root{--books-margin:' . self::mm( (float) $profile['margin_mm'] ) . ';--books-gutter:' . self::mm( (float) $profile['gutter_mm'] ) . ';--books-safe:' . self::mm( (float) $profile['safe_mm'] ) . ';--books-k:' . self::number( 'adapt' === $scaling ? $factor : 1.0 ) . ';}';

		if ( 'zoom' === $scaling && abs( $factor - 1.0 ) > 0.0005 ) {
			$css .= 'html{zoom:' . self::number( $factor ) . ';}';
		}

		// Chromium applies a zoom to the margins of `@page` and to its margin boxes too (a margin of 10 mm at 0.674 is 6.7 mm, the folios shrink
		// with it): `zoom` asks for the margins and the folios divided by the factor so that they come out as the profile says.
		$zoomed = 'zoom' === $scaling && abs( $factor - 1.0 ) > 0.0005 ? $factor : 1.0;
		$places = 1.0 === $zoomed ? 1 : 2;
		$margin = $margin / $zoomed;
		$gutter = $gutter / $zoomed;
		$css   .= '@page{size:' . self::mm( $width ) . ' ' . self::mm( $height ) . ';margin:' . self::mm( $margin, $places ) . ';}';

		// The same outer margin and gutter on both sides gives one rule; otherwise the inner margin is on the binding side.
		if ( abs( $gutter - $margin ) > 0.05 ) {
			$css .= '@page :right{margin-left:' . self::mm( $gutter, $places ) . ';margin-right:' . self::mm( $margin, $places ) . ';}';
			$css .= '@page :left{margin-left:' . self::mm( $margin, $places ) . ';margin-right:' . self::mm( $gutter, $places ) . ';}';
		}

		if ( $folios ) {
			$css .= self::folios( (string) $profile['folio'], $zoomed );
		}

		return $css;
	}

	/**
	 * The factor of the page: the usable area of the profile compared with the usable area the templates were composed for, as the smaller of
	 * the width ratio and the height ratio (the dimension that limits), between a quarter and four, three decimals; 1 when the reference is
	 * not known. With `adapt` a theme multiplies by it the sizes that do not follow the width of the page: `calc( 200px * var( --books-k, 1 ) )`.
	 *
	 * @param array<string, mixed> $profile A normalized profile.
	 * @param float|array          $design  The usable area of the reference (see `css()`); a number is a width alone.
	 * @return float
	 */
	public static function factor( array $profile, $design ) {
		$design = is_array( $design ) ? $design : array( 'width' => (float) $design );
		$ratios = array();

		foreach ( array(
			'width'  => (float) $profile['width_mm'] - (float) $profile['margin_mm'] - (float) $profile['gutter_mm'],
			'height' => (float) $profile['height_mm'] - 2 * (float) $profile['margin_mm'],
		) as $side => $usable ) {
			$reference = (float) ( $design[ $side ] ?? 0 );

			if ( $reference > 0 && $usable > 0 ) {
				$ratios[] = $usable / $reference;
			}
		}

		return array() === $ratios ? 1.0 : round( min( 4.0, max( 0.25, min( $ratios ) ) ), 3 );
	}

	/**
	 * A number with three decimals at most (or the given number) and no trailing zero.
	 *
	 * @param float $value  Value.
	 * @param int   $places Decimals.
	 * @return string
	 */
	private static function number( $value, $places = 3 ) {
		return rtrim( rtrim( number_format( $value, $places, '.', '' ), '0' ), '.' );
	}

	/**
	 * The margin boxes of the folios.
	 *
	 * @param string $position One of `PrinterProfile::FOLIOS`.
	 * @param float  $zoom     The zoom of the page that the folios must undo (1 for none).
	 * @return string
	 */
	private static function folios( $position, $zoom = 1.0 ) {
		$box = '{content:counter(page);font:' . self::number( 9.0 / $zoom, 2 ) . 'pt sans-serif;}';

		if ( 'center' === $position ) {
			return '@page{@bottom-center' . $box . '}';
		}

		if ( 'outside' === $position ) {
			return '@page :right{@bottom-right' . $box . '}@page :left{@bottom-left' . $box . '}';
		}

		return '';
	}

	/**
	 * A length in millimetres, with at most one decimal (two for the margins that a zoom divides).
	 *
	 * @param float $value  Value.
	 * @param int   $places Decimals.
	 * @return string
	 */
	private static function mm( $value, $places = 1 ) {
		return rtrim( rtrim( number_format( $value, $places, '.', '' ), '0' ), '.' ) . 'mm';
	}
}
