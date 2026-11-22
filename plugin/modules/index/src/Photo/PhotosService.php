<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The WordPress side of slice 606.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Photo;

use Otherguise\Index\Harvest\Signal;
use Otherguise\Index\Indexer;
use Otherguise\Index\Job\PagesService;
use Otherguise\Index\Source\Photos;

defined( 'ABSPATH' ) || exit;

/**
 * The photographs and the tracks as sources (slice 606): the source with its meta and its settings, the reading of a page, the second pass for the
 * places that were not known yet (cron, and the signal of Media Helper), the switch.
 */
final class PhotosService {
	public const TICK_HOOK = 'otherguise_index_photos';
	public const PENDING   = '_index_geo_pending';
	public const SETTINGS  = 'index_photos_settings';
	public const LAST      = 'index_photos_last';
	public const WAITING   = 'index_photos_waiting';

	/**
	 * Returns the indexer: `fn(): Indexer`.
	 *
	 * @var callable
	 */
	private $indexer;

	/**
	 * The source.
	 *
	 * @var Photos|null
	 */
	private $source = null;

	/**
	 * Builds the service.
	 *
	 * @param callable $indexer Returns the indexer.
	 */
	public function __construct( $indexer ) {
		$this->indexer = $indexer;
	}

	/**
	 * The source, one for the request: the indexer and the page that is read share it.
	 *
	 * @return Photos
	 */
	public function source() {
		if ( null === $this->source ) {
			$this->source = new Photos(
				new Geo(),
				array( $this, 'settings' ),
				static function ( $post ) {
					$urls = json_decode( (string) get_post_meta( (int) $post, self::PENDING, true ), true );

					return is_array( $urls ) ? $urls : array();
				},
				static function ( $post, array $urls ) {
					// The posts that wait are listed in an option, so that the second pass needs no query on the meta.
					$waiting = get_option( self::WAITING, array() );
					$waiting = array_values( array_diff( is_array( $waiting ) ? array_map( 'intval', $waiting ) : array(), array( (int) $post ) ) );

					if ( array() === $urls ) {
						delete_post_meta( (int) $post, self::PENDING );
					} else {
						update_post_meta( (int) $post, self::PENDING, wp_slash( (string) wp_json_encode( $urls ) ) );
						$waiting[] = (int) $post;
					}

					update_option( self::WAITING, $waiting, false );
				},
				static function ( $url ) {
					$host = wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST );

					// A relative URL is of the site; so is an absolute one on its host. Media Helper has the last word (the hosts of a CDN).
					return ! is_string( $host ) || preg_replace( '/^www\./', '', strtolower( $host ) ) === preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) || (bool) apply_filters( 'index_photos_is_local', false, $url );
				}
			);
		}

		return $this->source;
	}

	/**
	 * The settings: the options `photos_min`, `minutes_min` and `chain_metres`. The defaults are **not calibrated** (Eric's list of places is awaited).
	 *
	 * @return array<string, int>
	 */
	public function settings() {
		$saved = get_option( self::SETTINGS, array() );

		return array_merge( Stops::DEFAULTS, array_map( 'intval', array_intersect_key( is_array( $saved ) ? $saved : array(), Stops::DEFAULTS ) ) );
	}

	/**
	 * Whether the source is on.
	 *
	 * @return bool
	 */
	public function is_on() {
		return in_array( Photos::SLUG, ( $this->indexer )()->source_ids(), true );
	}

	/**
	 * A page was read: its photographs and files make the mentions of the sources `image/*` and `trace/*`.
	 *
	 * @param int      $post_id Post.
	 * @param Signal[] $signals What the page said.
	 * @return void
	 */
	public function process( $post_id, array $signals ) {
		if ( ! $this->is_on() ) {
			return;
		}

		$this->source()->handle( (int) $post_id, $signals );
		( $this->indexer )()->index_post( (int) $post_id, array( Photos::SLUG ) );
		$this->keep( (int) $post_id );
		$this->schedule();
	}

	/**
	 * The second pass (no network: Media Helper's database only): the posts that have URLs without a place are asked again. Action
	 * `otherguise_index_photos`, and the action `wp_media_helper_places_updated` of Media Helper.
	 *
	 * @return int How many posts were looked at.
	 */
	public function tick() {
		if ( ! $this->is_on() ) {
			return 0;
		}

		$posts = $this->pending_posts( 50 );

		foreach ( $posts as $post ) {
			if ( 'publish' !== get_post_status( $post ) || ! $this->source()->knows( $post ) ) {
				$this->source()->release( $post );

				continue;
			}

			( $this->indexer )()->index_post( $post, array( Photos::SLUG ) );
			$this->keep( $post );
		}

		$this->schedule();

		return count( $posts );
	}

	/**
	 * What the analysis of a post says, without keeping anything: the stops, their duration, the place chosen and what is left out (the tool to calibrate
	 * the minimums).
	 *
	 * @param int      $post_id Post.
	 * @param Signal[] $signals What the page said.
	 * @return array
	 */
	public function explain( $post_id, array $signals ) {
		$this->source()->handle( (int) $post_id, $signals );

		return $this->source()->analyse( (int) $post_id, true );
	}

	/**
	 * How many posts wait for a place, how many URLs, and what was left out so far.
	 *
	 * @return array{posts:int,urls:int,left_out:array<string,int>}
	 */
	public function status() {
		$urls = 0;

		foreach ( $this->pending_posts( 100000 ) as $post ) {
			$urls += count( (array) json_decode( (string) get_post_meta( $post, self::PENDING, true ), true ) );
		}

		$left = get_option( self::LAST, array() );

		return array(
			'posts'    => count( $this->pending_posts( 100000 ) ),
			'urls'     => $urls,
			'left_out' => is_array( $left ) ? array_map( 'intval', $left ) : array(),
		);
	}

	/**
	 * Keeps what the last analysis of a post found left out, for `status`.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	private function keep( $post_id ) {
		$last = $this->source()->last( $post_id );

		if ( array() === $last ) {
			return;
		}

		$totals = get_option( self::LAST, array() );
		$totals = is_array( $totals ) ? $totals : array();

		foreach ( $last['left_out'] as $reason => $count ) {
			$totals[ $reason ] = (int) ( $totals[ $reason ] ?? 0 ) + $count;
		}

		update_option( self::LAST, $totals, false );
	}

	/**
	 * Schedules the next pass in an hour while some post has URLs without a place.
	 *
	 * @return void
	 */
	private function schedule() {
		if ( array() !== $this->pending_posts( 1 ) && false === wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::TICK_HOOK );
		}
	}

	/**
	 * The posts that have URLs without a place (listed in an option).
	 *
	 * @param int $limit At most.
	 * @return int[]
	 */
	public function pending_posts( $limit ) {
		$waiting = get_option( self::WAITING, array() );

		return array_slice( array_values( array_map( 'intval', is_array( $waiting ) ? $waiting : array() ) ), 0, (int) $limit );
	}

	/**
	 * Turns the source on or off (the job that reads the pages must be on too: `index pages enable`).
	 *
	 * @param bool $on On or off.
	 * @return true
	 */
	public function set_source( $on ) {
		$sources = get_option( PagesService::SOURCES, array( 'categories', 'tags' ) );
		$sources = array_values( array_diff( is_array( $sources ) ? $sources : array(), array( Photos::SLUG ) ) );

		if ( $on ) {
			$sources[] = Photos::SLUG;
		}

		update_option( PagesService::SOURCES, $sources );

		return true;
	}
}
