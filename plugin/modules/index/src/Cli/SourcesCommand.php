<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI: the sources of the mentions.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Mentions;
use Otherguise\Index\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index sources list|migrate|check` (slice 605): the hierarchy of the sources, the move from the flat names of slices 600 to
 * 602 to the paths, and a check of what the mentions hold.
 */
final class SourcesCommand {
	/**
	 * The old names of the sources and what they became: `categories` and `tags` are renamed; `page` is split in two sources (`post/link`
	 * and `post/mark`), so it is dropped and made again from the signals that were stored.
	 */
	public const LEGACY = array(
		'categories' => Sources::CATEGORY,
		'tags'       => Sources::TAG,
		'page'       => '',
	);

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * Returns the modes that exist, or null when it is not known: `fn(): ?string[]`.
	 *
	 * @var callable
	 */
	private $known_modes;

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
	 * Builds the command.
	 *
	 * @param Mentions      $mentions    The mentions.
	 * @param callable|null $known_modes The modes that exist (default the filter `index_known_modes`, `null` when nobody answers).
	 * @param callable|null $log         Default `WP_CLI::log`.
	 * @param callable|null $success     Default `WP_CLI::success`.
	 */
	public function __construct( Mentions $mentions, $known_modes = null, $log = null, $success = null ) {
		$this->mentions    = $mentions;
		$this->known_modes = $known_modes ?? static function () {
			$modes = apply_filters( 'index_known_modes', null );

			return is_array( $modes ) ? array_values( array_map( 'strval', $modes ) ) : null;
		};
		$this->log         = $log ?? array( 'WP_CLI', 'log' );
		$this->success     = $success ?? array( 'WP_CLI', 'success' );
	}

	/**
	 * Prints the sources as a tree, with the modes in which a source shows what it finds when it is not every mode.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function list( $args, $assoc ) {
		unset( $args, $assoc );

		foreach ( Sources::tree() as $row ) {
			( $this->log )( str_repeat( '  ', (int) $row['depth'] ) . $row['path'] . "\t" . $row['label'] . ( array() === $row['visible'] ? '' : "\tshown in: " . implode( ', ', $row['visible'] ) ) );
		}
	}

	/**
	 * Gives the existing mentions the new names of the sources: `categories` becomes `post/category`, `tags` becomes `post/tag`, and `page` is
	 * dropped (it is two sources now): run `wp otherguise index rebuild --source=page` afterwards, which makes them again from the stored signals,
	 * with no request. Can be run twice.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function migrate( $args, $assoc ) {
		unset( $args, $assoc );

		$total = 0;

		foreach ( self::LEGACY as $old => $new ) {
			$done   = $this->mentions->rename_source( $old, $new );
			$total += $done;

			( $this->log )( $old . ' => ' . ( '' === $new ? '(dropped)' : $new ) . ': ' . $done );
		}

		( $this->success )( $total . ' qualification(s) changed.' . ( $total > 0 ? ' Run `wp otherguise index rebuild --source=page` to make the mentions of the pages again.' : '' ) );
	}

	/**
	 * Lists the sources that the mentions hold, flags those that are not in the registry (an old name, a plugin that is gone), and the modes of the
	 * visibilities (flagged when the module that knows the modes does not know one).
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function check( $args, $assoc ) {
		unset( $args, $assoc );

		$problems = 0;

		foreach ( $this->mentions->sources_in_use() as $source => $count ) {
			$known = Sources::is_source( $source );

			$problems += $known ? 0 : 1;

			( $this->log )( $source . "\t" . $count . ' mention(s)' . ( $known ? '' : "\tNOT IN THE REGISTRY" . ( isset( self::LEGACY[ $source ] ) ? ' (an old name: wp otherguise index sources migrate)' : '' ) ) );
		}

		$modes = ( $this->known_modes )();

		foreach ( $this->mentions->modes_in_use() as $mode => $count ) {
			$unknown   = null !== $modes && ! in_array( $mode, $modes, true );
			$problems += $unknown ? 1 : 0;

			( $this->log )( 'mode ' . $mode . "\t" . $count . ' value(s)' . ( $unknown ? "\tUNKNOWN MODE" : '' ) );
		}

		( $this->success )( 0 === $problems ? 'Nothing to correct.' : $problems . ' thing(s) to correct.' );
	}
}
