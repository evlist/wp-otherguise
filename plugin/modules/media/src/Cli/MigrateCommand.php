<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI command `wp otherguise media migrate`.
 *
 * @package Otherguise
 */

namespace Otherguise\Media\Cli;

use Otherguise\Media\Migration\NativeLinksMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the parents of the attachments and the flag `wpdfh.print` into links usable by the method `otherguise` of Media Helper.
 */
final class MigrateCommand {
	/**
	 * Builds the migration.
	 *
	 * @var callable
	 */
	private $migration;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Stops with an error message.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Builds the command.
	 *
	 * @param callable      $migration Returns the NativeLinksMigration, or null when the modules are not available.
	 * @param callable|null $log       Prints a line; defaults to `WP_CLI::log`.
	 * @param callable|null $fail      Stops with a message; defaults to `WP_CLI::error`.
	 */
	public function __construct( $migration, $log = null, $fail = null ) {
		$this->migration = $migration;
		$this->log       = $log ?? array( 'WP_CLI', 'log' );
		$this->fail      = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Creates a link `media/illustrated-by` for every attachment that has a parent and no link yet.
	 *
	 * Nothing is changed or deleted on the attachments (neither `post_parent` nor the flag `wpdfh.print`) and links that exist are left
	 * alone, so the command can be run again. A flagged image is linked in every mode, an image that is not flagged in every mode but
	 * the print mode, and any other file in every mode.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Write nothing, say what would be done.
	 *
	 * [--print-mode=<slug>]
	 * : The mode to which the flag `wpdfh.print` applies.
	 * ---
	 * default: print
	 * ---
	 *
	 * [--batch-size=<n>]
	 * : Attachments read at a time.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--limit=<n>]
	 * : Stop after that many links.
	 *
	 * [--verbose]
	 * : One line per attachment.
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$migration = ( $this->migration )();

		if ( ! $migration instanceof NativeLinksMigration ) {
			( $this->fail )( 'The modules Triples, Modes and Media must be active.' );
			return;
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );
		$report  = $migration->run(
			array(
				'dry_run'    => $dry_run,
				'print_mode' => isset( $assoc_args['print-mode'] ) ? (string) $assoc_args['print-mode'] : 'print',
				'batch_size' => isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 100,
				'limit'      => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0,
			),
			! empty( $assoc_args['verbose'] ) ? array( $this, 'line' ) : null
		);

		if ( $report['print_mode_missing'] ) {
			$this->line( 'Warning: the print mode is not registered, so every attachment gets every registered mode.' );
		}

		foreach ( $report['by_modes'] as $modes => $count ) {
			$this->line( sprintf( '  %d in %s', $count, '' === $modes ? 'no mode' : $modes ) );
		}

		foreach ( $report['skipped'] as $skipped ) {
			$this->line( 'Skipped, ' . $skipped );
		}

		$this->line(
			sprintf(
				'%s: %d link(s) %s, %d already linked, %d skipped.',
				$dry_run ? 'Dry run, nothing written' : 'Done',
				$report['created'],
				$dry_run ? 'to create' : 'created',
				$report['existing'],
				count( $report['skipped'] )
			)
		);
	}

	/**
	 * Prints a line.
	 *
	 * @param string $text Text.
	 * @return void
	 */
	public function line( $text ) {
		( $this->log )( $text );
	}
}
