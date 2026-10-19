<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the format of the page of a book (slice 219).
 *
 * @package Otherguise
 */

use Otherguise\Books\Settings\PageFormat;
use PHPUnit\Framework\TestCase;

/**
 * No database: the options are the stubs of the tests.
 *
 * @covers \Otherguise\Books\Settings\PageFormat
 */
class BooksPageFormatTest extends TestCase {

	/**
	 * Starts without any option.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * Cleans the options.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		otherguise_test_reset();
	}

	/**
	 * A5 with margins of 10 mm by default, with no option, an option that is not an array, and an empty one.
	 *
	 * @return void
	 */
	public function test_defaults(): void {
		$expected = array(
			'width_mm'  => 148.0,
			'height_mm' => 210.0,
			'margin_mm' => 10.0,
		);
		$format   = new PageFormat();

		$this->assertSame( $expected, $format->get() );
		$this->assertSame( $expected, PageFormat::defaults() );

		update_option( PageFormat::OPTION, 'garbage' );
		$this->assertSame( $expected, $format->get() );

		update_option( PageFormat::OPTION, array() );
		$this->assertSame( $expected, $format->get() );
	}

	/**
	 * Valid values are kept, rounded to a tenth of a millimetre; numbers as text and a decimal comma are accepted.
	 *
	 * @return void
	 */
	public function test_valid_values_are_kept(): void {
		$format = new PageFormat();

		update_option(
			PageFormat::OPTION,
			array(
				'width_mm'  => 148.5,
				'height_mm' => '210',
				'margin_mm' => '12,34',
			)
		);

		$this->assertSame(
			array(
				'width_mm'  => 148.5,
				'height_mm' => 210.0,
				'margin_mm' => 12.3,
			),
			$format->get()
		);

		$this->assertSame(
			array(
				'width_mm'  => 50.0,
				'height_mm' => 1000.0,
				'margin_mm' => 0.0,
			),
			$format->sanitize(
				array(
					'width_mm'  => 50,
					'height_mm' => 1000,
					'margin_mm' => 0,
				)
			),
			'The bounds are included.'
		);
	}

	/**
	 * A field that is not a number, or out of bounds, takes its default; the others are kept.
	 *
	 * @return void
	 */
	public function test_invalid_values_fall_back_to_the_default_of_the_field(): void {
		$format = new PageFormat();

		$result = $format->sanitize(
			array(
				'width_mm'  => 'wide',
				'height_mm' => 49.9,
				'margin_mm' => -1,
			)
		);
		$this->assertSame( 148.0, $result['width_mm'] );
		$this->assertSame( 210.0, $result['height_mm'] );
		$this->assertSame( 10.0, $result['margin_mm'] );

		$result = $format->sanitize(
			array(
				'width_mm'  => 1000.1,
				'height_mm' => 300,
				'margin_mm' => 5,
			)
		);
		$this->assertSame( 148.0, $result['width_mm'] );
		$this->assertSame( 300.0, $result['height_mm'] );
		$this->assertSame( 5.0, $result['margin_mm'] );

		foreach ( array( null, true, array(), '', INF, NAN, '1e400' ) as $value ) {
			$this->assertSame( 148.0, $format->sanitize( array( 'width_mm' => $value ) )['width_mm'], 'Not a usable number: ' . var_export( $value, true ) );
		}

		$this->assertSame( array_keys( PageFormat::defaults() ), array_keys( $format->sanitize( array( 'other' => 1 ) ) ), 'Unknown keys are dropped.' );
		$this->assertSame( PageFormat::defaults(), $format->sanitize( 'not an array' ) );
	}

	/**
	 * The margin is less than half of the smaller side, so that something of the page is left.
	 *
	 * @return void
	 */
	public function test_the_margin_leaves_something_of_the_page(): void {
		$format = new PageFormat();

		$result = $format->sanitize(
			array(
				'width_mm'  => 60,
				'height_mm' => 200,
				'margin_mm' => 30,
			)
		);
		$this->assertSame( 60.0, $result['width_mm'] );
		$this->assertLessThan( 30.0, $result['margin_mm'], 'Half of the width is refused.' );

		$result = $format->sanitize(
			array(
				'width_mm'  => 60,
				'height_mm' => 200,
				'margin_mm' => 29.9,
			)
		);
		$this->assertSame( 29.9, $result['margin_mm'] );

		$result = $format->sanitize(
			array(
				'width_mm'  => 50,
				'height_mm' => 50,
				'margin_mm' => 40,
			)
		);
		$this->assertLessThan( 25.0, $result['margin_mm'] );
	}
}
