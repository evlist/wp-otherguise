<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The commands `wp otherguise index photos`.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Photo\PlaceChain;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index photos enable|disable|run|status` (slice 606): the photographs and the GPX tracks of the pages as sources of places.
 */
final class PhotosCommand {
	/**
	 * Turns the source on or off: `fn( bool $on ): true`.
	 *
	 * @var callable
	 */
	private $set_source;

	/**
	 * Reads a post and indexes it (or, dry, explains it): `fn( int $post, bool $dry ): array{ok:bool,message:string,analysis?:array}`.
	 *
	 * @var callable
	 */
	private $one;

	/**
	 * The second pass: `fn(): int` (posts looked at).
	 *
	 * @var callable
	 */
	private $second_pass;

	/**
	 * The status: `fn(): array{posts:int,urls:int,left_out:array}`.
	 *
	 * @var callable
	 */
	private $status;

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
	 * @param callable      $set_source  Turns the source on or off.
	 * @param callable      $one         Reads one post.
	 * @param callable      $second_pass The second pass.
	 * @param callable      $status      The status.
	 * @param callable|null $log         Default `WP_CLI::log`.
	 * @param callable|null $success     Default `WP_CLI::success`.
	 * @param callable|null $fail        Default `WP_CLI::error`.
	 */
	public function __construct( $set_source, $one, $second_pass, $status, $log = null, $success = null, $fail = null ) {
		$this->set_source  = $set_source;
		$this->one         = $one;
		$this->second_pass = $second_pass;
		$this->status      = $status;
		$this->log         = $log ?? array( 'WP_CLI', 'log' );
		$this->success     = $success ?? array( 'WP_CLI', 'success' );
		$this->fail        = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Turns the source on (the job that reads the pages must be on too: `wp otherguise index pages enable`).
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Unused.
	 * @return void
	 */
	public function enable( $args, $assoc ) {
		unset( $args, $assoc );
		( $this->set_source )( true );
		( $this->success )( 'The photographs and the tracks are a source of places, from the next reading of the pages.' );
	}

	/**
	 * Turns the source off.
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Unused.
	 * @return void
	 */
	public function disable( $args, $assoc ) {
		unset( $args, $assoc );
		( $this->set_source )( false );
		( $this->success )( 'The photographs and the tracks are no longer a source (the mentions stay until the next `index rebuild`).' );
	}

	/**
	 * Reads the page of a post and indexes its photographs and tracks, or, with no post, makes the second pass (the posts that have files without a place
	 * ask Media Helper again, with no network).
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : The post to read.
	 *
	 * [--dry-run]
	 * : With --post, write nothing and print the places of the photographs (one line per place, with its number of photographs) and what is left out.
	 *
	 * [--verbose]
	 * : With --dry-run, also print, under each place, the levels of its place as Media Helper gave them (the numbered administrative levels included) and each of its photographs: the time, the position and the address.
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function run( $args, $assoc ) {
		unset( $args );

		if ( ! isset( $assoc['post'] ) ) {
			( $this->success )( ( $this->second_pass )() . ' post(s) looked at again.' );
			return;
		}

		$done = ( $this->one )( (int) $assoc['post'], isset( $assoc['dry-run'] ) );

		if ( ! $done['ok'] ) {
			( $this->fail )( $done['message'] );
			return;
		}

		foreach ( (array) ( $done['analysis']['places'] ?? array() ) as $number => $place ) {
			( $this->log )( 'place ' . ( $number + 1 ) . ': ' . count( $place['photos'] ) . ' photograph(s), ' . self::name( $place['place'] ) );

			if ( isset( $assoc['verbose'] ) ) {
				self::details( $place, $this->log );
			}
		}

		foreach ( (array) ( $done['analysis']['left_out'] ?? array() ) as $reason => $count ) {
			if ( $count > 0 ) {
				( $this->log )( 'left out, ' . str_replace( '_', ' ', $reason ) . ': ' . $count );
			}
		}

		( $this->success )( $done['message'] );
	}

	/**
	 * Says how many posts wait for a place and what was left out.
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Unused.
	 * @return void
	 */
	public function status( $args, $assoc ) {
		unset( $args, $assoc );

		$status = ( $this->status )();

		( $this->log )( $status['posts'] . ' post(s) wait for a place, ' . $status['urls'] . ' file(s).' );

		foreach ( $status['left_out'] as $reason => $count ) {
			( $this->log )( 'left out, ' . str_replace( '_', ' ', $reason ) . ': ' . $count );
		}

		( $this->success )( 'Done.' );
	}

	/**
	 * What lies behind a place, for the one who looks for a wrong place: the levels of its place as Media Helper gave them, then its photographs with
	 * their time, their position and their address, in the order of the time.
	 *
	 * @param array    $stop The place with its photographs.
	 * @param callable $log  Prints a line.
	 * @return void
	 */
	private static function details( array $stop, $log ) {
		if ( is_array( $stop['place'] ) ) {
			$log( '    levels: ' . wp_json_encode( $stop['place']['levels'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}

		foreach ( $stop['photos'] as $photo ) {
			$log( '    ' . ( null === $photo['time'] ? '--:--' : gmdate( 'H:i', (int) $photo['time'] ) ) . '  ' . round( (float) $photo['lat'], 5 ) . ',' . round( (float) $photo['lon'], 5 ) . '  ' . implode( '  ', (array) ( $photo['urls'] ?? array( $photo['url'] ) ) ) );
		}
	}

	/**
	 * The name of a place, from the highest level to the lowest.
	 *
	 * @param array|null $place The `place` of the answer.
	 * @return string
	 */
	private static function name( $place ) {
		if ( ! is_array( $place ) ) {
			return 'no place yet';
		}

		$candidate = PlaceChain::candidate( $place, 'image/address', array() );
		$names     = array();

		while ( null !== $candidate ) {
			array_unshift( $names, $candidate->label );
			$candidate = $candidate->parent;
		}

		return array() === $names ? 'nothing found' : implode( ' > ', $names );
	}
}
