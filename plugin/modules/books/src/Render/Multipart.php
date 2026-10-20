<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A multipart/form-data body.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the body of a `multipart/form-data` request by hand, as the WordPress HTTP API has no helper for files (slice 220). No
 * dependency; the names and file names are refused when they could break the headers.
 */
final class Multipart {
	/**
	 * Builds the body.
	 *
	 * @param array<string, string>                                                     $fields   Text fields by name.
	 * @param array<int, array{name:string, filename:string, type:string, data:string}> $files    Files.
	 * @param string                                                                    $boundary Boundary.
	 * @return string
	 * @throws \InvalidArgumentException When a name, a file name or a type cannot be put in a header, or the boundary is found in a part.
	 */
	public static function build( array $fields, array $files, $boundary ) {
		$body = '';

		foreach ( $fields as $name => $value ) {
			self::check_header_text( (string) $name );
			self::check_boundary( (string) $value, $boundary );

			$body .= '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n" . $value . "\r\n";
		}

		foreach ( $files as $file ) {
			self::check_header_text( $file['name'] );
			self::check_header_text( $file['filename'] );
			self::check_header_text( $file['type'] );
			self::check_boundary( $file['data'], $boundary );

			$body .= '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="' . $file['name'] . '"; filename="' . $file['filename'] . '"' . "\r\n" . 'Content-Type: ' . $file['type'] . "\r\n\r\n" . $file['data'] . "\r\n";
		}

		return $body . '--' . $boundary . "--\r\n";
	}

	/**
	 * Makes a boundary that is not in the parts.
	 *
	 * @return string
	 */
	public static function boundary() {
		return 'books' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Refuses text that cannot be put in a header: an empty text, a quote, a line break.
	 *
	 * @param string $text Text.
	 * @return void
	 * @throws \InvalidArgumentException When it cannot.
	 */
	private static function check_header_text( $text ) {
		if ( '' === $text || 1 === preg_match( '/["\r\n\x00]/', $text ) ) {
			throw new \InvalidArgumentException( 'A name that cannot be put in a header.' );
		}
	}

	/**
	 * Refuses a part that contains the boundary.
	 *
	 * @param string $data     Part.
	 * @param string $boundary Boundary.
	 * @return void
	 * @throws \InvalidArgumentException When it does.
	 */
	private static function check_boundary( $data, $boundary ) {
		if ( false !== strpos( $data, $boundary ) ) {
			throw new \InvalidArgumentException( 'A part contains the boundary.' );
		}
	}
}
