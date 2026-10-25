<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The log of the merged print test.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

defined( 'ABSPATH' ) || exit;

/**
 * **Test branch `merge-all-html` only.** Appends one JSON line per event to `uploads/books-merge/merge.log`: durations, the memory of PHP
 * and, on Linux, the resident memory of the process read from `/proc/self/status`. Nothing here is meant to stay.
 */
final class MergeLog {
	/**
	 * Name of the directory of the uploads that holds the log.
	 */
	public const DIRECTORY = 'books-merge';

	/**
	 * Memory figures of the process, in megabytes.
	 *
	 * @return array<string, float>
	 */
	public static function memory() {
		$figures = array(
			'php_mb'      => round( memory_get_usage( true ) / 1048576, 1 ),
			'php_peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 1 ),
		);

		$status = is_readable( '/proc/self/status' ) ? file_get_contents( '/proc/self/status' ) : false;

		if ( is_string( $status ) ) {
			foreach ( array(
				'VmRSS' => 'rss_mb',
				'VmHWM' => 'rss_peak_mb',
			) as $key => $name ) {
				if ( 1 === preg_match( '/^' . $key . ':\s+(\d+)\s+kB/m', $status, $found ) ) {
					$figures[ $name ] = round( (int) $found[1] / 1024, 1 );
				}
			}
		}

		return $figures;
	}

	/**
	 * Writes an event.
	 *
	 * @param string               $event  Name of the event.
	 * @param array<string, mixed> $fields Fields.
	 * @return void
	 */
	public static function write( $event, array $fields = array() ) {
		$uploads = wp_upload_dir();
		$base    = rtrim( (string) ( $uploads['basedir'] ?? '' ), '/' ) . '/' . self::DIRECTORY;

		if ( '' === $base || ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) ) {
			return;
		}

		$line = wp_json_encode(
			array_merge(
				array(
					'at' => gmdate( 'c' ),
					'event' => $event,
				),
				$fields,
				self::memory()
			)
		);

		file_put_contents( $base . '/merge.log', $line . "\n", FILE_APPEND );
	}
}
