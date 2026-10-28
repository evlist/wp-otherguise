<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The page that the templates of the theme are composed for.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Project;

use Otherguise\Books\Settings\PageFormat;

defined( 'ABSPATH' ) || exit;

/**
 * The reference of the factor of the page (slice 230): the usable area, in millimetres, that the theme is optimised and tested for. It is
 * declared by the theme as plain data (`settings.custom.otherguise.reference` of its `theme.json`: `width`, `height`, `margin`, `safety`,
 * and an optional `gutter`, all in millimetres); the theme depends on nothing of this plugin. A theme that declares nothing, or something that
 * is not valid, leaves the global page format of the settings as the reference, which is what the factor was before.
 */
final class Reference {
	/**
	 * Returns what the theme declares (any value; it is checked here), null for nothing.
	 *
	 * @var callable
	 */
	private $theme;

	/**
	 * Returns the global page format of the settings: `width_mm`, `height_mm` and `margin_mm`.
	 *
	 * @var callable
	 */
	private $global;

	/**
	 * Builds the service.
	 *
	 * @param callable $theme  Returns what the theme declares.
	 * @param callable $global Returns the global page format of the settings.
	 */
	public function __construct( $theme, $global ) {
		$this->theme  = $theme;
		$this->global = $global;
	}

	/**
	 * Keeps a declaration that can be used: a width and a height inside the limits of the page format, a margin that leaves room, a gutter
	 * that is the margin when it is missing. Returns null for anything else.
	 *
	 * @param mixed $input What the theme declares.
	 * @return array{width:float, height:float, margin:float, gutter:float, safety:float}|null
	 */
	public static function normalize( $input ) {
		if ( ! is_array( $input ) ) {
			return null;
		}

		$number = static function ( $key, $default ) use ( $input ) {
			return isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? round( (float) $input[ $key ], 1 ) : $default;
		};

		$width  = $number( 'width', 0.0 );
		$height = $number( 'height', 0.0 );
		$margin = $number( 'margin', -1.0 );
		$gutter = $number( 'gutter', $margin );
		$safety = $number( 'safety', 0.0 );

		$valid = $width >= PageFormat::MIN_SIDE && $width <= PageFormat::MAX_SIDE && $height >= PageFormat::MIN_SIDE && $height <= PageFormat::MAX_SIDE
			&& $margin >= 0 && $gutter >= 0 && $safety >= 0
			&& $width - $margin - $gutter > 0 && $height - 2 * $margin > 0;

		return $valid ? array(
			'width'  => $width,
			'height' => $height,
			'margin' => $margin,
			'gutter' => $gutter,
			'safety' => $safety,
		) : null;
	}

	/**
	 * The usable width and height of the reference, in millimetres: the page minus the margin and the gutter on the width, minus the margin
	 * above and below on the height. The width and the height are 0 when nothing is known.
	 *
	 * @return array{width:float, height:float}
	 */
	public function usable() {
		$declared = self::normalize( ( $this->theme )() );

		if ( null !== $declared ) {
			return array(
				'width'  => $declared['width'] - $declared['margin'] - $declared['gutter'],
				'height' => $declared['height'] - 2 * $declared['margin'],
			);
		}

		$page   = (array) ( $this->global )();
		$margin = (float) ( $page[ PageFormat::MARGIN ] ?? 0 );

		return array(
			'width'  => max( 0.0, (float) ( $page[ PageFormat::WIDTH ] ?? 0 ) - 2 * $margin ),
			'height' => max( 0.0, (float) ( $page[ PageFormat::HEIGHT ] ?? 0 ) - 2 * $margin ),
		);
	}

	/**
	 * Where the reference comes from: `theme` for a valid declaration, `global` otherwise.
	 *
	 * @return string
	 */
	public function source() {
		return null === self::normalize( ( $this->theme )() ) ? 'global' : 'theme';
	}
}
