<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the image sizes of the print (slice 221).
 *
 * @package Otherguise
 */

use Otherguise\Books\Cli\SizesCommand;
use Otherguise\Books\Images\PrintSizes;
use Otherguise\Books\Images\PrintSrcset;
use Otherguise\Books\Settings\PageFormat;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress: the filters and `add_image_size` are doubles.
 *
 * @covers \Otherguise\Books\Images\PrintSizes
 * @covers \Otherguise\Books\Images\PrintSrcset
 * @covers \Otherguise\Books\Cli\SizesCommand
 */
class BooksImagesTest extends TestCase {

	/**
	 * Starts without any option.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * Cleans up.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		otherguise_test_reset();
	}

	/**
	 * Builds the sizes with a filter double.
	 *
	 * @param array $values Values of the filters by name.
	 * @param array $added  Calls of `add_image_size`.
	 * @return PrintSizes
	 */
	private function sizes( array $values, array &$added = array() ) {
		return new PrintSizes(
			new PageFormat(),
			static fn( $name, $default ) => array_key_exists( $name, $values ) ? $values[ $name ] : $default,
			static function ( ...$arguments ) use ( &$added ) {
				$added[] = $arguments;
			}
		);
	}

	/**
	 * Without declared sizes there are none: Books ships no size of its own.
	 *
	 * @return void
	 */
	public function test_no_size_is_declared_by_default(): void {
		$added = array();
		$sizes = $this->sizes( array(), $added );

		$this->assertSame( array(), $sizes->declared() );
		$this->assertSame( array(), $sizes->sizes() );
		$sizes->register();
		$this->assertSame( array(), $added );
		$this->assertSame( 300, $sizes->ppi() );
	}

	/**
	 * The pixels come from the usable width of the page (the page minus the margins), the fraction and the resolution.
	 *
	 * @return void
	 */
	public function test_the_pixels(): void {
		$declared = array(
			'books-print-full'  => array( 'fraction' => 0.9 ),
			'books-print-thumb' => array(
				'fraction' => 0.3,
				'ratio'    => '16:9',
			),
		);
		$added    = array();
		$sizes    = $this->sizes( array( 'books_print_sizes' => $declared ), $added );

		// A5, margins of 10 mm by default: usable width 128 mm.
		$this->assertSame(
			array(
				'books-print-full'  => array(
					'width'    => 1361,
					'height'   => 0,
					'crop'     => false,
					'fraction' => 0.9,
					'ratio'    => null,
				),
				'books-print-thumb' => array(
					'width'    => 454,
					'height'   => 255,
					'crop'     => true,
					'fraction' => 0.3,
					'ratio'    => 16 / 9,
				),
			),
			$sizes->sizes()
		);

		$sizes->register();
		$this->assertSame(
			array(
				array( 'books-print-full', 1361, 9999, false ),
				array( 'books-print-thumb', 454, 255, true ),
			),
			$added,
			'A proportional size has an open height; a cropped one has both.'
		);

		update_option(
			PageFormat::OPTION,
			array(
				'width_mm'  => 210,
				'height_mm' => 297,
				'margin_mm' => 10,
			)
		);
		$this->assertSame( 2020, $sizes->sizes()['books-print-full']['width'], 'A4, usable 190 mm: the page format drives the pixels.' );

		$ppi = $this->sizes(
			array(
				'books_print_sizes' => $declared,
				'books_print_ppi'   => 200,
			)
		);
		$this->assertSame( 1347, $ppi->sizes()['books-print-full']['width'], 'A4 at 200 ppi.' );
	}

	/**
	 * What is not valid is ignored: a name, a fraction, a ratio, a definition, the whole declaration.
	 *
	 * @return void
	 */
	public function test_invalid_declarations_are_ignored(): void {
		$declared = array(
			'books-print-ok'     => array( 'fraction' => 1 ),
			'books-print-ok-2'   => array(
				'fraction' => 0.05,
				'ratio'    => 1.5,
			),
			'gallery'            => array( 'fraction' => 0.3 ),
			'books-print-'       => array( 'fraction' => 0.3 ),
			'books-print-Upper'  => array( 'fraction' => 0.3 ),
			'books-print-a--b'   => array( 'fraction' => 0.3 ),
			'books-print-low'    => array( 'fraction' => 0.04 ),
			'books-print-high'   => array( 'fraction' => 1.01 ),
			'books-print-text'   => array( 'fraction' => '0.5' ),
			'books-print-none'   => array(),
			'books-print-string' => 'x',
			'books-print-ratio'  => array(
				'fraction' => 0.5,
				'ratio'    => 'wide',
			),
			'books-print-zero'   => array(
				'fraction' => 0.5,
				'ratio'    => '16:0',
			),
			12                   => array( 'fraction' => 0.5 ),
		);

		$this->assertSame( array( 'books-print-ok', 'books-print-ok-2' ), array_keys( $this->sizes( array( 'books_print_sizes' => $declared ) )->declared() ) );
		$this->assertSame( array(), $this->sizes( array( 'books_print_sizes' => 'nothing' ) )->declared() );
	}

