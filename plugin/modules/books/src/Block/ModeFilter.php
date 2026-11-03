<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The attribute `modes` of the blocks of the sections.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Block;

defined( 'ABSPATH' ) || exit;

/**
 * The blocks `books/section-list` and `books/section-merge` have an attribute `modes`: the slugs of the modes in which they act. An empty
 * list means every mode (slice 235).
 */
final class ModeFilter {
	/**
	 * Tells whether a block acts in the mode of the request.
	 *
	 * @param mixed  $attributes Attributes of the block.
	 * @param string $slug       Slug of the mode of the request, an empty string when unknown (Modes absent).
	 * @return bool
	 */
	public static function allows( $attributes, $slug ) {
		$modes = is_array( $attributes ) && isset( $attributes['modes'] ) && is_array( $attributes['modes'] ) ? array_filter( $attributes['modes'], 'is_string' ) : array();

		return array() === $modes || in_array( (string) $slug, $modes, true );
	}

	/**
	 * The slug of the mode of the request, an empty string without the Modes module.
	 *
	 * @return string
	 */
	public static function active() {
		$mode = function_exists( 'modes_active_mode' ) ? modes_active_mode() : null;

		return null === $mode ? '' : $mode->slug();
	}

	/**
	 * The modes to choose from in the editor: `value` (slug) and `label`.
	 *
	 * @param iterable $modes The definitions of the modes.
	 * @return array<int, array{value:string,label:string}>
	 */
	public static function choices( $modes ) {
		$choices = array();

		foreach ( $modes as $mode ) {
			$choices[] = array(
				'value' => $mode->slug(),
				'label' => $mode->label(),
			);
		}

		return $choices;
	}
}
