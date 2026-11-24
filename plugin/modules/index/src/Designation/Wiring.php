<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The commands of the designations, built on WordPress.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Core\Modules;
use Otherguise\Index\Admin\TopicsScreen;
use Otherguise\Index\Cli\DesignationsCommand;
use Otherguise\Index\Cli\SuggestionsCommand;
use Otherguise\Index\Merge\DuplicateFinder;
use Otherguise\Index\Merge\IgnoredPairs;
use Otherguise\Index\Merge\MergeWiring;
use Otherguise\Index\Merge\TopicMerger;
use Otherguise\Index\Designations;
use Otherguise\Index\Mentions;
use Otherguise\Index\Observations;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `wp otherguise index designations`, `assign` and `suggestions` (slices 626 and 628), and builds the screen of the topics.
 */
final class Wiring {
	/**
	 * The screen of the topics and of their designations (slice 628), with the suggestions as one of its views.
	 *
	 * @param Topics       $topics       The topics.
	 * @param Mentions     $mentions     The mentions.
	 * @param Observations $observations The observations.
	 * @param Designations $designations The designations.
	 * @return TopicsScreen
	 */
	public static function screen( Topics $topics, Mentions $mentions, Observations $observations, Designations $designations ) {
		return new TopicsScreen(
			$topics,
			$designations,
			$observations,
			new Assigner( $designations, $observations, $mentions, $topics ),
			MergeWiring::screen( $topics, $mentions )
		);
	}

	/**
	 * Registers the command.
	 *
	 * @param Topics       $topics       The topics.
	 * @param Mentions     $mentions     The mentions.
	 * @param Observations $observations The observations.
	 * @param Designations $designations The designations.
	 * @return void
	 */
	public static function register_command( Topics $topics, Mentions $mentions, Observations $observations, Designations $designations ) {
		$command = new DesignationsCommand(
			$designations,
			$observations,
			$topics,
			new Assigner( $designations, $observations, $mentions, $topics ),
			new Cleaner( $designations, $observations ),
			new Migration(
				static function () {
						return Modules::get( 'triples' )->statements();
				},
				$designations,
				$observations
			),
			null,
			null,
			null,
			new TopicMerger( $topics, $mentions )
		);

		\WP_CLI::add_command( 'otherguise index designations', $command );
		\WP_CLI::add_command( 'otherguise index assign', array( $command, 'assign' ) );
		\WP_CLI::add_command( 'otherguise index suggestions', new SuggestionsCommand( new DuplicateFinder( $topics, array( new IgnoredPairs(), 'has' ) ) ) );
	}
}
