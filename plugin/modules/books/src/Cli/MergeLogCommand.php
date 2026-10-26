<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI command `wp otherguise books merge-log`.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Cli;

use Otherguise\Books\Merge\MergeLog;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the log of the merge of the sections (slice 231). The log is off until `wp option update books_merge_log 1`.
 */
final class MergeLogCommand {
	/**
	 * The log.
	 *
	 * @var MergeLog
	 */
	private $merge_log;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Prints a success message.
	 *
	 * @var callable
	 */
	private $success;

	/**
	 * Builds the command.
	 *
	 * @param MergeLog      $merge_log The log.
	 * @param callable|null $log       Default `WP_CLI::log`.
	 * @param callable|null $success   Default `WP_CLI::success`.
	 */
	public function __construct( MergeLog $merge_log, $log = null, $success = null ) {
		$this->merge_log = $merge_log;
		$this->log       = $log ?? array( 'WP_CLI', 'log' );
		$this->success   = $success ?? array( 'WP_CLI', 'success' );
	}

	/**
	 * Prints the last lines of the log of the merge, its path, or empties it.
	 *
	 * One JSON line per event: `merge_start`, `unit_start`, `unit`, `merge_done`, `merge_aborted`, `pdf_convert`, with the seconds, the SQL
	 * queries and the memory. The log is off by default: `wp option update books_merge_log 1` turns it on, `wp option delete books_merge_log`
	 * off.
	 *
	 * ## OPTIONS
	 *
	 * [--lines=<n>]
	 * : The number of lines to print.
	 * ---
	 * default: 40
	 * ---
	 *
	 * [--show-path]
	 * : Print the path of the file instead of its lines (not `--path`: WP-CLI keeps it for the path of WordPress).
	 *
	 * [--clear]
	 * : Empty the log.
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		if ( isset( $assoc_args['clear'] ) ) {
			$this->merge_log->clear();
			( $this->success )( 'The log is empty.' );
			return;
		}

		if ( isset( $assoc_args['show-path'] ) ) {
			( $this->log )( $this->merge_log->path() );
			return;
		}

		foreach ( $this->merge_log->lines( (int) ( $assoc_args['lines'] ?? 40 ) ) as $line ) {
			( $this->log )( $line );
		}
	}
}
