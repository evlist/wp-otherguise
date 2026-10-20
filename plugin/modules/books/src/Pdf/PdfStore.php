<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The directory where the PDF files are kept.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the PDF files that the rendering server made (slice 220) in a directory of the uploads, under a name made of the hash that is the
 * key of the cache, so that it cannot be guessed. The files are never served by their address: the directory refuses direct access and
 * the REST route reads them after checking the capability.
 */
final class PdfStore {
	/**
	 * Directory.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Gives the time: seconds since the epoch.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Deletes a file.
	 *
	 * @var callable
	 */
	private $delete;

	/**
	 * Creates a directory and its parents.
	 *
	 * @var callable
	 */
	private $mkdir;

	/**
	 * Builds the store.
	 *
	 * @param string        $directory Directory, without a trailing slash.
	 * @param callable|null $now       Defaults to `time`.
	 * @param callable|null $delete    Defaults to `wp_delete_file`.
	 * @param callable|null $mkdir     Defaults to `wp_mkdir_p`.
	 */
	public function __construct( $directory, $now = null, $delete = null, $mkdir = null ) {
		$this->directory = rtrim( (string) $directory, '/\\' );
		$this->now       = $now ?? 'time';
		$this->delete    = $delete ?? 'wp_delete_file';
		$this->mkdir     = $mkdir ?? 'wp_mkdir_p';
	}

	/**
	 * Tells whether a key is valid: 32 hexadecimal digits.
	 *
	 * @param mixed $key Key.
	 * @return bool
	 */
	public static function is_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $key );
	}

	/**
	 * Tells whether the file of a key exists.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function has( $key ) {
		return self::is_key( $key ) && is_file( $this->path( $key ) );
	}

	/**
	 * Writes the file of a key, and the files that protect the directory when it is created.
	 *
	 * @param string $key   Key.
	 * @param string $bytes Bytes of the PDF.
	 * @return bool
	 */
	public function write( $key, $bytes ) {
		if ( ! self::is_key( $key ) || ! is_string( $bytes ) || '' === $bytes ) {
			return false;
		}

		if ( ! is_dir( $this->directory ) ) {
			if ( ! ( $this->mkdir )( $this->directory ) ) {
				return false;
			}

			file_put_contents( $this->directory . '/index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $this->directory . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}

		return false !== file_put_contents( $this->path( $key ), $bytes );
	}

	/**
	 * Reads the file of a key.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	public function read( $key ) {
		if ( ! $this->has( $key ) ) {
			return null;
		}

		$bytes = file_get_contents( $this->path( $key ) );

		return false === $bytes ? null : $bytes;
	}

	/**
	 * Deletes the PDF files that are older than a number of days.
	 *
	 * @param int $days Number of days.
	 * @return int Number of files deleted.
	 */
	public function cleanup( $days ) {
		$limit   = ( $this->now )() - max( 1, (int) $days ) * 86400;
		$removed = 0;

		foreach ( $this->files() as $file ) {
			$time = filemtime( $file );

			if ( false !== $time && $time < $limit ) {
				( $this->delete )( $file );
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Deletes every file and the directory.
	 *
	 * @return void
	 */
	public function remove_all() {
		if ( ! is_dir( $this->directory ) ) {
			return;
		}

		foreach ( $this->files() as $file ) {
			( $this->delete )( $file );
		}

		foreach ( array( 'index.php', '.htaccess' ) as $name ) {
			if ( is_file( $this->directory . '/' . $name ) ) {
				( $this->delete )( $this->directory . '/' . $name );
			}
		}

		if ( is_dir( $this->directory ) && array() === array_diff( (array) scandir( $this->directory ), array( '.', '..' ) ) ) {
			rmdir( $this->directory );
		}
	}

	/**
	 * Returns the path of the file of a key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public function path( $key ) {
		return $this->directory . '/' . $key . '.pdf';
	}

	/**
	 * Lists the PDF files of the directory.
	 *
	 * @return string[]
	 */
	private function files() {
		$files = glob( $this->directory . '/*.pdf' );

		return is_array( $files ) ? $files : array();
	}
}
