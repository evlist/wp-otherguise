<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI: the kinds of entries.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Kinds;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index kinds list|add|rename|css|move|delete` (slice 603). The commands that write can be run twice (the second time says why
 * nothing changed).
 */
final class KindsCommand {
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
	 * Builds the command.
	 *
	 * @param callable|null $log     Default `WP_CLI::log`.
	 * @param callable|null $success Default `WP_CLI::success`.
	 * @param callable|null $fail    Default `WP_CLI::error`.
	 */
	public function __construct( $log = null, $success = null, $fail = null ) {
		$this->log     = $log ?? array( 'WP_CLI', 'log' );
		$this->success = $success ?? array( 'WP_CLI', 'success' );
		$this->fail    = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Lists the kinds as a tree, with the CSS class, the number of terms and entries that have them, and whether a kind is a built-in kind
	 * (protected) or comes from code.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function list( $args, $assoc ) {
		unset( $args, $assoc );

		foreach ( Kinds::tree() as $row ) {
			$usage = Kinds::usage( $row['slug'] );
			$flags = array();

			if ( $row['builtin'] ) {
				$flags[] = 'built-in';
			}

			if ( 'code' === $row['source'] ) {
				$flags[] = 'code';
			}

			( $this->log )( str_repeat( '  ', (int) $row['depth'] ) . $row['slug'] . "\t" . $row['label'] . "\tindex-kind-" . $row['css'] . "\t" . $usage['terms'] . ' term(s), ' . $usage['entries'] . " entr.\t" . implode( ',', $flags ) );
		}
	}

	/**
	 * Adds a kind.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The identifier: lower-case letters, digits and hyphens, starting with a letter.
	 *
	 * --label=<label>
	 * : The name shown.
	 *
	 * [--parent=<slug>]
	 * : The broader kind (this one is a sort of it).
	 *
	 * [--css=<suffix>]
	 * : The suffix of the CSS class `index-kind-<suffix>`; the slug by default.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function add( $args, $assoc ) {
		$this->report( Kinds::add( (string) ( $args[0] ?? '' ), (string) ( $assoc['label'] ?? '' ), (string) ( $assoc['parent'] ?? '' ), (string) ( $assoc['css'] ?? '' ) ), 'Kind ' . ( $args[0] ?? '' ) . ' added.' );
	}

	/**
	 * Changes the label of a kind (a built-in kind too).
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The kind.
	 *
	 * <label>
	 * : The new label.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function rename( $args, $assoc ) {
		unset( $assoc );

		$this->report( Kinds::edit( (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ) ), 'Kind ' . ( $args[0] ?? '' ) . ' renamed.' );
	}

	/**
	 * Changes the CSS class of a kind: `index-kind-<suffix>`.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The kind.
	 *
	 * <suffix>
	 * : The suffix.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function css( $args, $assoc ) {
		unset( $assoc );

		$slug = (string) ( $args[0] ?? '' );
		$kind = Kinds::records()[ $slug ] ?? null;

		$result = null === $kind ? 'No such kind: ' . $slug . '.' : Kinds::edit( $slug, $kind['label'], (string) ( $args[1] ?? '' ) );

		$this->report( $result, 'The class of ' . $slug . ' is now index-kind-' . ( Kinds::records()[ $slug ]['css'] ?? '' ) . '.' );
	}

	/**
	 * Moves a kind under another (a built-in kind cannot be moved).
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The kind.
	 *
	 * [<parent>]
	 * : The new broader kind; none to make it a first-level kind.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function move( $args, $assoc ) {
		unset( $assoc );

		$this->report( Kinds::move( (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ) ), 'Kind ' . ( $args[0] ?? '' ) . ' moved.' );
	}

	/**
	 * Deletes a kind (not a built-in kind, not one that has sub-kinds; one that is used needs `--reassign`).
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The kind.
	 *
	 * [--reassign=<slug>]
	 * : The kind that takes over the terms and entries that have this one.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function delete( $args, $assoc ) {
		$this->report( Kinds::delete( (string) ( $args[0] ?? '' ), (string) ( $assoc['reassign'] ?? '' ) ), 'Kind ' . ( $args[0] ?? '' ) . ' deleted.' );
	}

	/**
	 * Prints the outcome.
	 *
	 * @param true|string $result  What the operation answered.
	 * @param string      $message The success message.
	 * @return void
	 */
	private function report( $result, $message ) {
		if ( true === $result ) {
			( $this->success )( $message );

			return;
		}

		( $this->fail )( (string) $result );
	}
}
