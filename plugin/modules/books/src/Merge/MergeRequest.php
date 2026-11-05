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
	 * Reads the request: the single post shown, the preview of the editor, the number of units asked for. The mode is the business of the
	 * block that asks for the merge (its `modes`).
	 *
	 * @return array{section:int, preview:bool, limit:int, pages:string}
	 */
	public function read() {
		$limit = filter_input( INPUT_GET, MergedPrint::LIMIT, FILTER_VALIDATE_INT );
		$pages = filter_input( INPUT_GET, MergedPrint::PAGES, FILTER_UNSAFE_RAW );

		return array(
			'section' => is_singular() ? (int) get_queried_object_id() : 0,
			'preview' => null !== filter_input( INPUT_GET, MergedPrint::PREVIEW, FILTER_UNSAFE_RAW ),
			'limit'   => is_int( $limit ) ? $limit : 0,
			'pages'   => is_string( $pages ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $pages ) ? $pages : '',
		);
	}
}
