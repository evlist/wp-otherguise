<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the modes, their registry and the detection of the mode of a request.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Mode\ActiveMode;
use Otherguise\Modes\Mode\ModeDefinition;
use Otherguise\Modes\Mode\ModeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests of ModeDefinition, ModeRegistry and ActiveMode.
 *
 * @covers \Otherguise\Modes\Mode\ActiveMode
 * @covers \Otherguise\Modes\Mode\ModeDefinition
 * @covers \Otherguise\Modes\Mode\ModeRegistry
 */
class ModesModeTest extends TestCase {

	/**
	 * Builds a registry with web (default), print (alias print) and book (alias book).
	 *
	 * @param callable|null $default_slug Default slug resolver.
	 * @return ModeRegistry
	 */
	private function registry( $default_slug = null ) {
		return new ModeRegistry(
			static function ( $registry ) {
				$registry->register( new ModeDefinition( 'web', 'Web' ) );
				$registry->register( new ModeDefinition( 'print', 'Print', 'print' ) );
				$registry->register( new ModeDefinition( 'book', 'Book', 'book' ) );
			},
			$default_slug
		);
	}

	/**
	 * A valid mode keeps what it was given.
	 *
	 * @return void
	 */
	public function test_a_mode(): void {
		$mode = new ModeDefinition( 'print', 'Print', 'print' );

		$this->assertSame( 'print', $mode->slug() );
		$this->assertSame( 'Print', $mode->label() );
		$this->assertSame( 'print', $mode->alias() );
		$this->assertNull( ( new ModeDefinition( 'web', 'Web' ) )->alias() );
	}

	/**
	 * Invalid slugs, labels and aliases are refused.
	 *
	 * @return void
	 */
	public function test_invalid_modes_are_refused(): void {
		foreach ( array(
			'empty slug'          => array( '', 'L' ),
			'upper case'          => array( 'Print', 'L' ),
			'starts with a digit' => array( '1up', 'L' ),
			'a slash'             => array( 'a/b', 'L' ),
			'too long'            => array( str_repeat( 'a', 21 ), 'L' ),
			'a trailing newline'  => array( "print\n", 'L' ),
			'not a string'        => array( 12, 'L' ),
			'no label'            => array( 'print', '  ' ),
			'alias with a dash'   => array( 'print', 'L', 'my-print' ),
			'alias mode'          => array( 'print', 'L', 'mode' ),
			'alias of WordPress'  => array( 'print', 'L', 'page_id' ),
			'alias not a string'  => array( 'print', 'L', 5 ),
		) as $label => $arguments ) {
			try {
				new ModeDefinition( ...$arguments );
				$this->fail( "Should be refused: $label" );
			} catch ( InvalidArgumentException $problem ) {
				$this->assertNotSame( '', $problem->getMessage(), $label );
			}
		}
	}

	/**
	 * The initializer runs once, at the first read.
	 *
	 * @return void
	 */
	public function test_the_registry_is_lazy(): void {
		$calls    = 0;
		$registry = new ModeRegistry(
			static function ( $registry ) use ( &$calls ) {
				++$calls;
				$registry->register( new ModeDefinition( 'web', 'Web' ) );
			}
		);

		$this->assertSame( 0, $calls );
		$this->assertTrue( $registry->has( 'web' ) );
		$this->assertFalse( $registry->has( 'ghost' ) );
		$this->assertFalse( $registry->has( array() ) );
		$this->assertSame( array( 'web' ), array_keys( $registry->all() ) );
		$this->assertSame( 1, $calls );
	}

	/**
	 * A callback of the registration action can read the registry while it fills it.
	 *
	 * @return void
	 */
	public function test_the_initializer_may_read_the_registry(): void {
		$registry = new ModeRegistry(
			static function ( $registry ) {
				$registry->register( new ModeDefinition( 'web', 'Web' ) );
				$registry->register( new ModeDefinition( 'copy', 'Copy' ) );

				if ( $registry->has( 'web' ) ) {
					$registry->register( new ModeDefinition( 'extra', 'Extra' ) );
				}
			}
		);

		$this->assertSame( array( 'web', 'copy', 'extra' ), array_keys( $registry->all() ) );
	}

	/**
	 * Duplicates are refused; an unknown mode cannot be read.
	 *
	 * @return void
	 */
	public function test_duplicates_and_unknown_modes(): void {
		$registry = new ModeRegistry();
		$registry->register( new ModeDefinition( 'print', 'Print', 'print' ) );

		foreach ( array( new ModeDefinition( 'print', 'Again' ), new ModeDefinition( 'other', 'Other', 'print' ) ) as $duplicate ) {
			try {
				$registry->register( $duplicate );
				$this->fail( 'A duplicate should be refused.' );
			} catch ( InvalidArgumentException $problem ) {
				$this->assertNotSame( '', $problem->getMessage() );
			}
		}

		$this->expectException( InvalidArgumentException::class );
		$registry->get( 'ghost' );
	}

