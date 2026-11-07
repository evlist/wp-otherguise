<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI: the pages of the tree.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Cli;

use Otherguise\Wiki\NameException;
use Otherguise\Wiki\Tree\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise tree create` and `rebuild` (slice 502): the same service as the screens and the migration. The content is given as HTML and
 * kept as it is: nothing here imports or knows MediaWiki. The command is called `tree` while the command of the first model, `wiki`, exists.
 */
final class TreeCommand {
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
	 * Creates a page under a parent.
	 *
	 * ## OPTIONS
	 *
	 * --title=<title>
	 * : The title; it is put through the rules of the titles (the underscore is a space, no slash, the case is kept).
	 *
	 * [--parent=<id>]
	 * : The id of the parent page; none for the first level.
	 *
	 * [--path=<titles>]
	 * : The parent as the path of its titles, separated by slashes (`Biography/1958-1968`); instead of `--parent`.
	 *
	 * [--status=<status>]
	 * : `draft` (default), `pending`, `private` or `publish`.
	 *
	 * [--file=<path>]
	 * : A file of HTML (or of blocks) that is the content, kept as it is.
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise tree create --path=Biography --title="1958-1968" --file=/tmp/1958-1968.html
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

		$parent = (int) ( $assoc['parent'] ?? 0 );

		if ( isset( $assoc['path'] ) && '' !== trim( (string) $assoc['path'], '/ ' ) ) {
			$found = $this->pages->find( explode( '/', trim( (string) $assoc['path'], '/ ' ) ) );

			if ( null === $found['post'] ) {
				( $this->fail )( 'The parent does not exist: ' . $assoc['path'] . ' (the last page that exists is ' . $found['deepest'] . ')' );

				return;
			}

			$parent = $found['post'];
		}

		try {
			$id = $this->pages->create( $parent, $assoc['title'] ?? '', (string) $html, (string) ( $assoc['status'] ?? 'draft' ) );
		} catch ( NameException $refusal ) {
			( $this->fail )( $refusal->getMessage() . ' (' . $refusal->error_code() . ( $refusal->holder() > 0 ? ', page ' . $refusal->holder() : '' ) . ')' );

			return;
		} catch ( \InvalidArgumentException $refusal ) {
			( $this->fail )( $refusal->getMessage() );

			return;
		}

		$path = $this->pages->keys()->path_of( $id );

		( $this->success )( 'Page ' . $id . ' created: ' . ( null === $path ? '' : implode( '/', $path ) ) );
	}

	/**
	 * Makes the keys again from the pages of the site: the current keys are deleted and made again, the aliases are kept, and the pages that
	 * cannot have their key are listed.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function rebuild( $args, $assoc ) {
		unset( $args, $assoc );

		$report = $this->pages->keys()->rebuild( $this->pages->nodes()->all() );

		foreach ( $report['refused'] as $page ) {
			( $this->log )( 'Page ' . $page['post'] . ' refused: ' . $page['code'] . ( $page['holder'] > 0 ? ', the key is held by page ' . $page['holder'] : '' ) );
		}

		( $this->success )( $report['done'] . ' key(s) made, ' . count( $report['refused'] ) . ' page(s) refused.' );
	}
}
