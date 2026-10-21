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
use Otherguise\Books\Images\PrintFallback;
use Otherguise\Books\Images\PrintPixelRatio;
use Otherguise\Books\Images\PrintSizes;
use Otherguise\Books\Images\PrintSrcset;
use Otherguise\Books\Settings\PageFormat;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress: the filters and `add_image_size` are doubles.
 *
 * @covers \Otherguise\Books\Images\PrintSizes
 * @covers \Otherguise\Books\Images\PrintSrcset
 * @covers \Otherguise\Books\Images\PrintPixelRatio
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
	 * Builds the pixel ratio script with doubles.
	 *
	 * @param string $mode  Active mode.
	 * @param mixed  $ratio Value of the filter.
	 * @param mixed  $modes Print modes.
	 * @return PrintPixelRatio
	 */
	private function pixel_ratio( $mode, $ratio, $modes = array( 'print' ) ): PrintPixelRatio {
		return new PrintPixelRatio( static fn() => $mode, static fn() => $modes, static fn() => $ratio );
	}

	/**
	 * In a print mode the script gives the ratio, 3 by default, as a number only.
	 *
	 * @return void
	 */
	public function test_the_pixel_ratio_script(): void {
		$this->assertSame(
			"Object.defineProperty(window,'devicePixelRatio',{configurable:true,get:function(){return 3;}});",
			$this->pixel_ratio( 'print', 3 )->script()
		);
		$this->assertStringContainsString( 'return 2.5;', $this->pixel_ratio( 'print', 2.5 )->script() );
		$this->assertStringContainsString( 'return 4;', $this->pixel_ratio( 'print', 4 )->script() );
		$this->assertStringContainsString( 'return 1.25;', $this->pixel_ratio( 'book', 1.25, array( 'print', 'book' ) )->script(), 'Any mode of the list.' );

		$default = new PrintPixelRatio();
		$this->assertSame( '', $default->script(), 'By default (the stubs of the tests answer web): nothing.' );
	}

	/**
	 * Nothing outside a print mode, and nothing for a ratio of 1 or one that is not a number from 1 to 4.
	 *
	 * @return void
	 */
	public function test_the_pixel_ratio_is_checked(): void {
		$this->assertSame( '', $this->pixel_ratio( 'web', 3 )->script(), 'Web mode.' );
		$this->assertSame( '', $this->pixel_ratio( 'print', 1 )->script(), 'A ratio of 1 turns it off.' );
		foreach ( array( 0, -2, 4.5, 5, '3', "3;alert('x')", 'abc', null, true, array( 3 ), NAN, INF ) as $bad ) {
			$this->assertSame( '', $this->pixel_ratio( 'print', $bad )->script(), 'Invalid: ' . var_export( $bad, true ) );
		}
		$this->assertSame( '', $this->pixel_ratio( array( 'print' ), 3 )->script(), 'A mode that is not a slug.' );
		$this->assertSame( '', $this->pixel_ratio( 'print', 3, 'print' )->script(), 'A list that is not a list.' );
	}

	/**
	 * The printed tag is the script, and nothing is printed when there is none; the script really sets the ratio.
	 *
	 * @return void
	 */
	public function test_the_pixel_ratio_is_printed(): void {
		ob_start();
		$this->pixel_ratio( 'print', 3 )->print_script();
		$out = ob_get_clean();
		$this->assertStringStartsWith( '<script>Object.defineProperty(window,', $out );

		ob_start();
		$this->pixel_ratio( 'web', 3 )->print_script();
		$this->assertSame( '', ob_get_clean() );
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

	/**
	 * Builds the fallback with doubles: the print mode, the metadata of a photo, and what `image_downsize` answers for a size.
	 *
	 * @param array  $meta     Metadata of the attachment, or null.
	 * @param string $mode     Active mode.
	 * @param array  $asked    Sizes asked of `image_downsize`.
	 * @return PrintFallback
	 */
	private function fallback( $meta, $mode = 'print', array &$asked = array() ) {
		$sizes = $this->sizes(
			array(
				'books_print_sizes' => array(
					'books-print-full'  => array( 'fraction' => 0.9 ),
					'books-print-thumb' => array(
						'fraction' => 0.3,
						'ratio'    => '16:9',
					),
				),
			)
		);

		return new PrintFallback(
			$sizes,
			static fn() => $mode,
			static fn() => array( 'print' ),
			static fn() => $meta,
			static function ( $id, $name ) use ( &$asked, $meta ) {
				$asked[] = $name;
				$entry   = $meta['sizes'][ $name ] ?? null;

				return is_array( $entry ) ? array( 'https://x.test/' . $entry['file'], $entry['width'], $entry['height'], true ) : array( 'https://x.test/original.jpg', $meta['width'], $meta['height'], false );
			}
		);
	}

	/**
	 * Metadata of a photo of 4000 x 2252 with the sizes of the site and no print size.
	 *
	 * @param array $more Other sizes.
	 * @return array
	 */
	private function photo( array $more = array() ) {
		return array(
			'width'  => 4000,
			'height' => 2252,
			'sizes'  => $more + array(
				'thumbnail'    => array(
					'file'   => 't-150x150.jpg',
					'width'  => 150,
					'height' => 150,
				),
				'gallery'      => array(
					'file'   => 'g-300x169.jpg',
					'width'  => 300,
					'height' => 169,
				),
				'medium'       => array(
					'file'   => 'm-300x169.jpg',
					'width'  => 300,
					'height' => 169,
				),
				'medium_large' => array(
					'file'   => 'ml-768x432.jpg',
					'width'  => 768,
					'height' => 432,
				),
				'large'        => array(
					'file'   => 'l-1024x576.jpg',
					'width'  => 1024,
					'height' => 576,
				),
			),
		);
	}

	/**
	 * A print size that the photo does not have: in a print mode, never the original: the narrowest size that is wide enough (the
	 * thumbnail asked at 674 px gets the 768 px), else the widest (the main photo asked at 2020 px gets the 1024 px).
	 *
	 * @return void
	 */
	public function test_a_missing_print_size_never_gives_the_original(): void {
		$asked    = array();
		$fallback = $this->fallback( $this->photo(), 'print', $asked );

		$thumb = $fallback->filter( false, 41025, 'books-print-thumb' );
		$this->assertSame( array( 'https://x.test/ml-768x432.jpg', 768, 432, true ), $thumb );

		$full = $fallback->filter( false, 41025, 'books-print-full' );
		$this->assertSame( array( 'https://x.test/l-1024x576.jpg', 1024, 576, true ), $full );

		$this->assertSame( array( 'medium_large', 'large' ), $asked );
	}

	/**
	 * What is kept: the answer of another filter, a size the photo has, the web mode, other sizes, a photo that is not wider than the
	 * size, and a photo with no other size.
	 *
	 * @return void
	 */
	public function test_the_fallback_leaves_the_other_cases_alone(): void {
		$answer = array( 'https://x.test/made.jpg', 2020, 1137, true );
		$have   = array(
			'books-print-full' => array(
				'file'   => 'f-2020x1137.jpg',
				'width'  => 2020,
				'height' => 1137,
			),
		);

		$this->assertSame( $answer, $this->fallback( $this->photo() )->filter( $answer, 1, 'books-print-full' ), 'Another filter answered (Media Helper).' );
		$this->assertFalse( $this->fallback( $this->photo( $have ) )->filter( false, 1, 'books-print-full' ), 'The photo has the size: WordPress finds it.' );
		$this->assertFalse( $this->fallback( $this->photo(), 'web' )->filter( false, 1, 'books-print-full' ), 'Web mode.' );
		$this->assertFalse( $this->fallback( $this->photo() )->filter( false, 1, 'large' ), 'Not a print size.' );
		$this->assertFalse( $this->fallback( $this->photo() )->filter( false, 1, array( 100, 100 ) ), 'A size given as an array.' );
		$this->assertFalse( $this->fallback( $this->photo() )->filter( false, 1, 'books-print-unknown' ), 'A name that is not declared.' );
		$this->assertFalse( $this->fallback( null )->filter( false, 1, 'books-print-full' ), 'No metadata.' );

		$small = array(
			'width'  => 1500,
			'height' => 844,
			'sizes'  => array(),
		);
		$this->assertFalse( $this->fallback( $small )->filter( false, 1, 'books-print-full' ), 'The original is not wider than the size (1500 < 2020): it is the right image.' );

		$bare = array(
			'width'  => 4000,
			'height' => 2252,
			'sizes'  => array(),
		);
		$this->assertFalse( $this->fallback( $bare )->filter( false, 1, 'books-print-full' ), 'A photo with no other size: nothing to give.' );
	}

	/**
	 * The print sizes of the photo are not candidates, and the answer of `image_downsize` must be a real size.
	 *
	 * @return void
	 */
	public function test_the_candidates(): void {
		$meta = $this->photo(
			array(
				'books-print-thumb' => array(
					'file'   => 'p-674x379.jpg',
					'width'  => 674,
					'height' => 379,
				),
			)
		);

		$this->assertSame(
			array( 'https://x.test/l-1024x576.jpg', 1024, 576, true ),
			$this->fallback( $meta )->filter( false, 1, 'books-print-full' ),
			'The print size that the photo has is not a candidate for another print size.'
		);

		$none = new PrintFallback(
			$this->sizes( array( 'books_print_sizes' => array( 'books-print-full' => array( 'fraction' => 0.9 ) ) ) ),
			static fn() => 'print',
			static fn() => array( 'print' ),
			fn() => $this->photo(),
			static fn() => false
		);
		$this->assertFalse( $none->filter( false, 1, 'books-print-full' ), 'When WordPress cannot give the size either: nothing changes.' );

		$default = new PrintFallback( $this->sizes( array() ) );
		$this->assertFalse( $default->filter( false, 1, 'books-print-full' ), 'By default, in the tests (mode web): nothing.' );
	}
}
