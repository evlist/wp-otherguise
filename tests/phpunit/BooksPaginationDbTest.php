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
	 * @return PaginationCommand
	 */
	private function command() {
		$record = function ( $kind ) {
			return function ( $message ) use ( $kind ) {
				$this->out[] = array( $kind, (string) $message );
			};
		};

		return new PaginationCommand( $this->sections, $record( 'log' ), $record( 'success' ), $record( 'fail' ), $record( 'warning' ), static fn( $id ) => 'Title ' . $id );
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
	 * The units are printed by page, with their title.
	 *
	 * @return void
	 */
	public function test_the_pages_of_the_units(): void {
		$file = $this->pdf(
			array(
				'unit/1'   => 2,
				'unit/101' => 3,
				'unit/3'   => 3,
				'unit/2'   => 2,
				'unit/5'   => 4,
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
	 * The format json, and the check: a unit without a marker, a marker found twice, a marker of a post that is not in the section.
	 *
	 * @return void
	 */
	public function test_json_and_the_check(): void {
		$file = $this->pdf(
			array(
				'unit/1'   => 2,
				'unit/101' => 3,
				'unit/5'   => 4,
				'unit/9'   => 4,
			)
		);

		$this->command()->__invoke(
			array( '100' ),
			array(
				'file' => $file,
				'format' => 'json',
			)
		);

		$this->assertSame( 'log', $this->out[0][0] );
		$this->assertSame(
			array(
				'pages' => 3,
				'units' => array(
					array(
						'id' => 1,
						'page' => 1,
						'title' => 'Title 1',
					),
					array(
						'id' => 101,
						'page' => 2,
						'title' => 'Title 101',
					),
					array(
						'id' => 5,
						'page' => 3,
						'title' => 'Title 5',
					),
					array(
						'id' => 9,
						'page' => 3,
						'title' => 'Title 9',
					),
				),
			),
			json_decode( $this->out[0][1], true )
		);

		$this->out = array();
		$this->command()->__invoke(
			array( '100' ),
			array(
				'file' => $file,
				'check' => true,
			)
		);
		$warnings = array_values( array_filter( $this->out, static fn( $line ) => 'warning' === $line[0] ) );

		$this->assertCount( 4, $warnings );
		$this->assertStringContainsString( 'unit 3', $warnings[0][1], 'In the order of the merge: the part comes first.' );
		$this->assertStringContainsString( 'unit 4', $warnings[1][1] );
		$this->assertStringContainsString( 'unit 2', $warnings[2][1] );
		$this->assertStringContainsString( 'post 9, which is not in the section', $warnings[3][1] );

		$this->out = array();
		$twice     = $this->pdf(
			array(
				'unit/1'   => 2,
				'unit/2'   => 2,
				'unit/101' => 2,
				'unit/3'   => 3,
				'unit/4'   => 3,
				'unit/5'   => 4,
			)
		);
		$this->command()->__invoke(
			array( '100' ),
			array(
				'file' => $twice,
				'check' => true,
			)
		);
		$this->assertSame( array( 'success', 'All 6 units of the section have a marker, found once.' ), end( $this->out ) );
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
