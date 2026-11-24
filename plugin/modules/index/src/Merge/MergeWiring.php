<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The screen and the command of the merge, built on the real storage.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Merge;

use Otherguise\Index\Admin\DuplicatesScreen;
use Otherguise\Index\Cli\PruneCommand;
use Otherguise\Index\Cli\PurgeCommand;
use Otherguise\Index\Mentions;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Builds what the merge needs (slice 618): the finder that leaves out the pairs that were said not to be doubles, the merger, the screen and the commands.
 */
final class MergeWiring {
	/**
	 * The screen of the topics that may be the same.
	 *
	 * @param Topics   $topics   The topics.
	 * @param Mentions $mentions The mentions.
	 * @return DuplicatesScreen
	 */
	public static function screen( Topics $topics, Mentions $mentions ) {
		$ignored = new IgnoredPairs();

		return new DuplicatesScreen(
			new DuplicateFinder( $topics, array( $ignored, 'has' ) ),
			new TopicMerger( $topics, $mentions ),
			$ignored,
			$topics,
			static function ( $id ) use ( $mentions ) {
				$of = $mentions->of_topic( (int) $id );

				return array(
					'posts'   => count( $of ),
					'sources' => array_values( array_unique( array_merge( array(), ...array_column( $of, 'sources' ) ) ) ),
				);
			}
		);
	}

	/**
	 * Registers the commands `merge`, `duplicates`, `prune` and `purge`.
	 *
	 * @param Topics   $topics   The topics.
	 * @param Mentions $mentions The mentions.
	 * @return void
	 */
	public static function register_commands( Topics $topics, Mentions $mentions ) {
		\WP_CLI::add_command( 'otherguise index prune', new PruneCommand( new TopicPruner( $topics, $mentions ) ) );
		\WP_CLI::add_command( 'otherguise index purge', new PurgeCommand( $topics, $mentions, new TopicPruner( $topics, $mentions ) ) );
	}
}
