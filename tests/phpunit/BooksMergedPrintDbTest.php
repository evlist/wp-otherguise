<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the merge of a section with its units on a real database (slice 231).
 *
 * @package Otherguise
 */

use Otherguise\Books\Merge\MergeAccess;
use Otherguise\Books\Merge\MergedPrint;
use Otherguise\Books\Merge\MergeLog;
use Otherguise\Books\Module as BooksModule;
use Otherguise\Books\Section\Sections;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-section-posts.php';

/**
 * The book 100 holds the days 1 and 2, the part 101 (a section, that holds the days 3 and 4) and the day 5; the day 2 is a draft, the day 4
 * has a password. The order is the date: the part first. The statements are real, the posts, the request and the rendering are doubles.
 *
 * @covers \Otherguise\Books\Merge\MergedPrint
 */
class BooksMergedPrintDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The sections.
	 *
	 * @var Sections
	 */
	private $sections;

	/**
	 * The request.
	 *
	 * @var array
	 */
	private $request;

	/**
	 * The rendered units.
	 *
	 * @var int[]
	 */
	private $rendered = array();

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
			$posts->add( $id, 'Day ' . $id, '2026-05-1' . $id . ' 10:00:00', 2 === $id ? 'draft' : 'publish' );
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

		$this->request  = array(
			'print'   => true,
			'section' => 100,
			'preview' => false,
			'limit'   => 0,
		);
		$this->rendered = array();

		$GLOBALS['otherguise_test_meta'] = array();
	}

	/**
	 * Builds the merge.
	 *
	 * @param bool $allowed Whether the request may merge.
	 * @return MergedPrint
	 */
	private function merge( $allowed = true ) {
		return new MergedPrint(
			$this->sections,
			new MergeAccess( null, static fn() => 'key', static fn() => $allowed, static fn() => '' ),
			new MergeLog( static fn() => false, static fn() => '' ),
			fn() => $this->request,
			function ( $id ) {
				$this->rendered[] = $id;

				return '<p>unit ' . $id . '</p>';
			},
			static function ( $id ) {
				return array(
					'status'   => 2 === $id ? 'draft' : 'publish',
					'password' => 4 === $id ? 'secret' : '',
				);
			}
		);
	}

	/**
	 * The units come after one another in the order of the section, each in a div that starts a page; a unit that is a section is followed by
	 * its own units; a draft and a post with a password are left out.
	 *
	 * @return void
	 */
	public function test_the_units_in_order(): void {
		$html = $this->merge()->merge();

		$this->assertSame( array( 101, 3, 1, 5 ), $this->rendered, 'In date order; the part is followed by its own units; the draft and the password are skipped.' );
		$this->assertStringStartsWith( '<style>.books-merge-unit{break-before:page;}</style><div class="books-merge-unit" data-post="101" data-depth="1"><p>unit 101</p></div>', $html );
		$this->assertStringContainsString( '<div class="books-merge-unit" data-post="3" data-depth="2"><p>unit 3</p></div>', $html, 'A level deeper.' );
		$this->assertLessThan( strpos( $html, 'data-post="3"' ), strpos( $html, 'data-post="101"' ) );
		$this->assertLessThan( strpos( $html, 'data-post="1"' ), strpos( $html, 'data-post="3"' ) );
		$this->assertLessThan( strpos( $html, 'data-post="5"' ), strpos( $html, 'data-post="1"' ) );
		$this->assertMatchesRegularExpression( '/<!-- books-merge \{"section":100,"units":4,/', $html );
	}

	/**
	 * Nothing is merged for a visitor, for a page that is not a print page of a section, or for a section that holds nothing.
	 *
	 * @return void
	 */
	public function test_when_nothing_is_merged(): void {
		$this->assertSame( '', $this->merge( false )->merge(), 'Not allowed: the page of any section would cost the whole merge to anybody.' );
		$this->assertSame( array(), $this->rendered );

		foreach ( array(
			array( 'print' => false ),
			array( 'section' => 0 ),
			array( 'section' => 5 ),
		) as $change ) {
			$this->request = $change + $this->request;
			$this->assertSame( '', $this->merge()->merge(), json_encode( $change ) );
			$this->request = array(
				'print'   => true,
				'section' => 100,
				'preview' => false,
				'limit'   => 0,
			);
		}

		$this->assertSame( array(), $this->rendered );
	}

	/**
	 * The preview of the editor merges the first units only, with a note that says so; when all of them fit there is no note.
	 *
	 * @return void
	 */
	public function test_the_preview_is_bounded(): void {
		$this->request['preview']                               = true;
		$GLOBALS['otherguise_test_filters']['books_merge_preview_limit'] = 2;

		$html = $this->merge()->merge();

		$this->assertSame( array( 101, 3 ), $this->rendered, 'The first two units.' );
		$this->assertStringContainsString( '<p class="books-merge-note"', $html );
		$this->assertStringContainsString( 'Preview: the first 2 units. The PDF has all of them.', $html );

		$this->rendered = array();
		unset( $GLOBALS['otherguise_test_filters']['books_merge_preview_limit'] );
		$html = $this->merge()->merge();

		$this->assertSame( array( 101, 3, 1, 5 ), $this->rendered, 'Few units: all of them, as many as the preview allows by default.' );
		$this->assertStringNotContainsString( 'books-merge-note', $html, 'Nothing was left out: no note.' );

		$this->rendered         = array();
		$this->request['preview'] = false;
		$GLOBALS['otherguise_test_filters']['books_merge_preview_limit'] = 1;
		$this->merge()->merge();
		$this->assertSame( array( 101, 3, 1, 5 ), $this->rendered, 'The PDF is not bounded by the preview.' );
		unset( $GLOBALS['otherguise_test_filters']['books_merge_preview_limit'] );
	}

	/**
	 * The number of units: the address, else the custom field, else the option; never more than the largest merge.
	 *
	 * @return void
	 */
	public function test_the_limit(): void {
		$this->request['limit'] = 1;
		$this->merge()->merge();
		$this->assertSame( array( 101 ), $this->rendered, 'The address.' );

		$this->rendered                              = array();
		$this->request['limit']                      = 0;
		$GLOBALS['otherguise_test_meta'][100]['books_merge_limit'] = '2';
		$this->merge()->merge();
		$this->assertSame( array( 101, 3 ), $this->rendered, 'The field of the section.' );

		$this->rendered         = array();
		$this->request['limit'] = 3;
		$this->merge()->merge();
		$this->assertSame( array( 101, 3, 1 ), $this->rendered, 'The address wins over the field.' );

		$this->rendered                                            = array();
		$this->request['limit']                                    = 5000000;
		$GLOBALS['otherguise_test_meta'][100]['books_merge_limit'] = '';
		$this->merge()->merge();
		$this->assertSame( array( 101, 3, 1, 5 ), $this->rendered, 'Past the units there are: all of them (and never more than ' . MergedPrint::MAX_UNITS . ').' );
	}

	/**
	 * A post that is in two sections is shown in each (the sections cannot make a cycle: the service refuses it, and the merge stops at a section
	 * it is already following).
	 *
	 * @return void
	 */
	public function test_a_post_in_two_sections(): void {
		$this->sections->add( 101, array( 5 ) );
		$this->merge()->merge();

		$this->assertSame( array( 101, 3, 5, 1, 5 ), $this->rendered, 'The day 5 is in both sections.' );
	}

	/**
	 * The units are put in front of what the footer prints, once.
	 *
	 * @return void
	 */
	public function test_the_units_go_in_front(): void {
		$merge = $this->merge();

		$this->assertSame( 'FOOTER', $merge->prepend( 'FOOTER' ), 'Nothing to put in front before the merge.' );

		$reflection = new ReflectionProperty( MergedPrint::class, 'html' );
		$reflection->setAccessible( true );
		$reflection->setValue( $merge, '<div>units</div>' );

		$this->assertSame( '<div>units</div>FOOTER', $merge->prepend( 'FOOTER' ) );
		$this->assertSame( 'MORE', $merge->prepend( 'MORE' ), 'Once: another call of the callback does not put them again.' );
	}
}
