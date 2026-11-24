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
use Otherguise\Index\Cli\DesignationsCommand;
use Otherguise\Index\Designations;
use Otherguise\Index\Mentions;
use Otherguise\Index\Observations;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `wp otherguise index designations` (slice 626).
 */
final class Wiring {
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
		\WP_CLI::add_command(
			'otherguise index designations',
			new DesignationsCommand(
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
				)
			)
		);
	}
}
