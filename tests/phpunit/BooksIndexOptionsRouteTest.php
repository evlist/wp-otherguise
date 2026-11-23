<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the route that checks the options of the block of the index while they are typed (slice 613).
 *
 * @package Otherguise
 */

use Otherguise\Books\Rest\IndexOptionsRoute;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress: the module of the index is a closure.
 *
 * @covers \Otherguise\Books\Rest\IndexOptionsRoute
 */
class BooksIndexOptionsRouteTest extends TestCase {

	/**
	 * Starts clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * Each field is checked on its own by the module of the index, so that the message is under the field that is wrong; a field that is empty is not
	 * asked.
	 *
	 * @return void
	 */
	public function test_each_field_is_checked_by_the_module(): void {
		$asked = array();
		$route = new IndexOptionsRoute(
			static fn() => true,
			static function ( array $options ) use ( &$asked ) {
				$asked[] = $options;

				return 'kind:category' === reset( $options ) ? array(
					'groups' => array(),
					'errors' => array( 'Unknown kind "category".' ),
				) : array( 'groups' => array() );
			}
		);

		$answer = $route->check(
			array(
				'include' => 'kind:place',
				'exclude' => '',
				'rollup'  => 'kind:category',
			)
		);

		$this->assertSame(
			array(
				'available' => true,
				'errors'    => array(
					'include' => array(),
					'exclude' => array(),
					'rollup'  => array( 'Unknown kind "category".' ),
				),
			),
			$answer
		);
		$this->assertSame(
			array(
				array( 'include' => 'kind:place' ),
				array( 'rollup' => 'kind:category' ),
			),
			$asked,
			'One question per field that has something in it, with that field only.'
		);
	}

	/**
	 * Without the module of the index (nothing answers) the route says it is not available and reports no error.
	 *
	 * @return void
	 */
	public function test_without_the_module_nothing_is_reported(): void {
		$route  = new IndexOptionsRoute( static fn() => true, static fn() => null );
		$answer = $route->check( array( 'include' => 'kind:nonsense' ) );

		$this->assertFalse( $answer['available'] );
		$this->assertSame( array(), $answer['errors']['include'] );
	}

	/**
	 * The route is for the people who can edit posts, and takes its three options as strings.
	 *
	 * @return void
	 */
	public function test_the_route_is_registered_for_editors(): void {
		$route = new IndexOptionsRoute( static fn() => false );

		$this->assertFalse( $route->can_use() );
		$this->assertTrue( ( new IndexOptionsRoute( static fn() => true ) )->can_use() );

		$route->register();

		$calls = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_rest_route' === $call[0] ) );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'books/v1', $calls[0][1] );
		$this->assertSame( '/index-options', $calls[0][2] );
		$this->assertSame( array( 'include', 'exclude', 'rollup' ), array_keys( $calls[0][3]['args'] ) );
	}
}
