<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The log of the merge of a section.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

defined( 'ABSPATH' ) || exit;

/**
 * One JSON line per event: durations, queries, the memory of PHP and, on Linux, the resident memory of the process read from
 * `/proc/self/status` (slice 231). **Off by default**: it is on when the option `books_merge_log` is set (`wp option update books_merge_log 1`)
 * or the site is in debug mode. The file is in the protected directory of the PDF files, under a name that depends on a key of the site
 * (`wp otherguise books merge-log` prints it and its lines); the daily clean-up and the uninstall remove it, and it is emptied when it passes
 * a megabyte.
 */
final class MergeLog {
	/**
	 * Name of the option that turns the log on.
	 */
	public const OPTION = 'books_merge_log';

	/**
	 * Size at which the file is started again, in bytes.
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Tells whether the log is on.
	 *
	 * @var callable
	 */
	private $enabled;

	/**
	 * Returns the path of the file, an empty string when it cannot be made.
	 *
	 * @var callable
	 */
	private $path;

	/**
	 * Builds the log.
	 *
	 * @param callable|null $enabled Defaults to the option and the debug mode.
	 * @param callable|null $path    Defaults to a file of the protected directory of the PDF files.
	 */
	public function __construct( $enabled = null, $path = null ) {
		$this->enabled = $enabled ?? static function () {
			return (bool) get_option( self::OPTION, false ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		};
		$this->path    = $path ?? static function () {
			return \Otherguise\Books\Module::store()->log_path( self::file_name() );
		};
	}

	/**
	 * The name of the file: `merge-` and sixteen hexadecimal digits of a signature made with a key of the site, `.log`.
	 *
	 * @return string
	 */
	public static function file_name() {
		return 'merge-' . substr( hash_hmac( 'sha256', 'books-merge-log', wp_salt( 'auth' ) ), 0, 16 ) . '.log';
	}

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
	 * Tells whether the log is on.
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) ( $this->enabled )();
	}

	/**
	 * The path of the file, an empty string when it cannot be made.
	 *
	 * @return string
	 */
	public function path() {
		return (string) ( $this->path )();
	}

	/**
	 * The last lines of the log.
	 *
	 * @param int $count Number of lines.
	 * @return string[]
	 */
	public function lines( $count ) {
		$path = $this->path();

		if ( '' === $path || ! is_file( $path ) ) {
			return array();
		}

		$lines = array_filter( explode( "\n", (string) file_get_contents( $path ) ), 'strlen' );

		return array_values( array_slice( $lines, -max( 1, (int) $count ) ) );
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public function clear() {
		$path = $this->path();

		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Writes an event, when the log is on.
	 *
	 * @param string               $event  Name of the event.
	 * @param array<string, mixed> $fields Fields.
	 * @return void
	 */
	public function write( $event, array $fields = array() ) {
		if ( ! $this->enabled() ) {
			return;
		}

		$path = $this->path();

		if ( '' === $path ) {
			return;
		}

		if ( is_file( $path ) && filesize( $path ) > self::MAX_BYTES ) {
			wp_delete_file( $path );
		}

		$line = wp_json_encode(
			array_merge(
				array(
					'at'    => gmdate( 'c' ),
					'event' => $event,
				),
				$fields,
				self::memory()
			)
		);

		file_put_contents( $path, $line . "\n", FILE_APPEND );
	}
}
