<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The command that starts the index again from nothing.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Mentions;
use Otherguise\Index\Merge\TopicPruner;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index reset [--apply] [--everything]` (slice 623): what `purge` of each source and `prune` do together, and what a read leaves behind
 * (the state of the reading, of the enrichment, of the places that wait), so that the next `wp otherguise index sync` starts from nothing.
 */
final class ResetCommand {
	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * The pruner.
	 *
	 * @var TopicPruner
	 */
	private $pruner;

	/**
	 * Stops the synchronisation that is going.
	 *
	 * @var callable
	 */
	private $stop;

	/**
	 * Forgets what the reads left behind: `fn( bool $everything ): void`.
	 *
	 * @var callable
	 */
	private $forget;

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
	 * @param Topics        $topics   The topics.
	 * @param Mentions      $mentions The mentions.
	 * @param TopicPruner   $pruner   The pruner.
	 * @param callable      $stop     Stops the synchronisation.
	 * @param callable      $forget   Forgets the state of the reads.
	 * @param callable|null $log      Default `WP_CLI::log`.
	 * @param callable|null $success  Default `WP_CLI::success`.
	 */
	public function __construct( Topics $topics, Mentions $mentions, TopicPruner $pruner, $stop, $forget, $log = null, $success = null ) {
		$this->topics   = $topics;
		$this->mentions = $mentions;
		$this->pruner   = $pruner;
		$this->stop     = $stop;
		$this->forget   = $forget;
		$this->log      = $log ?? array( 'WP_CLI', 'log' );
		$this->success  = $success ?? array( 'WP_CLI', 'success' );
	}

	/**
	 * Starts the index again from nothing, on a test site: the mentions of every source (the pages, the tags and categories, the photographs, the tracks)
	 * are dropped, then the topics that nothing mentions any more are deleted, and the state of the readings is forgotten (what was read, what waits for a
	 * place, the enrichment, the synchronisation that was going). **Nothing is changed without --apply.** What a person decided stays by default: the topics
	 * whose kind or label was chosen, the refused ones and the candidates, the mentions made by hand, the kinds and the list of the pairs that are not doubles.
	 * Then: `wp otherguise index sync --from-scratch`.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Do it. Without it the command only says what it would do.
	 *
	 * [--everything]
	 * : Also delete what a person decided: every topic, every mention (those made by hand too), and the list of the pairs that are not doubles. The kinds stay.
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise index reset
	 *     wp otherguise index reset --apply
	 *     wp otherguise index sync start --from-scratch --foreground
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc ) {
		unset( $args );

		$dry        = ! isset( $assoc['apply'] );
		$everything = isset( $assoc['everything'] );
		$sources    = array( 'post', 'image', 'trace' );

		if ( ! $dry ) {
			( $this->stop )();
		}

		if ( $everything ) {
			$dropped = $this->mentions->drop_all( $dry );
		} else {
			$dropped = 0;

			foreach ( $sources as $source ) {
				$dropped += (int) $this->mentions->drop_source( $source, $dry )['mentions'];
			}
		}

		// A dry run says what would stay by what is mentioned beyond the sources that would be dropped; the real run asks the database.
		$mentioned = $dry ? ( $everything ? array() : $this->mentions->mentioned_beyond( $sources ) ) : null;
		$goes      = $this->pruner->prune( array( 'any' ), $dry, null, $mentioned, ! $everything );

		if ( ! $dry ) {
			( $this->forget )( $everything );
		}

		foreach ( $goes as $topic ) {
			( $this->log )( $topic['id'] . "\t" . $topic['label'] . ' [' . $topic['kind'] . ']' );
		}

		( $this->success )(
			$dropped . ' mention(s) ' . ( $dry ? 'would be dropped, ' : 'dropped, ' ) . count( $goes ) . ' topic(s) ' . ( $dry ? 'would be deleted' : 'deleted' ) . ', the state of the readings ' . ( $dry ? 'would be forgotten: nothing was changed, add --apply to do it.' : 'forgotten. Now: wp otherguise index sync start --from-scratch --foreground' )
		);
	}
}
