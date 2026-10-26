<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The number of pages of a PDF file, counted without loading it.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the page objects (`/Type /Page`, not `/Type /Pages`) of a PDF file by reading it in pieces (slice 226), so that a PDF of
 * hundreds of megabytes is not loaded in memory and does not have to be sent back to the server to be counted. It is right for the files
 * that Chromium writes, whose page objects are not compressed; it gives `null` when it finds none (another producer, or an object
 * stream), and the caller can then ask the server.
 */
final class PdfPages {
	/**
	 * Bytes read at a time.
	 */
	public const CHUNK = 4194304;

	/**
	 * Bytes of the next piece that are read too, so that a mark cut by the end of a piece is found.
	 */
	public const OVERLAP = 64;

	/**
	 * Counts the pages of a file.
	 *
	 * @param string $path Path of the file.
	 * @param int    $chunk Bytes read at a time (the tests make it small).
	 * @return int|null
	 */
	public static function count( $path, $chunk = self::CHUNK ) {
		if ( ! is_string( $path ) || ! is_file( $path ) || $chunk < self::OVERLAP * 2 ) {
			return null;
		}

		$size  = (int) filesize( $path );
		$pages = 0;

		for ( $offset = 0; $offset < $size; $offset += $chunk ) {
			$piece = file_get_contents( $path, false, null, $offset, $chunk + self::OVERLAP );

			if ( false === $piece ) {
				return null;
			}

			if ( false === preg_match_all( '/\/Type\s*\/Page(?![A-Za-z])/', $piece, $found, PREG_OFFSET_CAPTURE ) ) {
				return null;
			}

			foreach ( $found[0] as $match ) {
				// A mark that starts in the overlap is counted with the next piece.
				if ( $match[1] < $chunk ) {
					++$pages;
				}
			}
		}

		return $pages > 0 ? $pages : null;
	}
}
