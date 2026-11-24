<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The command that lists the topics that may be the same.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Merge\DuplicateFinder;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index suggestions` (slices 616 and 628): the pairs of topics that may be the same, for a person to look at. **Nothing is changed**; the
 * answer to a suggestion is `wp otherguise index assign` (or the screen Otherguise → Topics).
 */
final class SuggestionsCommand {
	/**
	 * The finder.
	 *
	 * @var DuplicateFinder
	 */
	private $finder;

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
	 * @param DuplicateFinder $finder  The finder.
	 * @param callable|null   $log     Default `WP_CLI::log`.
	 * @param callable|null   $success Default `WP_CLI::success`.
	 */
	public function __construct( DuplicateFinder $finder, $log = null, $success = null ) {
		$this->finder  = $finder;
		$this->log     = $log ?? array( 'WP_CLI', 'log' );
		$this->success = $success ?? array( 'WP_CLI', 'success' );
	}

	/**
	 * Lists the pairs of topics that may be the same: the same label once what is in parentheses is left out, or labels that differ by a letter or two
	 * under the same parent. Homonyms under two parents are not offered.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : At most this many pairs (default 100).
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc ) {
		unset( $args );

		$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 100;
		$pairs = $this->finder->pairs();

		foreach ( array_slice( $pairs, 0, $limit ) as $pair ) {
			( $this->log )( $pair['a']['id'] . "\t" . $pair['a']['label'] . ' [' . $pair['a']['kind'] . ', under ' . $pair['a']['parent'] . "]\t" . $pair['b']['id'] . "\t" . $pair['b']['label'] . ' [' . $pair['b']['kind'] . ', under ' . $pair['b']['parent'] . "]\t" . $pair['why'] );
		}

		( $this->success )( count( $pairs ) . ' pair(s)' . ( count( $pairs ) > $limit ? ', ' . $limit . ' shown' : '' ) . '. Answer with: wp otherguise index assign --topic=<id> --from-topic=<id> --with-sub-topics' );
	}
}
