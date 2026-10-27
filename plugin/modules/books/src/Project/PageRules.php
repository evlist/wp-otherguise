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
	 * @param float                $design  The usable width, in millimetres, of the page the templates were composed for (the global format of
	 *                                      the settings); 0 when it is not known.
	 * @return string
	 */
	public static function css( array $profile, $folios = true, $design = 0.0 ) {
		$bleed  = (float) $profile['bleed_mm'];
		$margin = (float) $profile['margin_mm'] + $bleed;
		$gutter = (float) $profile['gutter_mm'] + $bleed;
		$width  = (float) $profile['width_mm'] + 2 * $bleed;
		$height = (float) $profile['height_mm'] + 2 * $bleed;
		$css    = ':root{--books-margin:' . self::mm( (float) $profile['margin_mm'] ) . ';--books-gutter:' . self::mm( (float) $profile['gutter_mm'] ) . ';--books-safe:' . self::mm( (float) $profile['safe_mm'] ) . ';--books-k:' . self::factor( (float) $profile['width_mm'] - (float) $profile['margin_mm'] - (float) $profile['gutter_mm'], (float) $design ) . ';}';
		$css   .= '@page{size:' . self::mm( $width ) . ' ' . self::mm( $height ) . ';margin:' . self::mm( $margin ) . ';}';

		// The same outer margin and gutter on both sides gives one rule; otherwise the inner margin is on the binding side.
		if ( abs( $gutter - $margin ) > 0.05 ) {
			$css .= '@page :right{margin-left:' . self::mm( $gutter ) . ';margin-right:' . self::mm( $margin ) . ';}';
			$css .= '@page :left{margin-left:' . self::mm( $margin ) . ';margin-right:' . self::mm( $gutter ) . ';}';
		}

		if ( $folios ) {
			$css .= self::folios( (string) $profile['folio'] );
		}

		return $css;
	}

	/**
	 * The factor of the page: its usable width over the usable width the templates were composed for, between a quarter and four, three
	 * decimals; 1 when the second is not known. A theme multiplies the sizes that do not follow the width of the page by it:
	 * `calc( 200px * var( --books-k, 1 ) )`.
	 *
	 * @param float $usable Usable width of the page, in millimetres.
	 * @param float $design Usable width of the page the templates were composed for, 0 when it is not known.
	 * @return string
	 */
	private static function factor( $usable, $design ) {
		if ( $design <= 0 || $usable <= 0 ) {
			return '1';
		}

		return rtrim( rtrim( number_format( min( 4.0, max( 0.25, $usable / $design ) ), 3, '.', '' ), '0' ), '.' );
	}

	/**
	 * The margin boxes of the folios.
	 *
	 * @param string $position One of `PrinterProfile::FOLIOS`.
	 * @return string
	 */
	private static function folios( $position ) {
		$box = '{content:counter(page);font:9pt sans-serif;}';

		if ( 'center' === $position ) {
			return '@page{@bottom-center' . $box . '}';
		}

		if ( 'outside' === $position ) {
			return '@page :right{@bottom-right' . $box . '}@page :left{@bottom-left' . $box . '}';
		}

		return '';
	}

	/**
	 * A length in millimetres, with at most one decimal.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function mm( $value ) {
		return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' ) . 'mm';
	}
}
