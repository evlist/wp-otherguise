<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The icons the block that links to a mode can show.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Link;

defined( 'ABSPATH' ) || exit;

/**
 * Small inline SVG icons drawn with `currentColor`, so that they take the colour of the text around them and need no font or file.
 */
final class LinkIcons {
	/**
	 * Returns the icons.
	 *
	 * @return array<string, array{label: string, svg: string}> Label and SVG by slug.
	 */
	public static function all() {
		$open = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">';
		$icons = array(
			'print' => array(
				'label' => __( 'Printer', 'otherguise' ),
				'svg'   => $open . '<path fill="currentColor" d="M6 3h12v5H6zM3 9h18v8h-3v-3H6v3H3zM8 15h8v6H8z"/></svg>',
			),
			'web'   => array(
				'label' => __( 'Globe', 'otherguise' ),
				'svg'   => $open . '<g fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/></g></svg>',
			),
		);

		/**
		 * Filters the icons of the block that links to a mode.
		 *
		 * @param array<string, array{label: string, svg: string}> $icons Label and SVG (with `currentColor`) by slug.
		 */
		$filtered = apply_filters( 'modes_link_icons', $icons );

		return is_array( $filtered ) ? $filtered : $icons;
	}

	/**
	 * Returns the SVG of an icon.
	 *
	 * @param string $slug Slug of the icon.
	 * @return string Empty when there is no such icon.
	 */
	public static function svg( $slug ) {
		$icons = self::all();

		return isset( $icons[ $slug ]['svg'] ) && is_string( $icons[ $slug ]['svg'] ) ? $icons[ $slug ]['svg'] : '';
	}
}
