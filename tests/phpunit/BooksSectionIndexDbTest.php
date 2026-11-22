<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the block of the index of a section inside the merge (slice 611), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Books\Block\SectionIndexBlock;
use Otherguise\Books\Block\SectionMergeBlock;
use Otherguise\Books\Block\SectionTocBlock;
use Otherguise\Books\Merge\MergeAccess;
use Otherguise\Books\Merge\MergedPrint;
use Otherguise\Books\Merge\MergeLog;
use Otherguise\Books\Module as BooksModule;
use Otherguise\Books\Section\Sections;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-section-posts.php';

/**
 * The book 100 holds the days 1 and 2, the part 101 (a section that holds the days 3 and 4) and the day 5; the day 2 is a draft and the day 4 has
 * a password, so the posts of the merge are 101, 3, 1 and 5, in the order of the dates (the part first). The statements are real; the module of the index is a closure that answers the
 * filter `index_build`.
 *
 * @covers \Otherguise\Books\Merge\MergedPrint
 * @covers \Otherguise\Books\Merge\MergeRecord
 * @covers \Otherguise\Books\Merge\IndexSlots
 * @covers \Otherguise\Books\Block\SectionIndexBlock
 */
class BooksSectionIndexDbTest extends Otherguise_Test_Database_Case {

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
	 * What the filter `index_build` was asked: the posts and the options.
	 *
	 * @var array<int, array>
	 */
	private $asked = array();

	/**
	 * Builds the site, the sections and the posts, and the answer of the module of the index.
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

		$this->request = array(
			'section' => 100,
			'preview' => false,
			'limit'   => 0,
		);
		$this->asked   = array();

		$GLOBALS['otherguise_test_meta']                  = array();
		$GLOBALS['otherguise_test_filters']['index_build'] = function ( $value, $posts, $options ) {
			$this->asked[] = array( $posts, $options );

			return array(
				'groups'      => array(
					'B' => array(
						array(
							'label'    => 'Beaufort',
							'kind'     => 'place',
							'classes'  => array( 'index-kind-place' ),
							'locators' => array(
								array(
									'post'     => 1,
									'strength' => 'passing',
									'sources'  => array( 'post/tag' ),
								),
								array(
									'post'     => 3,
									'strength' => 'main',
									'sources'  => array( 'post/tag' ),
								),
								array(
									'post'     => 5,
									'strength' => 'passing',
									'sources'  => array( 'post/tag' ),
								),
							),
						),
					),
				),
				'attribution' => array(
					'osm'       => true,
					'wikimedia' => false,
				),
			);
		};
	}

	/**
	 * Builds the merge: each unit is rendered as a title, the manifest is written to `$written`, the map of the pages is read from `$maps`.
	 *
	 * @param string   $written Receives the JSON of the manifest.
	 * @param string[] $maps    The maps of the pages by key.
	 * @return MergedPrint
	 */
	private function merge( &$written = '', array $maps = array() ) {
		return new MergedPrint(
			$this->sections,
			new MergeAccess( null, static fn() => 'key', static fn() => true, static fn() => '' ),
			new MergeLog( static fn() => false, static fn() => '' ),
			fn() => $this->request,
			static fn( $id ) => '<h2>Unit ' . $id . '</h2>',
			static fn( $id ) => array(
				'status'   => 2 === $id ? 'draft' : 'publish',
				'password' => 4 === $id ? 'secret' : '',
			),
			static function ( $key, $json ) use ( &$written ) {
				$written = $json;

				return true;
			},
			static fn( $key ) => $maps[ $key ] ?? null
		);
	}

	/**
	 * Merges and returns the page: the markers given, the merge block, in a body.
	 *
	 * @param MergedPrint $merge   The merge.
	 * @param string      $content What goes before the units.
	 * @return string
	 */
	private function page( MergedPrint $merge, $content ) {
		( new SectionMergeBlock( $merge, static fn() => 'print' ) )->render( array(), '', null );

		ob_start();
		$merge->head();
		$source = '<body>' . $content . MergedPrint::MARKER . '</body>';
		echo $source; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$merge->footer();
		ob_end_flush();

		return (string) ob_get_clean();
	}

