<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the passes that number the tables of contents (slice 228).
 *
 * @package Otherguise
 */

use Otherguise\Books\Pdf\Passes;
use Otherguise\Books\Pdf\PdfStore;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: a store in a temporary directory and PDFs written by hand, with the markers that Chromium would write.
 *
 * @covers \Otherguise\Books\Pdf\Passes
 */
class BooksPassesTest extends TestCase {

	/**
	 * Directory of the store.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Makes a store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/books-passes-test-' . uniqid();
	}

	/**
	 * Removes what the test wrote.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( (array) glob( $this->directory . '/{*,.[!.]*}', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		if ( is_dir( $this->directory ) ) {
			rmdir( $this->directory );
		}
	}

	/**
	 * A PDF with the markers given, as the objects that Chromium writes: the markers of the first page, then the second.
	 *
	 * @param string[] $page1 Markers of the first page.
	 * @param string[] $page2 Markers of the second page.
	 * @return string
	 */
	private function pdf( array $page1, array $page2 = array() ) {
		$objects = array();
		$number  = 10;
		$annots  = array();

		foreach ( array(
			2 => $page1,
			3 => $page2,
		) as $page => $names ) {
			$annots[ $page ] = array();

			foreach ( $names as $name ) {
				$objects[]         = $number . ' 0 obj <</Type /Annot /Subtype /Link /F 4 /A <</Type /Action /S /URI /URI (https://marker.invalid/' . $name . ')>>>> endobj';
				$annots[ $page ][] = $number . ' 0 R';
				++$number;
			}
		}

		foreach ( $annots as $page => $list ) {
			$objects[] = $page . ' 0 obj <</Type /Page /MediaBox [0 0 420 594]' . ( array() === $list ? '' : ' /Annots [' . implode( ' ', $list ) . ']' ) . ' /Parent 19 0 R>> endobj';
		}

		$objects[] = '19 0 obj <</Type /Pages /Count 2 /Kids [2 0 R 3 0 R]>> endobj';

		return "%PDF-1.4\n" . implode( "\n", $objects );
	}

	/**
	 * Runs the passes with a converter that makes the PDFs given, one per pass.
	 *
	 * @param string[]   $pdfs     What each pass makes.
	 * @param bool       $numbered Whether the tables are numbered.
	 * @param string[]   $given    Receives the keys of the maps that the passes were given.
	 * @param int        $max      The most passes.
	 * @param array|null $seed The map of an earlier PDF.
	 * @return array
	 */
	private function passes( array $pdfs, $numbered, array &$given, $max = Passes::MAX, $seed = null ) {
		$store = new PdfStore( $this->directory );
		$path  = $this->directory . '.pdf';
		$made  = ( new Passes( $store ) )->run(
			static function ( $key ) use ( &$pdfs, &$given, $path ) {
				$given[] = $key;
				$pdf     = array_shift( $pdfs );

				if ( $pdf instanceof WP_Error || ( is_string( $pdf ) && str_starts_with( $pdf, 'error:' ) ) ) {
					return $pdf;
				}

				file_put_contents( $path, $pdf );

				return true;
			},
			$path,
			$numbered,
			$max,
			$seed
		);

		if ( is_file( $path ) ) {
			unlink( $path );
		}

		return $made;
	}

	/**
	 * A manifest in the store that says there is a table of contents with numbers, and its marker.
	 *
	 * @param int $tocs Number of numbered tables.
	 * @return string The marker.
	 */
	private function manifest( $tocs = 1 ) {
		$store = new PdfStore( $this->directory );
		$key   = md5( 'manifest' . $tocs );

		$store->write_manifest( $key, '{"tocs":' . $tocs . '}' );

		return 'manifest/' . $key;
	}

	/**
	 * Without numbers, or without a table that has numbers, there is one pass.
	 *
	 * @return void
	 */
	public function test_one_pass_when_nothing_is_to_settle(): void {
		$given = array();

		$this->assertSame(
			array(
				'ok'     => true,
				'error'  => '',
				'passes' => 1,
				'stable' => true,
				'map'    => null,
			),
			$this->passes( array( $this->pdf( array( $this->manifest(), 'heading/1/1' ) ) ), false, $given ),
			'Not numbered.'
		);
		$this->assertSame( array( '' ), $given );

		$given = array();
		$made  = $this->passes( array( $this->pdf( array( $this->manifest( 0 ), 'heading/1/1' ) ) ), true, $given );

		$this->assertSame( 1, $made['passes'], 'A manifest without a table with numbers.' );
		$this->assertTrue( $made['stable'] );

		$given = array();
		$made  = $this->passes( array( $this->pdf( array( 'heading/1/1' ) ) ), true, $given );

		$this->assertSame( 1, $made['passes'], 'No manifest in the PDF.' );
		$this->assertSame( 1, $this->passes( array( '%PDF-1.4 nothing' ), true, $given )['passes'], 'Not a PDF that can be read.' );
	}