	/**
	 * Ratios: `W:H`, a number, and the bounds.
	 *
	 * @return void
	 */
	public function test_ratios(): void {
		$this->assertEqualsWithDelta( 16 / 9, PrintSizes::ratio( '16:9' ), 0.0001 );
		$this->assertEqualsWithDelta( 1.5, PrintSizes::ratio( ' 3:2 ' ), 0.0001 );
		$this->assertEqualsWithDelta( 1.0, PrintSizes::ratio( 1 ), 0.0001 );
		$this->assertEqualsWithDelta( 0.75, PrintSizes::ratio( 0.75 ), 0.0001 );

		foreach ( array( '16:0', '0:9', 'wide', '', null, true, 0, 6, '1:10', array( 1 ) ) as $bad ) {
			$this->assertNull( PrintSizes::ratio( $bad ), 'Not a ratio: ' . var_export( $bad, true ) );
		}
	}

	/**
	 * The resolution: a whole number from 72 to 1200, else 300.
	 *
	 * @return void
	 */
	public function test_the_resolution(): void {
		foreach ( array(
			72 => 72,
			1200 => 1200,
			600 => 600,
		) as $value => $expected ) {
			$this->assertSame( $expected, $this->sizes( array( 'books_print_ppi' => $value ) )->ppi() );
		}

		foreach ( array( 71, 1201, '300', 300.0, null, array() ) as $bad ) {
			$this->assertSame( 300, $this->sizes( array( 'books_print_ppi' => $bad ) )->ppi(), 'Refused: ' . var_export( $bad, true ) );
		}
	}

	/**
	 * In a print mode the srcset is removed; elsewhere it is kept.
	 *
	 * @return void
	 */
	public function test_the_srcset(): void {
		$sources = array(
			300 => array( 'url' => 'a-300.jpg' ),
			480 => array( 'url' => 'a-480.jpg' ),
		);

		$print = new PrintSrcset( static fn() => 'print', static fn() => array( 'print' ) );
		$this->assertFalse( $print->filter( $sources ) );
		$this->assertFalse( $print->filter( false ) );

		$web = new PrintSrcset( static fn() => 'web', static fn() => array( 'print' ) );
		$this->assertSame( $sources, $web->filter( $sources ) );

		$book = new PrintSrcset( static fn() => 'book', static fn() => array( 'print', 'book' ) );
		$this->assertFalse( $book->filter( $sources ), 'Any mode of the list.' );

		$this->assertSame( $sources, ( new PrintSrcset( static fn() => array( 'print' ), static fn() => array( 'print' ) ) )->filter( $sources ), 'A mode that is not a slug.' );
		$this->assertSame( $sources, ( new PrintSrcset( static fn() => 'print', static fn() => 'print' ) )->filter( $sources ), 'A list that is not a list.' );

		$default = new PrintSrcset();
		$this->assertSame( $sources, $default->filter( $sources ), 'By default (the stubs of the tests answer web): kept.' );
	}

	/**
	 * The command lists the sizes as lines, as JSON or as names for `--size=`, and refuses another format.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$declared = array(
			'books-print-full'  => array( 'fraction' => 0.9 ),
			'books-print-thumb' => array(
				'fraction' => 0.3,
				'ratio'    => '16:9',
			),
		);
		$out      = array();
		$command  = new SizesCommand(
			$this->sizes( array( 'books_print_sizes' => $declared ) ),
			static function ( $line ) use ( &$out ) {
				$out[] = $line;
			},
			static function ( $message ) {
				throw new RuntimeException( esc_html( $message ) );
			}
		);

		$command( array(), array() );
		$this->assertSame(
			array(
				"books-print-full\t1361\t0\tproportional\t0.9\t-",
				"books-print-thumb\t454\t255\tcrop\t0.3\t1.778",
			),
			$out
		);

		$out = array();
		$command( array(), array( 'format' => 'names' ) );
		$this->assertSame( array( 'books-print-full,books-print-thumb' ), $out );

		$out = array();
		$command( array(), array( 'format' => 'json' ) );
		$this->assertSame( 454, json_decode( $out[0], true )['books-print-thumb']['width'] );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( '--format: table, json or names.' );
		$command( array(), array( 'format' => 'xml' ) );
	}

	/**
	 * Without declared sizes the command prints nothing for `names` (an empty line) and no line for the table.
	 *
	 * @return void
	 */
	public function test_the_command_without_sizes(): void {
		$out     = array();
		$command = new SizesCommand(
			$this->sizes( array() ),
			static function ( $line ) use ( &$out ) {
				$out[] = $line;
			}
		);

		$command( array(), array() );
		$this->assertSame( array(), $out );

		$command( array(), array( 'format' => 'names' ) );
		$this->assertSame( array( '' ), $out );

		$reflection = new ReflectionProperty( SizesCommand::class, 'fail' );
		$reflection->setAccessible( true );
		$this->assertSame( array( 'WP_CLI', 'error' ), $reflection->getValue( $command ) );
	}
}
