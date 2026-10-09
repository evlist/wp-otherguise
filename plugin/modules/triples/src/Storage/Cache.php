<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Object cache of the reads of the store.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * The cache of the statements: the pattern WordPress uses for its queries.
 *
 * The group `triples` holds a `last_changed` token and the cached results, whose keys contain the token. A write changes the token,
 * which invalidates every result at once; nothing is purged one by one. Without a persistent object cache plugin WordPress keeps the
 * cache for the current request only. A change made outside the module (direct SQL) does not change the token.
 */
final class Cache {
	/**
	 * Cache group.
	 */
	public const GROUP = 'triples';

	/**
	 * Key of the token.
	 */
	private const TOKEN_KEY = 'last_changed';

	/**
	 * Reads a key: receives the key and the group, returns the value or false.
	 *
	 * @var callable
	 */
	private $get;

	/**
	 * Writes a key: receives the key, the value and the group.
	 *
	 * @var callable
	 */
	private $set;

	/**
	 * Builds the cache.
	 *
	 * @param callable|null $get Reader; defaults to `wp_cache_get()`.
	 * @param callable|null $set Writer; defaults to `wp_cache_set()`.
	 */
	public function __construct( $get = null, $set = null ) {
		$this->get = $get ?? 'wp_cache_get';
		$this->set = $set ?? 'wp_cache_set';
	}

	/**
	 * Reads a result.
	 *
	 * @param string $name Name of the result: the query.
	 * @return mixed The value, or false when it is not cached.
	 */
	public function get( $name ) {
		return ( $this->get )( $this->key( $name ), self::GROUP );
	}

	/**
	 * Caches a result until the next change.
	 *
	 * @param string $name  Name of the result.
	 * @param mixed  $value Value; never false.
	 * @return void
	 */
	public function set( $name, $value ) {
		( $this->set )( $this->key( $name ), $value, self::GROUP );
	}

	/**
	 * Invalidates every cached result.
	 *
	 * @return void
	 */
	public function bump() {
		( $this->set )( self::TOKEN_KEY, sprintf( '%.6f', microtime( true ) ) . '-' . bin2hex( random_bytes( 4 ) ), self::GROUP );
	}

	/**
	 * Builds the key of a result from the current token, which is created when there is none.
	 *
	 * @param string $name Name of the result.
	 * @return string
	 */
	private function key( $name ) {
		$token = ( $this->get )( self::TOKEN_KEY, self::GROUP );

		if ( ! is_string( $token ) || '' === $token ) {
			$this->bump();

			$token = ( $this->get )( self::TOKEN_KEY, self::GROUP );
		}

		return 'q:' . ( is_string( $token ) ? $token : '' ) . ':' . md5( $name );
	}
}
