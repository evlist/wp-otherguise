<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the reader of the marker links of a PDF (slice 227).
 *
 * @package Otherguise
 */

use Otherguise\Books\Pdf\PdfMarkers;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: small PDFs written by hand, with the objects as Chromium writes them.
 *
 * @covers \Otherguise\Books\Pdf\PdfMarkers
 */
class BooksPdfMarkersTest extends TestCase {

	/**
	 * Files to remove.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Removes the files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	/**
	 * Writes a PDF made of objects.
	 *
	 * @param string[] $objects The objects, as text.
	 * @param string   $stream  Bytes put before them, as the content of a page is.
	 * @return string The path.
	 */
	private function file( array $objects, $stream = '' ) {
		$path          = (string) tempnam( sys_get_temp_dir(), 'books-pdf-' );
		$this->files[] = $path;

		file_put_contents( $path, "%PDF-1.4\n" . $stream . implode( "\n", $objects ) . "\n%%EOF" );

		return $path;
	}

	/**
	 * A link annotation, as Chromium writes it.
	 *
	 * @param int    $number Object number.
	 * @param string $uri    The address.
	 * @return string
	 */
	private function link( $number, $uri ) {
		return $number . ' 0 obj <</Type /Annot /Subtype /Link /F 4 /Border [0 0 0] /Rect [34.5 519.9 35.2 520.7] /A <</Type /Action /S /URI /URI (' . $uri . ')>>>> endobj';
	}

	/**
	 * A page.
	 *
	 * @param int   $number Object number.
	 * @param int   $parent Number of its node.
	 * @param int[] $annots Numbers of its annotations.
	 * @return string
	 */
	private function page( $number, $parent, array $annots = array() ) {
		$list = array() === $annots ? '' : ' /Annots [' . implode( ' ', array_map( static fn( $n ) => $n . ' 0 R', $annots ) ) . ']';

		return $number . ' 0 obj <</Type /Page /Resources <</ProcSet [/PDF /Text] /Font <</F4 4 0 R>>>> /MediaBox [0 0 420 594]' . $list . ' /Contents 99 0 R /Parent ' . $parent . ' 0 R>> endobj';
	}

	/**
	 * A node of the page tree.
	 *
	 * @param int      $number Object number.
	 * @param int[]    $kids   Numbers of the kids.
	 * @param int|null $parent Number of its node.
	 * @return string
	 */
	private function node( $number, array $kids, $parent = null ) {
		return $number . ' 0 obj <</Type /Pages /Count ' . count( $kids ) . ' /Kids [' . implode( ' ', array_map( static fn( $n ) => $n . ' 0 R', $kids ) ) . ']' . ( null === $parent ? '' : ' /Parent ' . $parent . ' 0 R' ) . '>> endobj';
	}

	/**
	 * The page of each marker, in the order of the page tree and not of the file; a link that is not a marker is left out.
	 *
	 * @return void
	 */
	public function test_the_pages_of_the_markers(): void {
		$path = $this->file(
			array(
				$this->link( 6, 'https://marker.invalid/heading/1/1' ),
				$this->link( 7, 'https://marker.invalid/heading/2/1' ),
				$this->link( 8, 'https://example.org/elsewhere' ),
				$this->page( 2, 19, array( 6, 8 ) ),
				$this->page( 9, 19, array( 7 ) ),
				$this->page( 11, 19 ),
				$this->node( 19, array( 11, 2, 9 ) ),
			)
		);

		$this->assertSame(
			array(
				'pages'   => 3,
				'markers' => array(
					'heading/1/1' => array( 2 ),
					'heading/2/1' => array( 3 ),
				),
			),
			PdfMarkers::read( $path ),
			'The tree gives the order: the object 11 is the first page.'
		);
	}

	/**
	 * The same answer whatever the size of the pieces (a mark cut by the end of one is whole in the next), with bytes that look like objects in a stream.
	 *
	 * @return void
	 */
	public function test_the_size_of_the_pieces(): void {
		$stream = '5 0 obj <</Length 40>> stream' . "\n" . str_repeat( "\x00\xff/Type /Page 1 0 R \n", 300 ) . "\nendstream endobj\n";
		$path   = $this->file(
			array(
				$this->link( 6, 'https://marker.invalid/heading/12/1' ),
				$this->page( 2, 19, array( 6 ) ),
				$this->page( 9, 19 ),
				$this->node( 19, array( 2, 9 ) ),
			),
			$stream
		);
		$first  = PdfMarkers::read( $path );

		$this->assertSame( 2, $first['pages'] );

		foreach ( array( 1, 7, 100, 513, 4096 ) as $chunk ) {
			$this->assertSame( $first, PdfMarkers::read( $path, $chunk ), 'Piece of ' . $chunk . ' bytes.' );
		}
	}

	/**
	 * A tree of several levels; a marker found on two pages is listed on both.
	 *
	 * @return void
	 */
	public function test_nested_nodes_and_a_marker_twice(): void {
		$path = $this->file(
			array(
				$this->link( 6, 'https://marker.invalid/heading/1/1' ),
				$this->link( 7, 'https://marker.invalid/heading/1/1' ),
				$this->page( 2, 20 ),
				$this->page( 3, 20, array( 6 ) ),
				$this->page( 4, 21 ),
				$this->page( 5, 21, array( 7 ) ),
				$this->node( 20, array( 2, 3 ), 30 ),
				$this->node( 21, array( 4, 5 ), 30 ),
				$this->node( 30, array( 21, 20 ) ),
			)
		);

		$this->assertSame(
			array(
				'pages'   => 4,
				'markers' => array( 'heading/1/1' => array( 2, 4 ) ),
			),
			PdfMarkers::read( $path )
		);
	}

	/**
	 * Without a page tree the pages come in the order of the file; without a page, a missing file, an empty file: null; no marker: an empty list.
	 *
	 * @return void
	 */
	public function test_the_other_cases(): void {
		$path = $this->file( array( $this->page( 2, 19 ), $this->page( 3, 19 ) ) );

		$this->assertSame(
			array(
				'pages'   => 2,
				'markers' => array(),
			),
			PdfMarkers::read( $path )
		);

		$this->assertNull( PdfMarkers::read( $this->file( array( '1 0 obj <</Type /Catalog>> endobj' ) ) ) );
		$this->assertNull( PdfMarkers::read( '/nonexistent/file.pdf' ) );
		$this->assertNull( PdfMarkers::read( $this->file( array() ) ) );
		$this->assertNull( PdfMarkers::read( $path, 0 ) );
		$this->assertSame( 'https://marker.invalid/heading/7/1', PdfMarkers::address( 'heading/7/1' ) );
	}
}