	/**
	 * The default mode is web, can be changed, and falls back to the first mode when the slug is unknown.
	 *
	 * @return void
	 */
	public function test_the_default_mode(): void {
		$this->assertSame( 'web', $this->registry()->default_mode()->slug() );
		$this->assertSame( 'print', $this->registry( static fn() => 'print' )->default_mode()->slug() );
		$this->assertSame( 'web', $this->registry( static fn() => 'ghost' )->default_mode()->slug(), 'Unknown: the first registered.' );
		$this->assertSame( 'web', $this->registry( static fn() => array( 'print' ) )->default_mode()->slug() );

		$this->expectException( LogicException::class );
		( new ModeRegistry() )->default_mode();
	}

	/**
	 * Builds the finder for a query string.
	 *
	 * @param array             $query    What the query reader returns.
	 * @param ModeRegistry|null $registry Registry.
	 * @return ActiveMode
	 */
	private function active( array $query, ?ModeRegistry $registry = null ) {
		return new ActiveMode( $registry ?? $this->registry(), static fn() => $query );
	}

	/**
	 * Without anything in the query string the request is in the default mode.
	 *
	 * @return void
	 */
	public function test_default_mode_when_nothing_is_asked(): void {
		$active = $this->active( array() );

		$this->assertSame( 'web', $active->mode()->slug() );
		$this->assertFalse( $active->is_explicit() );
	}

	/**
	 * `?mode=print` selects print.
	 *
	 * @return void
	 */
	public function test_mode_parameter(): void {
		$active = $this->active( array( 'mode' => 'print' ) );

		$this->assertSame( 'print', $active->mode()->slug() );
		$this->assertTrue( $active->is_explicit() );
		$this->assertSame( 'web', $this->active( array( 'mode' => 'web' ) )->mode()->slug() );
		$this->assertTrue( $this->active( array( 'mode' => 'web' ) )->is_explicit() );
	}

	/**
	 * The alias selects a mode by its presence, even without a value.
	 *
	 * @return void
	 */
	public function test_alias(): void {
		$this->assertSame( 'print', $this->active( array( 'print' => '' ) )->mode()->slug() );
		$this->assertSame( 'print', $this->active( array( 'print' => '1' ) )->mode()->slug() );
		$this->assertTrue( $this->active( array( 'print' => '' ) )->is_explicit() );
	}

	/**
	 * `mode` wins over an alias; among aliases the mode registered first wins.
	 *
	 * @return void
	 */
	public function test_priorities(): void {
		$this->assertSame(
			'book',
			$this->active(
				array(
					'mode' => 'book',
					'print' => '',
				)
			)->mode()->slug()
		);
		$this->assertSame(
			'print',
			$this->active(
				array(
					'book' => '',
					'print' => '',
				)
			)->mode()->slug()
		);
		$this->assertSame(
			'print',
			$this->active(
				array(
					'mode' => 'ghost',
					'print' => '',
				)
			)->mode()->slug(),
			'An unknown mode is ignored, the alias counts.'
		);
	}

	/**
	 * What does not name a registered mode is ignored: unknown slugs, other keys, values that are not text, something that looks like a path.
	 *
	 * @return void
	 */
	public function test_invalid_requests_give_the_default_mode(): void {
		foreach ( array(
			array( 'mode' => 'ghost' ),
			array( 'mode' => '../../wp-config' ),
			array( 'mode' => 'PRINT' ),
			array( 'mode' => array( 'print' ) ),
			array( 'mode' => '' ),
			array( 'other' => '1' ),
			array( 'print' => array( 'x' ) ),
		) as $query ) {
			$active = $this->active( $query );

			$this->assertSame( 'web', $active->mode()->slug(), json_encode( $query ) );
			$this->assertFalse( $active->is_explicit() );
		}
	}

	/**
	 * A reader that gives something else than an array is read as an empty query string.
	 *
	 * @return void
	 */
	public function test_a_broken_reader_is_an_empty_query_string(): void {
		$active = new ActiveMode( $this->registry(), static fn() => null );

		$this->assertSame( 'web', $active->mode()->slug() );
	}

	/**
	 * The mode is found once; reset() looks again.
	 *
	 * @return void
	 */
	public function test_the_mode_is_found_once(): void {
		$query  = array( 'mode' => 'print' );
		$active = new ActiveMode(
			$this->registry(),
			static function () use ( &$query ) {
				return $query;
			}
		);

		$this->assertSame( 'print', $active->mode()->slug() );

		$query = array( 'mode' => 'book' );

		$this->assertSame( 'print', $active->mode()->slug() );

		$active->reset();

		$this->assertSame( 'book', $active->mode()->slug() );
	}
}
