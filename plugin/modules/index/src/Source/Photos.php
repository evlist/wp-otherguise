<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The source `photos`: where the photographs of a post were taken and where its tracks start and end.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Source;

use Otherguise\Index\Candidate;
use Otherguise\Index\Harvest\Signal;
use Otherguise\Index\Photo\Geo;
use Otherguise\Index\Photo\PlaceChain;
use Otherguise\Index\Photo\Stops;
use Otherguise\Index\Source;

defined( 'ABSPATH' ) || exit;

/**
 * The photographs and the GPX files of a post as sources of places (slice 606). The URLs come from the signals of the page, **while it is read** (the
 * store keeps no URL); Media Helper is asked for them at once and **only what is derived is kept**: topics and mentions (sources `image/address`,
 * `trace/start`, `trace/end`, `via` = `image:` or `trace:` and a short hash of the URL). The URLs of what has **no place yet** are kept as
 * **pending** (capped), for a second pass that asks again without network; a post with nothing pending keeps no URL.
 */
final class Photos implements Source, Partial {
	public const SLUG = 'photos';

	/**
	 * The most URLs kept as pending for one post.
	 */
	public const CAP = 100;

	/**
	 * The door to Media Helper.
	 *
	 * @var Geo
	 */
	private $geo;

	/**
	 * Returns the settings (`photos_min`, `minutes_min`, `chain_metres`).
	 *
	 * @var callable
	 */
	private $settings;

	/**
	 * Reads the pending URLs of a post: `fn( int $post ): string[]`.
	 *
	 * @var callable
	 */
	private $read;

	/**
	 * Keeps the pending URLs of a post (an empty list forgets them): `fn( int $post, string[] $urls ): void`.
	 *
	 * @var callable
	 */
	private $write;

	/**
	 * Whether a URL is one of this site: `fn( string $url ): bool`.
	 *
	 * @var callable
	 */
	private $is_local;

	/**
	 * The URLs of the page being read, by post.
	 *
	 * @var array<int, string[]>
	 */
	private $live = array();

	/**
	 * What the last analysis found, by post.
	 *
	 * @var array<int, array>
	 */
	private $last = array();

	/**
	 * Builds the source.
	 *
	 * @param Geo      $geo      Media Helper.
	 * @param callable $settings Returns the settings.
	 * @param callable $read     Reads the pending URLs of a post.
	 * @param callable $write    Keeps them.
	 * @param callable $is_local Whether a URL is of this site.
	 */
	public function __construct( Geo $geo, $settings, $read, $write, $is_local ) {
		$this->geo      = $geo;
		$this->settings = $settings;
		$this->read     = $read;
		$this->write    = $write;
		$this->is_local = $is_local;
	}

	/**
	 * The slug of the source.
	 *
	 * @return string
	 */
	public function id() {
		return self::SLUG;
	}

	/**
	 * The sources of the mentions it makes.
	 *
	 * @return string[]
	 */
	public function mention_sources() {
		return array( 'image/address', 'trace/start', 'trace/end' );
	}

	/**
	 * Places of OpenStreetMap, found by a program from coordinates: they are not sure at first sight, but a wrong place costs little and the queue
	 * would be of thousands. The topics are approved at once.
	 *
	 * @return bool
	 */
	public function is_sure() {
		return true;
	}

	/**
	 * Notes the photographs and the files that the page of a post shows.
	 *
	 * @param int      $post_id Post.
	 * @param Signal[] $signals What the reader found in the page.
	 * @return void
	 */
	public function handle( $post_id, array $signals ) {
		$urls = array();

		foreach ( $signals as $signal ) {
			if ( ( Signal::IMAGE === $signal->type || Signal::GPX_FILE === $signal->type ) && '' !== $signal->value ) {
				$urls[] = $signal->value;
			}
		}

		$this->live[ (int) $post_id ] = array_values( array_unique( $urls ) );
	}

	/**
	 * Whether the page is being read, or some URLs wait for a place.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public function knows( $post_id ) {
		return isset( $this->live[ (int) $post_id ] ) || array() !== (array) ( $this->read )( (int) $post_id );
	}

	/**
	 * What the post speaks of.
	 *
	 * @param int $post_id Post.
	 * @return Candidate[]
	 */
	public function candidates( $post_id ) {
		return $this->analyse( (int) $post_id )['candidates'];
	}

