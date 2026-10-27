<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the merge of a section asks of the request.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WordPress and the address for `MergedPrint` (slice 231).
 */
final class MergeRequest {
	/**
	 * Reads the request: a print mode, the single post shown, the preview of the editor, the number of units asked for.
	 *
	 * @return array{print:bool, section:int, preview:bool, limit:int}
	 */
	public function read() {
		$modes = apply_filters( 'books_preview_frame_modes', array( 'print' ) );
		$mode  = function_exists( 'modes_active_mode' ) ? modes_active_mode() : null;
		$limit = filter_input( INPUT_GET, MergedPrint::LIMIT, FILTER_VALIDATE_INT );

		return array(
			'print'   => null !== $mode && is_array( $modes ) && in_array( $mode->slug(), $modes, true ),
			'section' => is_singular() ? (int) get_queried_object_id() : 0,
			'preview' => null !== filter_input( INPUT_GET, MergedPrint::PREVIEW, FILTER_UNSAFE_RAW ),
			'limit'   => is_int( $limit ) ? $limit : 0,
		);
	}
}
