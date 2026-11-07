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
use Otherguise\Wiki\Tree\Angles;
use Otherguise\Wiki\Tree\Citations;
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
	 * The other angles, or null.
	 *
	 * @var Angles|null
	 */
	private $angles;

	/**
	 * The index of the links, or null.
	 *
	 * @var Citations|null
	 */
	private $citations;

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
	 * @param Pages          $pages   The pages.
	 * @param callable|null  $log     Default `WP_CLI::log`.
	 * @param callable|null  $success Default `WP_CLI::success`.
	 * @param callable|null  $fail    Default `WP_CLI::error`.
	 * @param callable|null  $read    Reads a local file named on the command line; returns its content or false.
	 * @param Citations|null $citations The index of the links (slice 506).
	 * @param Angles|null    $angles    The other angles (slice 507).
	 */
	public function __construct( Pages $pages, $log = null, $success = null, $fail = null, $read = null, $citations = null, $angles = null ) {
		$this->pages     = $pages;
		$this->angles    = $angles;
		$this->citations = $citations;
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
	 * Moves a page under another parent and/or renames it; the old address becomes an alias that redirects.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The page.
	 *
	 * [--parent=<id>]
	 * : The new parent (0 for the first level).
	 *
	 * [--path=<titles>]
	 * : The new parent as a path of titles (`Biography/1958-1968`); wins over `--parent`.
	 *
	 * [--title=<title>]
	 * : The new title; default: the title stays.
	 *
	 * [--dry-run]
	 * : Checks and reports, writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise tree move 42 --path=Opinion --dry-run
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function move( $args, $assoc ) {
		$id     = (int) ( $args[0] ?? 0 );
		$page   = $this->pages->nodes()->get( $id );
		$parent = isset( $assoc['parent'] ) ? (int) $assoc['parent'] : ( null === $page ? 0 : $page['parent'] );

		if ( isset( $assoc['path'] ) ) {
			$titles = '' === trim( (string) $assoc['path'], '/ ' ) ? array() : explode( '/', trim( (string) $assoc['path'], '/ ' ) );
			$found  = array() === $titles ? array(
				'post' => 0,
			) : $this->pages->find( $titles );

			if ( null === $found['post'] ) {
				( $this->fail )( 'The parent does not exist: ' . $assoc['path'] );

				return;
			}

			$parent = (int) $found['post'];
		}

		$dry = ! empty( $assoc['dry-run'] );

		try {
			$key = $this->pages->move( $id, $parent, $assoc['title'] ?? null, ! $dry );
		} catch ( NameException $refusal ) {
			( $this->fail )( $refusal->getMessage() . ' (' . $refusal->error_code() . ( $refusal->holder() > 0 ? ', page ' . $refusal->holder() : '' ) . ')' );

			return;
		}

		if ( $dry ) {
			( $this->success )( 'Page ' . $id . ' can be moved (dry run, nothing written).' );

			return;
		}

		$path = $this->pages->keys()->path_of( $id );

		( $this->success )( null === $key ? 'Page ' . $id . ' is already there.' : 'Page ' . $id . ' moved: ' . ( null === $path ? '' : implode( '/', $path ) ) );
	}

	/**
	 * Lists the wanted pages: the paths that pages link to and that nobody has yet, the most wanted first.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function wanted( $args, $assoc ) {
		unset( $args, $assoc );

		if ( null === $this->citations ) {
			( $this->fail )( 'The index of the links is not available.' );

			return;
		}

		$wanted = $this->citations->wanted();

		foreach ( $wanted as $path => $from ) {
			( $this->log )( '/' . $path . ' (wanted by ' . count( $from ) . ': ' . implode( ', ', $from ) . ')' );
		}

		( $this->success )( count( $wanted ) . ' wanted page(s).' );
	}

	/**
	 * Declares two pages the same subject (symmetric), or takes the declaration back.
	 *
	 * ## OPTIONS
	 *
	 * <a>
	 * : A page.
	 *
	 * <b>
	 * : Another page.
	 *
	 * [--remove]
	 * : Takes the declaration back.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function relate( $args, $assoc ) {
		if ( null === $this->angles ) {
			( $this->fail )( 'The angles are not available.' );

			return;
		}

		$a = (int) ( $args[0] ?? 0 );
		$b = (int) ( $args[1] ?? 0 );

		if ( ! empty( $assoc['remove'] ) ) {
			( $this->success )( $this->angles->unrelate( $a, $b ) ? 'The declaration is removed.' : 'There was none.' );

			return;
		}

		if ( ! $this->angles->relate( $a, $b ) ) {
			( $this->fail )( 'Both must be different pages of the tree.' );

			return;
		}

		( $this->success )( 'Pages ' . $a . ' and ' . $b . ' are the same subject.' );
	}

	/**
	 * Reads every page again and brings the index of the links in line with the content. Can be run twice.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Counts the pages whose statements would change, writes nothing.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function links( $args, $assoc ) {
		unset( $args );

		if ( null === $this->citations ) {
			( $this->fail )( 'The index of the links is not available.' );

			return;
		}

		$dry    = ! empty( $assoc['dry-run'] );
		$report = $this->citations->rebuild( $this->pages->nodes()->contents(), ! $dry );

		( $this->success )( $report['pages'] . ' page(s) read, ' . $report['changed'] . ' ' . ( $dry ? 'would change (dry run).' : 'changed.' ) );
	}

	/**
	 * Makes the keys again from the pages of the site: the current keys are deleted and made again, the aliases of the pages that exist are kept
	 * (those of pages that no longer exist are removed, and so are the link statements `wiki/cites`, `wiki/wants` and `wiki/same-subject` of pages
	 * that no longer exist: `wp site empty` truncates the tables and leaves them), and the pages that cannot have their key are listed.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function rebuild( $args, $assoc ) {
		unset( $args, $assoc );

		$pages  = $this->pages->nodes()->all();
		$report = $this->pages->keys()->rebuild( $pages );
		$ids    = array_column( $pages, 'post' );
		$links  = ( null === $this->citations ? 0 : $this->citations->purge( $ids ) ) + ( null === $this->angles ? 0 : $this->angles->purge( $ids ) );

		foreach ( $report['refused'] as $page ) {
			( $this->log )( 'Page ' . $page['post'] . ' refused: ' . $page['code'] . ( $page['holder'] > 0 ? ', the key is held by page ' . $page['holder'] : '' ) );
		}

		( $this->success )( $report['done'] . ' key(s) made, ' . count( $report['refused'] ) . ' page(s) refused, ' . $report['orphans'] . ' alias(es) and ' . $links . ' link statement(s) of vanished pages removed.' );
	}
}