	/**
	 * Analyses a post: the stops, the tracks, what was left out and why, and the URLs that wait for a place. It keeps the pending URLs unless `$dry`.
	 *
	 * @param int  $post_id Post.
	 * @param bool $dry     Keep nothing.
	 * @return array{candidates:Candidate[],stops:array,tracks:array,left_out:array<string,int>,pending:string[]}
	 */
	public function analyse( $post_id, $dry = false ) {
		$post_id = (int) $post_id;
		$urls    = $this->live[ $post_id ] ?? array_values( array_map( 'strval', (array) ( $this->read )( $post_id ) ) );
		$left    = array(
			'not_of_the_site' => 0,
			'no_data'         => 0,
			'no_position'     => 0,
			'no_precise_time' => 0,
			'no_place_yet'    => 0,
			'small_stop'      => 0,
		);
		$local = array();

		foreach ( $urls as $url ) {
			if ( ( $this->is_local )( $url ) ) {
				$local[] = $url;
			} else {
				++$left['not_of_the_site'];
			}
		}

		$answers    = $this->geo->answers( $local );
		$photos     = array();
		$tracks     = array();
		$pending    = array();
		$candidates = array();

		foreach ( $answers as $url => $answer ) {
			if ( null === $answer ) {
				++$left['no_data'];
			} elseif ( 'track' === ( $answer['type'] ?? '' ) ) {
				$tracks[ $url ] = $answer;
			} elseif ( 'positioned' !== ( $answer['state'] ?? '' ) ) {
				++$left['no_position'];
			} else {
				$photos[] = self::photo( $url, $answer );

				if ( null === end( $photos )['time'] ) {
					++$left['no_precise_time'];
				}
			}
		}

		$stops = Stops::group( $photos, (array) ( $this->settings )() );

		foreach ( $stops as $stop ) {
			if ( null === $stop['place'] ) {
				$left['no_place_yet'] += count( $stop['photos'] );
				$pending               = array_merge( $pending, array_column( $stop['photos'], 'url' ) );
			} elseif ( ! $stop['qualifies'] ) {
				$left['small_stop'] += count( $stop['photos'] );
			} else {
				$candidate = PlaceChain::candidate( $stop['place'], 'image/address', array_map( static fn( $photo ) => self::via( 'image', $photo['url'] ), $stop['photos'] ) );

				if ( null !== $candidate ) {
					$candidates[] = $candidate;
				}
			}
		}

		foreach ( $tracks as $url => $track ) {
			foreach ( array(
				'start' => 'trace/start',
				'end'   => 'trace/end',
			) as $end => $source ) {
				$place = $track[ $end ]['place'] ?? null;

				if ( ! is_array( $place ) ) {
					++$left['no_place_yet'];
					$pending[] = $url;

					continue;
				}

				$candidate = PlaceChain::candidate( $place, $source, array( self::via( 'trace', $url ) ) );

				if ( null !== $candidate ) {
					$candidates[] = $candidate;
				}
			}
		}

		$pending = array_slice( array_values( array_unique( $pending ) ), 0, self::CAP );

		if ( ! $dry ) {
			( $this->write )( $post_id, $pending );
			unset( $this->live[ $post_id ] );
		}

		$this->last[ $post_id ] = array(
			'candidates' => $candidates,
			'stops'      => $stops,
			'tracks'     => $tracks,
			'left_out'   => $left,
			'pending'    => $pending,
		);

		return $this->last[ $post_id ];
	}

	/**
	 * Forgets the pending URLs of a post (it is gone, or it no longer shows these files).
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public function release( $post_id ) {
		( $this->write )( (int) $post_id, array() );
	}

	/**
	 * What the last analysis of a post found.
	 *
	 * @param int $post_id Post.
	 * @return array Empty when the post was not analysed.
	 */
	public function last( $post_id ) {
		return $this->last[ (int) $post_id ] ?? array();
	}

	/**
	 * The record of a photograph for the grouping.
	 *
	 * @param string $url    URL.
	 * @param array  $answer What Media Helper answered.
	 * @return array{url:string,lat:float,lon:float,time:?int,place:?array}
	 */
	private static function photo( $url, array $answer ) {
		$precise = ! in_array( (string) ( $answer['taken_precision'] ?? '' ), array( 'day', 'date' ), true ) && 'mtime' !== (string) ( $answer['taken_source'] ?? '' );
		$time    = $precise && '' !== (string) ( $answer['taken_at'] ?? '' ) ? strtotime( (string) $answer['taken_at'] . ' UTC' ) : false;
		$place   = is_array( $answer['place'] ?? null ) ? $answer['place'] : null;

		return array(
			'url'   => $url,
			'lat'   => (float) ( $answer['lat'] ?? 0 ),
			'lon'   => (float) ( $answer['lon'] ?? 0 ),
			'time'  => false === $time ? null : $time,
			'place' => $place,
		);
	}

	/**
	 * What says that a mention comes from a file: the kind and a short hash of the URL (the URL itself is not kept).
	 *
	 * @param string $kind `image` or `trace`.
	 * @param string $url  URL.
	 * @return string
	 */
	public static function via( $kind, $url ) {
		return $kind . ':' . substr( sha1( $url ), 0, 12 );
	}
}
