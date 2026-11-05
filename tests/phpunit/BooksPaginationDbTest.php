<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the command that prints the page of each unit in a PDF (slice 227).
 *
 * @package Otherguise
 */

use Otherguise\Books\Cli\PaginationCommand;
use Otherguise\Books\Module as BooksModule;
use Otherguise\Books\Section\Sections;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-section-posts.php';

/**
 * The book 100 holds the days 1 and 2, the part 101 (a section, that holds the days 3 and 4) and the day 5. The statements are real; the files
 * are PDFs written by hand.
 *
 * @covers \Otherguise\Books\Cli\PaginationCommand
 */
class BooksPaginationDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The sections.
	 *
	 * @var Sections
	 */
	private $sections;

	/**
	 * What the command printed.
	 *
	 * @var array<int, array{string, string}>
	 */
	private $out = array();

	/**
	 * Files to remove.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Builds the site, the sections and the posts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		otherguise_test_wp_objects( array() );

		$books = new BooksModule();
		$site  = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $books ) {
					$books->register_predicates( $registry );
				},
			)
		);
		$posts = new Otherguise_Test_Section_Posts();

		foreach ( array( 100, 101 ) as $id ) {
			$posts->add( $id, 'Section ' . $id, '2026-05-01 08:00:00', 'publish', 'page' );
		}

		foreach ( array( 1, 2, 3, 4, 5 ) as $id ) {
			$posts->add( $id, 'Day ' . $id, '2026-05-1' . $id . ' 10:00:00' );
		}

		$statements     = $site->statements;
		$this->sections = new Sections(
			static function () use ( $statements ) {
				return $statements;
			},
			$posts
		);
		$this->sections->add( 100, array( 1, 2, 101, 5 ) );
		$this->sections->add( 101, array( 3, 4 ) );

		$this->out = array();
	}

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

		parent::tearDown();
	}

	/**
	 * Builds the command.
	 *
	 * @param callable|null $manifest Reads a manifest by its key; none by default.
	 * @return PaginationCommand
	 */
	private function command( $manifest = null ) {
		$record = function ( $kind ) {
			return function ( $message ) use ( $kind ) {
				$this->out[] = array( $kind, (string) $message );
			};
		};

		return new PaginationCommand( $this->sections, $record( 'log' ), $record( 'success' ), $record( 'fail' ), $record( 'warning' ), static fn( $id ) => 'Title ' . $id, $manifest );
	}

	/**
	 * A PDF of three pages with the markers given: unit => the number of the page object that holds it.
	 *
	 * @param array<string, int> $markers Name of the marker => page object (2, 3 or 4).
	 * @return string
	 */
	private function pdf( array $markers ) {
		$objects = array();
		$annots  = array(
			2 => array(),
			3 => array(),
			4 => array(),
		);
		$number  = 10;

		foreach ( $markers as $name => $page ) {
			$objects[]         = $number . ' 0 obj <</Type /Annot /Subtype /Link /F 4 /A <</Type /Action /S /URI /URI (https://marker.invalid/' . $name . ')>>>> endobj';
			$annots[ $page ][] = $number . ' 0 R';
			++$number;
		}

		foreach ( $annots as $page => $list ) {
			$objects[] = $page . ' 0 obj <</Type /Page /MediaBox [0 0 420 594]' . ( array() === $list ? '' : ' /Annots [' . implode( ' ', $list ) . ']' ) . ' /Parent 19 0 R>> endobj';
		}

		$objects[] = '19 0 obj <</Type /Pages /Count 3 /Kids [2 0 R 3 0 R 4 0 R]>> endobj';
		$path      = (string) tempnam( sys_get_temp_dir(), 'books-pdf-' );

		file_put_contents( $path, "%PDF-1.4\n" . implode( "\n", $objects ) );
		$this->files[] = $path;

		return $path;
	}

	/**
	 * The page of a unit is the page of its first heading; units are printed by page, with their title; the page of the section itself comes first.
	 *
	 * @return void
	 */
	public function test_the_pages_of_the_units(): void {
		$file = $this->pdf(
			array(
				'heading/1/1'   => 2,
				'heading/101/1' => 3,
				'heading/3/1'   => 3,
				'heading/2/1'   => 2,
				'heading/5/1'   => 4,
			)
		);

		$this->command()->__invoke( array( '100' ), array( 'file' => $file ) );

		$this->assertSame(
			array(
				array( 'log', "1\t1\tTitle 1" ),
				array( 'log', "2\t1\tTitle 2" ),
				array( 'log', "3\t2\tTitle 3" ),
				array( 'log', "101\t2\tTitle 101" ),
				array( 'log', "5\t3\tTitle 5" ),
				array( 'success', '5 units in 3 pages.' ),
			),
			$this->out
		);
	}

	/**
	 * The first heading gives the page, whatever order the markers were found in; `--headings` prints the page of every heading; the markers of the
	 * units that older files had are ignored.
	 *
	 * @return void
	 */
	public function test_the_first_heading_gives_the_page(): void {
		$file = $this->pdf(
			array(
				'unit/1'      => 2,
				'heading/1/1' => 3,
				'heading/1/2' => 4,
				'heading/5/2' => 4,
				'heading/5/1' => 3,
				'heading/2/1' => 3,
			)
		);

		$this->command()->__invoke(
			array( '100' ),
			array(
				'file'     => $file,
				'headings' => true,
			)
		);

		$this->assertSame(
			array(
				array( 'log', "1\t2\tTitle 1" ),
				array( 'log', "1#1\t2" ),
				array( 'log', "1#2\t3" ),
				array( 'log', "2\t2\tTitle 2" ),
				array( 'log', "2#1\t2" ),
				array( 'log', "5\t2\tTitle 5" ),
				array( 'log', "5#1\t2" ),
				array( 'log', "5#2\t3" ),
				array( 'warning', 'No manifest for this PDF (made before the manifests, or removed): the level and the text of the headings are not shown.' ),
				array( 'success', '3 units in 3 pages.' ),
			),
			$this->out
		);

		$this->out = array();
		$this->command()->__invoke(
			array( '100' ),
			array(
				'file'     => $file,
				'headings' => true,
				'format'   => 'json',
			)
		);
		$json = json_decode( $this->out[0][1], true );

		$this->assertSame(
			array(
				1 => 2,
				2 => 3,
			),
			$json['units'][0]['headings']
		);
	}

	/**
	 * The headings of the page of the section itself (the markers with its id) are listed with it, and it is not counted as one of its units or
	 * reported by the check.
	 *
	 * @return void
	 */
	public function test_the_headings_of_the_section_itself(): void {
		$file = $this->pdf(
			array(
				'heading/100/1' => 2,
				'heading/100/2' => 3,
				'heading/1/1'   => 3,
				'heading/2/1'   => 3,
				'heading/101/1' => 3,
				'heading/3/1'   => 4,
				'heading/4/1'   => 4,
				'heading/5/1'   => 4,
			)
		);

		$this->command()->__invoke(
			array( '100' ),
			array(
				'file'     => $file,
				'headings' => true,
				'check'    => true,
			)
		);

		$this->assertSame( array( 'log', "100\t1\tTitle 100" ), $this->out[0] );
		$this->assertSame( array( 'log', "100#1\t1" ), $this->out[1] );
		$this->assertSame( array( 'log', "100#2\t2" ), $this->out[2] );
		$this->assertSame( array( 'success', 'All 6 units of the section have a page, from their headings.' ), end( $this->out ) );
		$this->assertSame(
			array( 'No manifest for this PDF (made before the manifests, or removed): the level and the text of the headings are not shown.' ),
			array_values( array_column( array_filter( $this->out, static fn( $line ) => 'warning' === $line[0] ), 1 ) ),
			'No other warning.'
		);
	}

	/**
	 * The check: a unit with no heading marker (no manifest: could be a draft or a unit without a heading), a heading marker on several pages, a
	 * heading of a post that is not in the section.
	 *
	 * @return void
	 */
	public function test_the_check_without_a_manifest(): void {
		$file = $this->pdf(
			array(
				'heading/1/1'   => 2,
				'heading/101/1' => 3,
				'heading/5/1'   => 4,
				'heading/9/1'   => 4,
			)
		);

		$this->command()->__invoke(
			array( '100' ),
			array(
				'file'  => $file,
				'check' => true,
			)
		);

		$warnings = array_column( array_filter( $this->out, static fn( $line ) => 'warning' === $line[0] ), 1 );

		$this->assertCount( 4, $warnings );
		$this->assertStringContainsString( 'No heading marker for the unit 3 ', $warnings[0], 'In the order of the merge: the part comes first.' );
		$this->assertStringContainsString( 'No heading marker for the unit 4 ', $warnings[1] );
		$this->assertStringContainsString( 'No heading marker for the unit 2 ', $warnings[2] );
		$this->assertStringContainsString( 'headings of the post 9, which is not in the section', $warnings[3] );
	}

	/**
	 * With the manifest, the check tells the units that were merged but have no heading found from those that were not merged.
	 *
	 * @return void
	 */
	public function test_the_check_with_a_manifest(): void {
		$key      = md5( 'units' );
		$manifest = array(
			'section'  => 100,
			'units'    => array(
				array(
					'id'    => 101,
					'depth' => 1,
				),
				array(
					'id'    => 3,
					'depth' => 2,
				),
				array(
					'id'    => 1,
					'depth' => 1,
				),
			),
			'headings' => array(
				array(
					'unit'  => 101,
					'n'     => 1,
					'level' => 1,
					'text'  => 'Part',
					'toc'   => true,
					'depth' => 1,
				),
				array(
					'unit'  => 1,
					'n'     => 1,
					'level' => 1,
					'text'  => 'Day 1',
					'toc'   => true,
					'depth' => 1,
				),
			),
		);
		$file     = $this->pdf(
			array(
				'manifest/' . $key => 2,
				'heading/101/1'    => 2,
				'heading/1/1'      => 3,
				'heading/1/2'      => 3,
				'heading/9/1'      => 4,
			)
		);
		$read     = static fn( $asked ) => $asked === $key ? wp_json_encode( $manifest ) : null;

		$this->command( $read )->__invoke(
			array( '100' ),
			array(
				'file'  => $file,
				'check' => true,
			)
		);

		$warnings = array_column( array_filter( $this->out, static fn( $line ) => 'warning' === $line[0] ), 1 );

		$this->assertContains( 'The unit 3 is in the PDF but none of its headings was found: it has no page (no heading, or the headings are not printed).', $warnings );
		$this->assertContains( 'The unit 4 is not in the PDF (a draft, a post with a password, or a unit left out by a limit).', $warnings );
		$this->assertContains( 'The unit 2 is not in the PDF (a draft, a post with a password, or a unit left out by a limit).', $warnings );
		$this->assertContains( 'The unit 5 is not in the PDF (a draft, a post with a password, or a unit left out by a limit).', $warnings );
		$this->assertContains( 'The headings of the post 9, which is not in the section.', $warnings );
		$this->assertCount( 5, $warnings );
	}

	/**
	 * When the first heading of a unit is not printed, the page is the one of a later heading: the check says so.
	 *
	 * @return void
	 */
	public function test_the_first_heading_is_not_printed(): void {
		$key      = md5( 'hidden' );
		$manifest = array(
			'section'  => 100,
			'units'    => array(),
			'headings' => array(
				array(
					'unit'  => 1,
					'n'     => 1,
					'level' => 1,
					'text'  => 'Title',
					'toc'   => true,
					'depth' => 1,
				),
				array(
					'unit'  => 1,
					'n'     => 2,
					'level' => 2,
					'text'  => 'Later',
					'toc'   => true,
					'depth' => 1,
				),
			),
		);
		$file     = $this->pdf(
			array(
				'manifest/' . $key => 2,
				'heading/1/2'      => 3,
			)
		);

		$this->command( static fn( $asked ) => $asked === $key ? wp_json_encode( $manifest ) : null )->__invoke(
			array( '100' ),
			array(
				'file'  => $file,
				'check' => true,
			)
		);

		$this->assertContains(
			'The first heading of the post 1 (n° 1) is not in the PDF: the page given for it is the page of its heading n° 2.',
			array_column( array_filter( $this->out, static fn( $line ) => 'warning' === $line[0] ), 1 )
		);
	}

	/**
	 * When every unit has a heading on a page, the check has nothing to say.
	 *
	 * @return void
	 */
	public function test_the_check_when_all_is_well(): void {
		$file = $this->pdf(
			array(
				'heading/1/1'   => 2,
				'heading/2/1'   => 2,
				'heading/101/1' => 2,
				'heading/3/1'   => 3,
				'heading/4/1'   => 3,
				'heading/5/1'   => 4,
			)
		);
		$this->command()->__invoke(
			array( '100' ),
			array(
				'file'  => $file,
				'check' => true,
			)
		);
		$this->assertSame( array( 'success', 'All 6 units of the section have a page, from their headings.' ), end( $this->out ) );
	}

	/**
	 * With the manifest of the PDF (its marker names it), `--headings` shows the level and the text of each heading, `--check` reports a level that
	 * is skipped, a heading above the first one of its post, a heading with no text; a heading left out of the table of contents is not looked at.
	 *
	 * @return void
	 */
	public function test_the_manifest_gives_the_levels_and_the_checks(): void {
		$key      = md5( 'manifest' );
		$manifest = array(
			'section'  => 100,
			'headings' => array(
				array(
					'unit' => 100,
					'n' => 1,
					'level' => 1,
					'text' => 'Book',
					'toc' => true,
					'depth' => 0,
				),
				array(
					'unit' => 1,
					'n' => 1,
					'level' => 1,
					'text' => 'Day 1',
					'toc' => true,
					'depth' => 1,
				),
				array(
					'unit' => 1,
					'n' => 2,
					'level' => 3,
					'text' => 'Skipped',
					'toc' => true,
					'depth' => 1,
				),
				array(
					'unit' => 1,
					'n' => 3,
					'level' => 2,
					'text' => 'Fine',
					'toc' => true,
					'depth' => 1,
				),
				array(
					'unit' => 1,
					'n' => 4,
					'level' => 5,
					'text' => 'Left out',
					'toc' => false,
					'depth' => 1,
				),
				array(
					'unit' => 2,
					'n' => 1,
					'level' => 2,
					'text' => 'Starts at 2',
					'toc' => true,
					'depth' => 1,
				),
				array(
					'unit' => 2,
					'n' => 2,
					'level' => 1,
					'text' => 'Above',
					'toc' => true,
					'depth' => 1,
				),
				array(
					'unit' => 2,
					'n' => 3,
					'level' => 2,
					'text' => '',
					'toc' => true,
					'depth' => 1,
				),
			),
		);
		$file     = $this->pdf(
			array(
				'manifest/' . $key => 2,
				'heading/100/1'    => 2,
				'heading/1/1'      => 2,
				'heading/1/2'      => 2,
				'heading/1/3'      => 3,
				'heading/1/4'      => 3,
				'heading/2/1'      => 3,
				'heading/2/2'      => 4,
				'heading/2/3'      => 4,
				'heading/101/1'    => 4,
				'heading/3/1'      => 4,
				'heading/4/1'      => 4,
				'heading/5/1'      => 4,
			)
		);
		$read     = static fn( $asked ) => $asked === $key ? wp_json_encode( $manifest ) : null;

		$this->command( $read )->__invoke(
			array( '100' ),
			array(
				'file' => $file,
				'headings' => true,
				'check' => true,
			)
		);

		$lines = array_column( array_filter( $this->out, static fn( $line ) => 'log' === $line[0] ), 1 );

		$this->assertContains( "100#1\t1\th1\tBook", $lines );
		$this->assertContains( "1#2\t1\th3\tSkipped", $lines );
		$this->assertContains( "1#4\t2\th5\tLeft out\t(no-toc)", $lines );

		$warnings = array_column( array_filter( $this->out, static fn( $line ) => 'warning' === $line[0] ), 1 );

		$this->assertContains( 'The heading 2 (h3) of the post 1 follows an h1: a level is skipped.', $warnings );
		$this->assertContains( 'The heading 2 (h1) of the post 2 is above the first heading of the post (h2).', $warnings );
		$this->assertContains( 'The heading 3 (h2) of the post 2 has no text.', $warnings );
		$this->assertCount( 3, array_filter( $warnings, static fn( $text ) => str_starts_with( $text, 'The heading' ) ), 'The heading left out is not looked at.' );

		$this->out = array();
		$this->command()->__invoke(
			array( '100' ),
			array(
				'file' => $file,
				'headings' => true,
				'check' => true,
			)
		);

		$lines = array_column( array_filter( $this->out, static fn( $line ) => 'log' === $line[0] ), 1 );

		$this->assertContains( "100#1\t1", $lines, 'Without the manifest: the pages only.' );
		$this->assertStringContainsString( 'No manifest for this PDF', implode( ' ', array_column( array_filter( $this->out, static fn( $line ) => 'warning' === $line[0] ), 1 ) ) );
	}

	/**
	 * The title is plain text: the entities that WordPress writes in a title are decoded.
	 *
	 * @return void
	 */
	public function test_a_title_is_plain_text(): void {
		$this->assertSame( 'Pérols – Tautavel', PaginationCommand::plain( 'Pérols &#8211; Tautavel' ) );
		$this->assertSame( 'Pic d’Orhy & co', PaginationCommand::plain( 'Pic d&rsquo;Orhy &amp; co' ) );
		$this->assertSame( 'Bold', PaginationCommand::plain( '<b>Bold</b>' ) );
	}

	/**
	 * The refusals: not a section, no file, a format that is not known, a file that is not a PDF, a PDF without a marker.
	 *
	 * @return void
	 */
	public function test_the_refusals(): void {
		$file = $this->pdf( array() );

		foreach ( array(
			array( array( '5' ), array( 'file' => $file ), 'Not a section' ),
			array( array(), array( 'file' => $file ), 'Not a section' ),
			array( array( '100' ), array(), '--file' ),
			array( array( '100' ), array( 'file' => '/nonexistent.pdf' ), '--file' ),
			array(
				array( '100' ),
				array(
					'file' => $file,
					'format' => 'xml',
				),
				'--format',
			),
			array( array( '100' ), array( 'file' => $file ), 'No marker' ),
		) as $case ) {
			$this->out = array();
			$this->command()->__invoke( $case[0], $case[1] );

			$this->assertSame( 'fail', end( $this->out )[0], json_encode( $case ) );
			$this->assertStringContainsString( $case[2], end( $this->out )[1] );
		}

		$text = (string) tempnam( sys_get_temp_dir(), 'books-txt-' );
		file_put_contents( $text, 'not a pdf' );
		$this->files[] = $text;
		$this->out     = array();
		$this->command()->__invoke( array( '100' ), array( 'file' => $text ) );

		$this->assertStringContainsString( 'No page found', end( $this->out )[1] );
	}
}
