<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the object cache of the store.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Storage\Cache;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the object cache of the store.
 *
 * @covers \Otherguise\Triples\Storage\Cache
 */
class TriplesCacheTest extends TestCase {

	/**
	 * Values written, by group and key.
	 *
	 * @var array
	 */
	private $data = array();

	/**
	 * Builds a cache on an array.
	 *
	 * @return Cache
	 */
	private function cache() {
		return new Cache(
			fn( $key, $group ) => $this->data[ $group ][ $key ] ?? false,
			function ( $key, $value, $group ) {
				$this->data[ $group ][ $key ] = $value;
			}
		);
	}

	/**
	 * A result is kept in the group of the module.
	 *
	 * @return void
	 */
	public function test_a_result_is_cached_in_its_group(): void {
		$cache = $this->cache();

		$this->assertFalse( $cache->get( 'SELECT 1' ) );

		$cache->set( 'SELECT 1', array( 'a' ) );

		$this->assertSame( array( 'a' ), $cache->get( 'SELECT 1' ) );
		$this->assertFalse( $cache->get( 'SELECT 2' ) );
		$this->assertSame( array( Cache::GROUP ), array_keys( $this->data ) );
	}

	/**
	 * A zero or an empty array is a result, not a miss.
	 *
	 * @return void
	 */
	public function test_empty_results_are_hits(): void {
		$cache = $this->cache();

		$cache->set( 'count', '0' );
		$cache->set( 'rows', array() );

		$this->assertSame( '0', $cache->get( 'count' ) );
		$this->assertSame( array(), $cache->get( 'rows' ) );
	}

	/**
	 * Bumping invalidates every result at once.
	 *
	 * @return void
	 */
	public function test_bump_invalidates_everything(): void {
		$cache = $this->cache();
		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );

		$cache->bump();

		$this->assertFalse( $cache->get( 'a' ) );
		$this->assertFalse( $cache->get( 'b' ) );

		$cache->set( 'a', 3 );

		$this->assertSame( 3, $cache->get( 'a' ) );
	}

	/**
	 * Two instances on the same backend share the token, as two processes with a persistent cache do.
	 *
	 * @return void
	 */
	public function test_instances_share_the_token_through_the_backend(): void {
		$first  = $this->cache();
		$second = $this->cache();
		$first->set( 'a', 1 );

		$this->assertSame( 1, $second->get( 'a' ) );

		$second->bump();

		$this->assertFalse( $first->get( 'a' ) );
	}
}
