<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The command that cleans the topics nobody mentions.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Merge\TopicPruner;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index prune` (slice 619).
 */
final class PruneCommand {
	/**
	 * The pruner.
	 *
	 * @var TopicPruner
	 */
	private $pruner;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Reports a success.
	 *
	 * @var callable
	 */
	private $success;

	/**
	 * Builds the command.
	 *
	 * @param TopicPruner   $pruner  The pruner.
	 * @param callable|null $log     Default `WP_CLI::log`.
	 * @param callable|null $success Default `WP_CLI::success`.
	 */
	public function __construct( TopicPruner $pruner, $log = null, $success = null ) {
		$this->pruner  = $pruner;
		$this->log     = $log ?? array( 'WP_CLI', 'log' );
		$this->success = $success ?? array( 'WP_CLI', 'success' );
	}

	/**
	 * Lists, and with --apply deletes, the topics that no post mentions: the places that an older chain made and that reading the pages again no longer
	 * mentions. A topic stays when a post mentions it, when a topic below it stays, when a person chose its kind or its label, or when it is refused.
	 * **Nothing is deleted without --apply**; what is deleted is made again, with its keys, if a source names it again. Read the pages again first
	 * (`wp otherguise index pages start`).
	 *
	 * ## OPTIONS
	 *
	 * [--kind=<kinds>]
	 * : The kinds to clean, with their sub-kinds, separated by commas (default `place`; `any` for every kind).
	 *
	 * [--apply]
	 * : Delete them. Without it the command only lists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise index prune
	 *     wp otherguise index prune --kind=place,waterway --apply
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc ) {
		unset( $args );

		$kinds = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['kind'] ?? 'place' ) ) ), 'strlen' ) );
		$dry   = ! isset( $assoc['apply'] );
		$goes  = $this->pruner->prune( array() === $kinds ? array( 'place' ) : $kinds, $dry );

		foreach ( $goes as $topic ) {
			( $this->log )( $topic['id'] . "\t" . $topic['label'] . ' [' . $topic['kind'] . ']' );
		}

		( $this->success )( count( $goes ) . ' topic(s) ' . ( $dry ? 'that no post mentions: nothing deleted, add --apply to delete them.' : 'deleted.' ) );
	}
}
