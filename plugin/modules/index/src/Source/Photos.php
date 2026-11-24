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
use Otherguise\Index\Source;

defined( 'ABSPATH' ) || exit;

/**
 * The photographs and the GPX files of a post as sources of places (slice 606; **no more stops since slice 623**: every photograph that has a place is one
 * observation of it, so that a village shown on two pages is found on both). The URLs come from the signals of the page, **while it is read** (the
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
	 * @param callable $read     Reads the pending URLs of a post.
	 * @param callable $write    Keeps them.
	 * @param callable $is_local Whether a URL is of this site.
	 */
	public function __construct( Geo $geo, $read, $write, $is_local ) {
		$this->geo      = $geo;
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
	 * Analyses a post: the places of its photographs (grouped by place, one `via` per photograph), the tracks, what was left out and why, and the URLs that
	 * wait for a place. It keeps the pending URLs unless `$dry`.
	 *
	 * @param int  $post_id Post.
	 * @param bool $dry     Keep nothing.
	 * @return array{candidates:Candidate[],places:array,tracks:array,left_out:array<string,int>,pending:string[]}
	 */
	public function analyse( $post_id, $dry = false ) {
		$post_id = (int) $post_id;
		$urls    = $this->live[ $post_id ] ?? array_values( array_map( 'strval', (array) ( $this->read )( $post_id ) ) );
		$left    = array(
			'not_of_the_site' => 0,
			'no_data'         => 0,
			'no_position'     => 0,
			'no_place_yet'    => 0,
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
			}
		}

		$photos = self::one_per_photograph( $photos );
		$places = array();

		foreach ( $photos as $photo ) {
			// A photograph with no place yet waits for the second pass; the others are grouped by place, which only saves resolving the same chain again.
			if ( null === $photo['place'] ) {
				++$left['no_place_yet'];
				$pending = array_merge( $pending, $photo['urls'] );

				continue;
			}

			$key = md5( (string) wp_json_encode( $photo['place'] ) );

			if ( ! isset( $places[ $key ] ) ) {
				$places[ $key ] = array(
					'place'  => $photo['place'],
					'photos' => array(),
				);
			}

			$places[ $key ]['photos'][] = $photo;
		}

		$places = array_values( $places );

		foreach ( $places as $group ) {
			$candidate = PlaceChain::candidate( $group['place'], 'image/address', array_map( static fn( $url ) => self::via( 'image', $url ), array_merge( ...array_column( $group['photos'], 'urls' ) ) ) );

			if ( null !== $candidate ) {
				$candidates[] = $candidate;
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
			'places'     => $places,
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
	 * @return array{url:string,urls:string[],lat:float,lon:float,time:?int,place:?array}
	 */
	private static function photo( $url, array $answer ) {
		$precise = ! in_array( (string) ( $answer['taken_precision'] ?? '' ), array( 'day', 'date' ), true ) && 'mtime' !== (string) ( $answer['taken_source'] ?? '' );
		$time    = $precise && '' !== (string) ( $answer['taken_at'] ?? '' ) ? strtotime( (string) $answer['taken_at'] . ' UTC' ) : false;
		$place   = is_array( $answer['place'] ?? null ) ? $answer['place'] : null;

		return array(
			'url'   => $url,
			'urls'  => array( $url ),
			'lat'   => (float) ( $answer['lat'] ?? 0 ),
			'lon'   => (float) ( $answer['lon'] ?? 0 ),
			'time'  => false === $time ? null : $time,
			'place' => $place,
		);
	}

	/**
	 * Makes one photograph of a thumbnail and of its original (slice 617): two addresses with the **same position, the same time and the same file name once
	 * the size (`-1024x577`, `-scaled`) and the thumbnail directories are left out**. A page shows both (the image and its link), and each would be counted
	 * twice. The record keeps all the addresses (`urls`), so that the mention has the `via` of each and the pending list the addresses of both.
	 *
	 * @param array<int, array> $photos The photographs.
	 * @return array<int, array>
	 */
	private static function one_per_photograph( array $photos ) {
		$one = array();

		foreach ( $photos as $photo ) {
			$name = (string) preg_replace( '/^thumbs_/', '', (string) preg_replace( '/-(?:\d+x\d+|scaled)(\.[A-Za-z0-9]+)$/', '$1', basename( (string) wp_parse_url( $photo['url'], PHP_URL_PATH ) ) ) );
			$key  = $name . '|' . (string) $photo['time'] . '|' . round( (float) $photo['lat'], 5 ) . '|' . round( (float) $photo['lon'], 5 );

			if ( isset( $one[ $key ] ) ) {
				$one[ $key ]['urls'][] = $photo['url'];

				continue;
			}

			$one[ $key ] = $photo;
		}

		return array_values( $one );
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