	/**
	 * A table with numbers: the first pass finds the pages, the second prints them and finds the same: two passes, and the second was given the map.
	 *
	 * @return void
	 */
	public function test_two_passes_when_the_pagination_holds(): void {
		$same  = $this->pdf( array( $this->manifest(), 'heading/1/1' ), array( 'heading/1/2', 'heading/7/1' ) );
		$given = array();
		$made  = $this->passes( array( $same, $same ), true, $given );

		$this->assertSame( 2, $made['passes'] );
		$this->assertTrue( $made['stable'] );
		$this->assertSame( '', $given[0], 'The first pass has no map.' );

		$map = json_encode(
			array(
				'1#1' => 1,
				'1#2' => 2,
				'7#1' => 2,
			)
		);

		$this->assertSame( md5( $map ), $given[1], 'The second pass is given the key of the map of the first.' );
		$this->assertSame( $map, ( new PdfStore( $this->directory ) )->read_manifest( $given[1] ), 'The map is in the store.' );
	}

	/**
	 * The pagination moves when the numbers are printed: a third pass, with the map of the second.
	 *
	 * @return void
	 */
	public function test_three_passes_when_the_pagination_moves_once(): void {
		$first = $this->pdf( array( $this->manifest(), 'heading/1/1' ), array( 'heading/1/2' ) );
		$moved = $this->pdf( array( $this->manifest(), 'heading/1/1', 'heading/1/2' ) );
		$given = array();
		$made  = $this->passes( array( $first, $moved, $moved ), true, $given );

		$this->assertSame( 3, $made['passes'] );
		$this->assertTrue( $made['stable'] );
		$this->assertCount( 3, array_unique( $given ), 'Each pass has its own map.' );
	}

	/**
	 * A pagination that does not settle: the PDF of the last pass is kept and the answer says that the numbers may be wrong.
	 *
	 * @return void
	 */
	public function test_a_pagination_that_does_not_settle(): void {
		$one   = $this->pdf( array( $this->manifest(), 'heading/1/1' ), array( 'heading/1/2' ) );
		$two   = $this->pdf( array( $this->manifest(), 'heading/1/1', 'heading/1/2' ) );
		$given = array();
		$made  = $this->passes( array( $one, $two, $one, $two ), true, $given );

		$this->assertTrue( $made['ok'] );
		$this->assertSame( 3, $made['passes'], 'Three passes at most.' );
		$this->assertFalse( $made['stable'] );

		$given = array();
		$made  = $this->passes( array( $one ), true, $given, 1 );

		$this->assertSame( 1, $made['passes'] );
		$this->assertFalse( $made['stable'], 'With one pass the numbers are not settled.' );
	}

	/**
	 * A pass that fails stops the others, with its message.
	 *
	 * @return void
	 */
	public function test_a_failure_stops_the_passes(): void {
		$ok    = $this->pdf( array( $this->manifest(), 'heading/1/1' ) );
		$given = array();
		$made  = $this->passes( array( $ok, new WP_Error( 'x', 'The server answered 500.' ) ), true, $given );

		$this->assertFalse( $made['ok'] );
		$this->assertSame( 'The server answered 500.', $made['error'] );
		$this->assertSame( 2, $made['passes'] );

		$given = array();
		$made  = $this->passes( array( 'error: no server' ), false, $given );

		$this->assertSame( 'error: no server', $made['error'] );
		$this->assertSame( 1, $made['passes'] );
	}

	/**
	 * A seed is printed by the first pass; when the pagination did not move that pass is the last, else the passes go on from it; a seed does not
	 * change a PDF with no table to number, nor a PDF that is not numbered. The answer has the map to keep.
	 *
	 * @return void
	 */
	public function test_a_seed_saves_a_pass(): void {
		$page = $this->pdf( array( $this->manifest(), 'heading/1/1' ), array( 'heading/1/2' ) );
		$seed = array(
			'1#1' => 1,
			'1#2' => 2,
		);
		$given = array();
		$made  = $this->passes( array( $page ), true, $given, Passes::MAX, $seed );

		$this->assertSame( 1, $made['passes'], 'The seed was right.' );
		$this->assertTrue( $made['stable'] );
		$this->assertSame( $seed, $made['map'] );
		$this->assertSame( md5( json_encode( $seed ) ), $given[0], 'The first pass is given the seed.' );

		$given = array();
		$wrong = array(
			'1#1' => 1,
			'1#2' => 1,
		);
		$made  = $this->passes( array( $page, $page ), true, $given, Passes::MAX, $wrong );

		$this->assertSame( 2, $made['passes'], 'A seed that was wrong costs the pass that was needed anyway.' );
		$this->assertTrue( $made['stable'] );
		$this->assertSame( $seed, $made['map'] );

		$given = array();
		$made  = $this->passes( array( $this->pdf( array( 'heading/1/1' ) ) ), true, $given, Passes::MAX, $seed );

		$this->assertSame( 1, $made['passes'], 'No table to number: one pass.' );
		$this->assertNull( $made['map'], 'Nothing to keep.' );

		$given = array();
		$made  = $this->passes( array( $page ), false, $given, Passes::MAX, $seed );

		$this->assertSame( array( '' ), $given, 'Not numbered: no seed.' );
		$this->assertNull( $made['map'] );
	}
}
