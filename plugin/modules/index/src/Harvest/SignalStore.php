<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The signals of a post, kept between two harvests.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Harvest;

defined( 'ABSPATH' ) || exit;

/**
 * Kept as post meta (slice 602): `_index_signals` (JSON), `_index_images` (the number of `image` signals, which are not kept: a page holds up
 * to 150 of them) and `_index_harvest` (the time and the hash of what was kept).
 */
final class SignalStore {
	public const SIGNALS = '_index_signals';
	public const IMAGES  = '_index_images';
	public const HARVEST = '_index_harvest';

	/**
	 * Reads a meta: `fn( int $post_id, string $key ): mixed`.
	 *
	 * @var callable
	 */
	private $read;

	/**
	 * Writes a meta: `fn( int $post_id, string $key, mixed $value ): void`.
	 *
	 * @var callable
	 */
	private $write;

	/**
	 * Returns the time.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Builds the store.
	 *
	 * @param callable|null $read  Default `get_post_meta` (single).
	 * @param callable|null $write Default `update_post_meta`.
	 * @param callable|null $now   Default `time`.
	 */
	public function __construct( $read = null, $write = null, $now = null ) {
		$this->read  = $read ?? static function ( $post_id, $key ) {
			return get_post_meta( (int) $post_id, $key, true );
		};
		$this->write = $write ?? static function ( $post_id, $key, $value ) {
			update_post_meta( (int) $post_id, $key, $value );
		};
		$this->now   = $now ?? 'time';
	}

	/**
	 * Keeps the signals of a post.
	 *
	 * @param int      $post_id Post.
	 * @param Signal[] $signals What the reader found.
	 * @return bool Whether what is kept changed.
	 */
	public function save( $post_id, array $signals ) {
		$kept   = array();
		$images = 0;

		foreach ( $signals as $signal ) {
			if ( Signal::IMAGE === $signal->type ) {
				++$images;

				continue;
			}

			$kept[] = $signal->to_array();
		}

		$json = (string) wp_json_encode( $kept );
		$hash = sha1( $json . '|' . $images );
		$last = (string) ( $this->read )( $post_id, self::HARVEST );
		$old  = explode( ' ', $last, 2 );

		if ( ( $old[1] ?? '' ) === $hash ) {
			return false;
		}

		( $this->write )( $post_id, self::SIGNALS, wp_slash( $json ) );
		( $this->write )( $post_id, self::IMAGES, $images );
		( $this->write )( $post_id, self::HARVEST, ( $this->now )() . ' ' . $hash );

		return true;
	}

	/**
	 * Records that a harvest found nothing new, so that the time is up to date.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public function touch( $post_id ) {
		$old = explode( ' ', (string) ( $this->read )( $post_id, self::HARVEST ), 2 );

		if ( isset( $old[1] ) ) {
			( $this->write )( $post_id, self::HARVEST, ( $this->now )() . ' ' . $old[1] );
		}
	}

	/**
	 * The signals kept for a post.
	 *
	 * @param int $post_id Post.
	 * @return Signal[]
	 */
	public function load( $post_id ) {
		$decoded = json_decode( (string) ( $this->read )( $post_id, self::SIGNALS ), true );
		$signals = array();

		foreach ( is_array( $decoded ) ? $decoded : array() as $item ) {
			$signal = Signal::from_array( $item );

			if ( null !== $signal ) {
				$signals[] = $signal;
			}
		}

		return $signals;
	}
}