	/**
	 * The block leaves a marker with its options (a notice that is not known is `auto`), nothing in a mode it is not shown in, and the merge puts the
	 * index where the marker is, from the posts of the merge in order (the draft and the post with a password are not there).
	 *
	 * @return void
	 */
	public function test_the_index_is_made_when_the_units_are_placed(): void {
		$merge = $this->merge();
		$block = new SectionIndexBlock( $merge, static fn() => 'print', null, static fn( $slug ) => 'print' === $slug );

		$marker = $block->render(
			array(
				'include' => 'visibility:print',
				'attribution' => 'nonsense',
				'title' => 'Places',
			),
			'',
			null
		);

		$this->assertSame( '<!-- books-section-index:0 -->', $marker );
		$this->assertSame( '', $block->render( array( 'modes' => array( 'web' ) ), '', null ), 'Not in this mode.' );

		$page = $this->page( $merge, '<h1>Book</h1>' . $marker );

		$this->assertStringNotContainsString( 'books-section-index', $page, 'The marker is replaced.' );
		$this->assertStringContainsString( '<h2 class="books-index__title no-toc">Places</h2>', $page );
		$this->assertStringContainsString( '<span class="books-index__label">Beaufort</span> <span class="books-index__locators">000</span>', $page, 'Print: the placeholder until the pages are known.' );
		$this->assertStringContainsString( '<p class="books-index__attribution">Places: <a href="https://www.openstreetmap.org/copyright">© OpenStreetMap contributors</a>.</p>', $page );
		$this->assertCount( 1, $this->asked );
		$this->assertSame( array( 101, 3, 1, 5 ), $this->asked[0][0], 'The posts of the merge, in order (by date: the part first).' );
		$this->assertSame( 'visibility:print', $this->asked[0][1]['include'] );
		$this->assertTrue( $this->asked[0][1]['context'] );
		$this->assertLessThan( strpos( $page, 'Unit 1' ), strpos( $page, 'books-index' ), 'The index is where the marker was, before the units.' );
	}

	/**
	 * With the map of the pages in the address the numbers are the pages of the first heading of each post; the manifest counts the numbered
	 * indexes, with the tables of contents, so that the job knows a second pass is needed.
	 *
	 * @return void
	 */
	public function test_the_index_prints_the_pages_of_the_map_and_the_manifest_counts_it(): void {
		$key           = str_repeat( 'b', 32 );
		$written       = '';
		$this->request = $this->request + array( 'pages' => $key );
		$merge         = $this->merge( $written, array( $key => '{"1#1":7,"101#1":8,"3#1":9,"5#1":30}' ) );
		$index         = new SectionIndexBlock( $merge, static fn() => 'print', null, static fn() => true );
		$toc           = new SectionTocBlock( $merge, static fn() => 'print', null, static fn() => true );

		$page = $this->page( $merge, $toc->render( array(), '', null ) . $index->render( array(), '', null ) );

		$this->assertStringContainsString( '<span class="books-index__label">Beaufort</span> <span class="books-index__locators">7, <strong class="books-index__main">9</strong>, 30</span>', $page );
		$this->assertSame( 2, json_decode( $written, true )['tocs'], 'A table of contents and an index with page numbers.' );
	}

	/**
	 * Outside print the index is a list of links to the posts (not numbered, so the manifest does not count it); several blocks are several
	 * indexes with their own options.
	 *
	 * @return void
	 */
	public function test_links_outside_print_and_several_indexes(): void {
		$written = '';
		$merge   = $this->merge( $written );
		$web     = new SectionIndexBlock( $merge, static fn() => 'web', null, static fn() => false );
		$first   = $web->render( array( 'include' => 'kind:place' ), '', null );
		$second  = $web->render(
			array(
				'include' => 'source:image',
				'context' => false,
			),
			'',
			null
		);

		$page = $this->page( $merge, $first . '<hr>' . $second );

		$this->assertSame( 2, substr_count( $page, '<div class="books-index">' ) );
		$this->assertStringContainsString( '<a href="https://example.test/?p=3">', $page );
		$this->assertCount( 2, $this->asked );
		$this->assertSame( 'kind:place', $this->asked[0][1]['include'] );
		$this->assertSame( 'source:image', $this->asked[1][1]['include'] );
		$this->assertFalse( $this->asked[1][1]['context'] );
		$this->assertSame( 0, json_decode( $written, true )['tocs'] );
	}

	/**
	 * Without the module of the index nobody answers the filter: the marker goes away and nothing else changes. For a visitor who may not merge the
	 * block shows nothing.
	 *
	 * @return void
	 */
	public function test_without_the_module_of_the_index_or_without_the_right_to_merge(): void {
		unset( $GLOBALS['otherguise_test_filters']['index_build'] );

		$merge = $this->merge();
		$block = new SectionIndexBlock( $merge, static fn() => 'print', null, static fn() => true );
		$page  = $this->page( $merge, $block->render( array(), '', null ) );

		$this->assertStringNotContainsString( 'books-section-index', $page );
		$this->assertStringNotContainsString( 'books-index', $page );

		$denied = new MergedPrint(
			$this->sections,
			new MergeAccess( null, static fn() => 'key', static fn() => false, static fn() => '' ),
			new MergeLog( static fn() => false, static fn() => '' ),
			fn() => $this->request
		);

		$this->assertSame( '', ( new SectionIndexBlock( $denied, static fn() => 'print' ) )->render( array(), '', null ) );
		$this->assertSame( '', $denied->index_slot( 5, array(), true ), 'Not the post of the section of the page.' );
	}
}
