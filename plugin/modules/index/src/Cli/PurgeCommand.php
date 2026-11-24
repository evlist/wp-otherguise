<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The command that drops what a source said.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Mentions;
use Otherguise\Index\Merge\TopicPruner;
use Otherguise\Index\Sources;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index purge --source=<path>` (slice 620).
 */
final class PurgeCommand {
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
	 * Reports a failure and stops.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Builds the command.
	 *
	 * @param Topics        $topics   The topics.
	 * @param Mentions      $mentions The mentions.
	 * @param TopicPruner   $pruner   The pruner.
	 * @param callable|null $log      Default `WP_CLI::log`.
	 * @param callable|null $success  Default `WP_CLI::success`.
	 * @param callable|null $fail     Default `WP_CLI::error`.
	 */
	public function __construct( Topics $topics, Mentions $mentions, TopicPruner $pruner, $log = null, $success = null, $fail = null ) {
		$this->topics   = $topics;
		$this->mentions = $mentions;
		$this->pruner   = $pruner;
		$this->log      = $log ?? array( 'WP_CLI', 'log' );
		$this->success  = $success ?? array( 'WP_CLI', 'success' );
		$this->fail     = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Drops what a source said: its observations and its mentions go (a mention that another source also makes stays, without this source; the designations
	 * stay, a new reading finds them again with what Wikidata said, and `wp otherguise index designations clean` or the next synchronisation deletes the ones that
	 * nothing observes), then **the topics that nothing
	 * mentions any more and that no topic below keeps** are deleted: the topics that the source made, and their broader topics that nothing else mentions.
	 * A topic that a person chose the kind or the label of, or that is refused, stays. **Nothing is changed without --apply.** Then make again what the
	 * source says: `wp otherguise index pages start` for the links, the marks and the photographs, `wp otherguise index rebuild` for the categories and
	 * the tags.
	 *
	 * ## OPTIONS
	 *
	 * --source=<path>
	 * : The source, which stands for its sub-sources: `image` (the photographs), `trace`, `post/link`, `post/mark`... (`wp otherguise index sources list`).
	 *
	 * [--apply]
	 * : Do it. Without it the command only says what it would do.
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise index purge --source=image
	 *     wp otherguise index purge --source=image --apply
	 *     wp otherguise index pages start
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc ) {
		unset( $args );

		$source = trim( (string) ( $assoc['source'] ?? '' ) );
		$dry    = ! isset( $assoc['apply'] );

		if ( '' === $source || ! Sources::is_source( $source ) ) {
			( $this->fail )( '--source: one of ' . implode( ', ', array_keys( Sources::registry() ) ) . '.' );

			return;
		}

		$before   = $this->mentions->mentioned_topics();
		$dropped  = $this->mentions->drop_source( $source, $dry );
		$orphaned = $dropped['orphaned'];
		$mention  = $dry ? array_diff_key( $before, array_flip( $orphaned ) ) : $this->mentions->mentioned_topics();
		$goes     = $this->pruner->prune( array( 'any' ), $dry, $this->with_ancestors( $orphaned ), $mention );

		foreach ( $goes as $topic ) {
			( $this->log )( $topic['id'] . "\t" . $topic['label'] . ' [' . $topic['kind'] . ']' );
		}

		( $this->success )(
			$dropped['mentions'] . ' mention(s) of the source ' . $source . ( $dry ? ' would be dropped, ' : ' dropped, ' ) . count( $goes ) . ' topic(s) ' . ( $dry ? 'would be deleted: nothing was changed, add --apply to do it.' : 'deleted.' )
		);
	}

	/**
	 * The topics and their broader topics.
	 *
	 * @param int[] $ids The topics.
	 * @return int[]
	 */
	private function with_ancestors( array $ids ) {
		$all = array();

		foreach ( $ids as $id ) {
			$topic = $this->topics->get( (int) $id );

			for ( $level = 0; null !== $topic && $level < 30; $level++ ) {
				$all[ (int) $topic['id'] ] = true;
				$topic                     = (int) $topic['parent'] > 0 ? $this->topics->get( (int) $topic['parent'] ) : null;
			}
		}

		return array_keys( $all );
	}
}
