<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The commands of the motifs, built on WordPress.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Core\Modules;
use Otherguise\Index\Admin\AssignmentsScreen;
use Otherguise\Index\Admin\AssignmentsTable;
use Otherguise\Index\Admin\TopicsScreen;
use Otherguise\Index\Cli\DesignationsCommand;
use Otherguise\Index\Cli\SuggestionsCommand;
use Otherguise\Index\Merge\DuplicateFinder;
use Otherguise\Index\Merge\IgnoredPairs;
use Otherguise\Index\Merge\MergeWiring;
use Otherguise\Index\Merge\TopicMerger;
use Otherguise\Index\Rest\AssignmentRoute;
use Otherguise\Index\Rest\TopicsRoute;
use Otherguise\Index\Designations;
use Otherguise\Index\Resolver;
use Otherguise\Index\Mentions;
use Otherguise\Index\Observations;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `wp otherguise index motifs`, `assign` and `suggestions` (slices 626 and 628), and builds the screen of the topics.
 */
final class Wiring {
	/**
	 * Registers the screens of the administration (the topics, the assignments) and the route that finds topics (slices 628 and 630).
	 *
	 * @param Topics       $topics       The topics.
	 * @param Mentions     $mentions     The mentions.
	 * @param Observations $observations The observations.
	 * @param Designations $designations The motifs.
	 * @param callable     $add_action   `add_action`.
	 * @return void
	 */
	public static function register_admin( Topics $topics, Mentions $mentions, Observations $observations, Designations $designations, $add_action ) {
		$assigner = new Assigner( $designations, $observations, $mentions, $topics );

		( new TopicsScreen( $topics, $designations, $observations, $assigner, MergeWiring::screen( $topics, $mentions ) ) )->register( $add_action );
		( new AssignmentsScreen(
			$designations,
			$observations,
			$topics,
			$assigner,
			$mentions,
			new Reresolver( $designations, $observations, $mentions, new Resolver( $topics ), $topics ),
			static fn() => self::table( $designations, $observations, $topics ),
			static function ( $file ) {
				return plugins_url( 'modules/index/admin/' . $file, OTHERGUISE_PLUGIN_FILE );
			}
		) )->register( $add_action );
	}

	/**
	 * Registers the routes of the screen of the assignments (outside the administration: a request of the REST API is not one): the search of the topics
	 * (slice 630) and the quick edit of one motif (slice 633).
	 *
	 * @param Topics       $topics       The topics.
	 * @param Mentions     $mentions     The mentions.
	 * @param Observations $observations The observations.
	 * @param Designations $designations The motifs.
	 * @param callable     $add_action   `add_action`.
	 * @return void
	 */
	public static function register_rest( Topics $topics, Mentions $mentions, Observations $observations, Designations $designations, $add_action ) {
		$finder = new TopicFinder( $topics );

		$add_action( 'rest_api_init', array( new TopicsRoute( $finder ), 'register' ), 10, 0 );
		$add_action(
			'rest_api_init',
			array(
				new AssignmentRoute(
					$designations,
					$topics,
					new Assigner( $designations, $observations, $mentions, $topics ),
					new Neighbours( $designations, $observations, $topics, $finder ),
					$finder
				),
				'register',
			),
			10,
			0
		);
	}

	/**
	 * The table of the assignments: the class of WordPress that it extends is loaded first.
	 *
	 * @param Designations $designations The motifs.
	 * @param Observations $observations The observations.
	 * @param Topics       $topics       The topics.
	 * @return AssignmentsTable
	 */
	private static function table( Designations $designations, Observations $observations, Topics $topics ) {
		if ( ! class_exists( 'WP_List_Table', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		return new AssignmentsTable(
			new Listing( $designations, $observations, $topics ),
			$topics,
			static function ( $name ) {
				$value = filter_input( INPUT_GET, $name );

				return is_string( $value ) ? $value : '';
			},
			static function ( array $args ) {
				return add_query_arg( array_merge( array( 'page' => AssignmentsScreen::PAGE ), $args ), admin_url( 'admin.php' ) );
			}
		);
	}

	/**
	 * Registers the command.
	 *
	 * @param Topics       $topics       The topics.
	 * @param Mentions     $mentions     The mentions.
	 * @param Observations $observations The observations.
	 * @param Designations $designations The motifs.
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
			new TopicMerger( $topics, $mentions ),
			new Reresolver( $designations, $observations, $mentions, new Resolver( $topics ), $topics )
		);

		\WP_CLI::add_command( 'otherguise index motifs', $command );
		\WP_CLI::add_command( 'otherguise index assign', array( $command, 'assign' ) );
		\WP_CLI::add_command( 'otherguise index suggestions', new SuggestionsCommand( new DuplicateFinder( $topics, array( new IgnoredPairs(), 'has' ) ) ) );
	}
}
