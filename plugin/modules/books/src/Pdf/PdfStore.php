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
	 * Removes an empty directory.
	 *
	 * @var callable
	 */
	private $rmdir;

	/**
	 * Moves a file over another: receives the source and the target.
	 *
	 * @var callable
	 */
	private $move;

	/**
	 * Builds the store.
	 *
	 * @param string        $directory Directory, without a trailing slash.
	 * @param callable|null $now       Defaults to `time`.
	 * @param callable|null $delete    Defaults to `wp_delete_file`.
	 * @param callable|null $mkdir     Defaults to `wp_mkdir_p`.
	 * @param callable|null $rmdir     Defaults to `remove_directory()`, which uses the WordPress file system API.
	 * @param callable|null $move      Defaults to `move_file()`, which uses the WordPress file system API.
	 */
	public function __construct( $directory, $now = null, $delete = null, $mkdir = null, $rmdir = null, $move = null ) {
		$this->directory = rtrim( (string) $directory, '/\\' );
		$this->now       = $now ?? 'time';
		$this->delete    = $delete ?? 'wp_delete_file';
		$this->mkdir     = $mkdir ?? 'wp_mkdir_p';
		$this->rmdir     = $rmdir ?? array( self::class, 'remove_directory' );
		$this->move      = $move ?? array( self::class, 'move_file' );
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
		if ( ! self::is_key( $key ) || ! is_string( $bytes ) || '' === $bytes || ! $this->prepare() ) {
			return false;
		}

		return false !== file_put_contents( $this->path( $key ), $bytes );
	}

	/**
	 * Creates the directory, with the files that protect it, when it does not exist.
	 *
	 * @return bool
	 */
	private function prepare() {
		if ( is_dir( $this->directory ) ) {
			return true;
		}

		if ( ! ( $this->mkdir )( $this->directory ) ) {
			return false;
		}

		file_put_contents( $this->directory . '/index.php', "<?php\n// Silence is golden.\n" );
		file_put_contents( $this->directory . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );

		return true;
	}

	/**
	 * Returns the path where a file is written while it is made (a server writes its answer there, which keeps a large PDF out of the
	 * memory): the directory is created when needed. The file is made a PDF of the cache by `commit()`.
	 *
	 * @param string $key Key.
	 * @return string An empty string when the key is not valid or the directory cannot be made.
	 */
	public function partial_path( $key ) {
		return self::is_key( $key ) && $this->prepare() ? $this->directory . '/' . $key . '.part' : '';
	}

	/**
	 * Makes the file written at `partial_path()` the file of the key, when it is a PDF; deletes it when it is not.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function commit( $key ) {
		$part = $this->partial_path( $key );

		if ( '' === $part || ! is_file( $part ) || 0 === (int) filesize( $part ) || '%PDF-' !== file_get_contents( $part, false, null, 0, 5 ) ) {
			$this->discard( $key );

			return false;
		}

		return (bool) ( $this->move )( $part, $this->path( $key ) );
	}

	/**
	 * Deletes the file that was being written for a key.
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public function discard( $key ) {
		if ( self::is_key( $key ) && is_file( $this->directory . '/' . $key . '.part' ) ) {
			( $this->delete )( $this->directory . '/' . $key . '.part' );
		}
	}

	/**
	 * Gives the size of the file of a key, in bytes.
	 *
	 * @param string $key Key.
	 * @return int 0 when there is no file.
	 */
	public function size( $key ) {
		return $this->has( $key ) ? (int) filesize( $this->path( $key ) ) : 0;
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
			( $this->rmdir )( $this->directory );
		}
	}

	/**
	 * Removes an empty directory with the WordPress file system API (Plugin Check refuses a direct `rmdir()`).
	 *
	 * @param string $directory Directory.
	 * @return bool
	 */
	public static function remove_directory( $directory ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) ) {
			return false;
		}

		return (bool) $wp_filesystem->rmdir( $directory );
	}

	/**
	 * Moves a file over another with the WordPress file system API (Plugin Check refuses a direct `rename()`).
	 *
	 * @param string $from Source.
	 * @param string $to   Target.
	 * @return bool
	 */
	public static function move_file( $from, $to ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) ) {
			return false;
		}

		return (bool) $wp_filesystem->move( $from, $to, true );
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
	 * Lists the PDF files of the directory, and the files that were being written.
	 *
	 * @return string[]
	 */
	private function files() {
		$files = glob( $this->directory . '/*.{pdf,part}', GLOB_BRACE );

		return is_array( $files ) ? $files : array();
	}
}
