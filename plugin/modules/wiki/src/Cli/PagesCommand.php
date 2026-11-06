<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI: the pages of the wiki.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Cli;

use Otherguise\Wiki\NameException;
use Otherguise\Wiki\Page\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise wiki create` (slice 502): makes a page with `Pages::create()`, the service that the screens and the migration use too. The
 * content is given as HTML and kept as it is: nothing here imports or knows MediaWiki.
 */
final class PagesCommand {
	/**
	 * The pages.
	 *
	 * @var Pages
	 */
	private $pages;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Prints a success.
	 *
	 * @var callable
	 */
	private $success;

	/**
	 * Stops with an error.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Reads a file; returns its content or false.
	 *
	 * @var callable
	 */
	private $read;

	/**
	 * Builds the command.
	 *
	 * @param Pages         $pages   The pages.
	 * @param callable|null $log     Default `WP_CLI::log`.
	 * @param callable|null $success Default `WP_CLI::success`.
	 * @param callable|null $fail    Default `WP_CLI::error`.
	 * @param callable|null $read    Reads a local file named on the command line; returns its content or false.
	 */
	public function __construct( Pages $pages, $log = null, $success = null, $fail = null, $read = null ) {
		$this->pages   = $pages;
		$this->log     = $log ?? array( 'WP_CLI', 'log' );
		$this->success = $success ?? array( 'WP_CLI', 'success' );
		$this->fail    = $fail ?? array( 'WP_CLI', 'error' );
		$this->read    = $read ?? static function ( $path ) {
			if ( ! is_readable( $path ) || is_dir( $path ) ) {
				return false;
			}

			$file = new \SplFileObject( $path, 'r' );
			$size = $file->getSize();

			return is_int( $size ) && $size > 0 ? (string) $file->fread( $size ) : '';
		};
	}

	/**
	 * Creates a wiki page.
	 *
	 * ## OPTIONS
	 *
	 * --space=<name>
	 * : The space of the page (one of those of Otherguise → Wiki).
	 *
	 * --title=<title>
	 * : The title; it is put through the rules of the names (the first letter in capitals, the underscore is a space).
	 *
	 * [--status=<status>]
	 * : `draft` (default), `pending`, `private` or `publish`.
	 *
	 * [--file=<path>]
	 * : A file of HTML (or of blocks) that is the content, kept as it is.
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise wiki create --space=Biography --title="1958-1968" --file=/tmp/1958-1968.html
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function create( $args, $assoc ) {
		unset( $args );

		$html = '';

		if ( isset( $assoc['file'] ) ) {
			$html = ( $this->read )( (string) $assoc['file'] );

			if ( false === $html ) {
				( $this->fail )( 'The file cannot be read: ' . $assoc['file'] );

				return;
			}
		}

		try {
			$id = $this->pages->create( $assoc['space'] ?? '', $assoc['title'] ?? '', (string) $html, (string) ( $assoc['status'] ?? 'draft' ) );
		} catch ( NameException $refusal ) {
			( $this->fail )( $refusal->getMessage() . ' (' . $refusal->error_code() . ( $refusal->holder() > 0 ? ', page ' . $refusal->holder() : '' ) . ')' );

			return;
		} catch ( \InvalidArgumentException $refusal ) {
			( $this->fail )( $refusal->getMessage() );

			return;
		}

		$name = $this->pages->names()->current_of( $id );

		( $this->success )( 'Page ' . $id . ' created: ' . ( null === $name ? '' : $name['qualified'] ) );
	}
}
