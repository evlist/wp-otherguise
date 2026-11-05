<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the merge of a section with its units on a real database (slice 231).
 *
 * @package Otherguise
 */

use Otherguise\Books\Block\ModeFilter;
use Otherguise\Books\Block\SectionMergeBlock;
use Otherguise\Books\Block\SectionTocBlock;
use Otherguise\Books\Block\UnitsQuery;
use Otherguise\Books\Merge\MergeAccess;
use Otherguise\Books\Merge\Headings;
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
 * @covers \Otherguise\Books\Block\SectionMergeBlock
 * @covers \Otherguise\Books\Block\SectionTocBlock
 * @covers \Otherguise\Books\Merge\TableOfContents
 * @covers \Otherguise\Books\Merge\Headings
 * @covers \Otherguise\Books\Block\ModeFilter
 * @covers \Otherguise\Books\Block\UnitsQuery
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
	 * Slug of the mode of the request.
	 *
	 * @var string
	 */
	private $mode = 'print';

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
			'section' => 100,
			'preview' => false,
			'limit'   => 0,
		);
		$this->rendered = array();
		$this->mode     = 'print';

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
		$this->assertStringStartsWith( '<div class="books-merge-unit" data-post="101" data-depth="1"><p>unit 101</p></div>', $html );
		$this->assertStringNotContainsString( 'marker.invalid/unit', $html, 'The page of a unit is the page of its first heading: no marker at the start of a unit.' );
		$this->assertStringNotContainsString( 'break-before', $html, 'The plugin adds no page break: the templates of the theme decide.' );
		$this->assertStringContainsString( '<div class="books-merge-unit" data-post="3" data-depth="2"><p>unit 3</p></div>', $html, 'A level deeper.' );
		$this->assertLessThan( strpos( $html, 'data-post="3"' ), strpos( $html, 'data-post="101"' ) );
		$this->assertLessThan( strpos( $html, 'data-post="1"' ), strpos( $html, 'data-post="3"' ) );
		$this->assertLessThan( strpos( $html, 'data-post="5"' ), strpos( $html, 'data-post="1"' ) );
		$this->assertMatchesRegularExpression( '/<!-- books-merge \{"section":100,"units":4,/', $html );
	}

	/**
	 * Nothing is merged for a visitor, for a page that is not the page of a section, or for a section that holds nothing.
	 *
	 * @return void
	 */
	public function test_when_nothing_is_merged(): void {
		$this->assertSame( '', $this->merge( false )->merge(), 'Not allowed: the page of any section would cost the whole merge to anybody.' );
		$this->assertSame( array(), $this->rendered );

		foreach ( array(
			array( 'section' => 0 ),
			array( 'section' => 5 ),
		) as $change ) {
			$this->request = $change + $this->request;
			$this->assertSame( '', $this->merge()->merge(), json_encode( $change ) );
			$this->request = array(
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

	/**
	 * Builds the block around a merge.
	 *
	 * @param MergedPrint $merge The merge.
	 * @return SectionMergeBlock
	 */
	private function block( MergedPrint $merge ) {
		return new SectionMergeBlock( $merge, fn() => $this->mode );
	}

	/**
	 * In print, for who may merge, the block leaves the marker; the buffer started in the head puts the units there, once the footer has
	 * rendered them.
	 *
	 * @return void
	 */
	public function test_the_block_leaves_the_marker_and_the_units_go_there(): void {
		$merge = $this->merge();
		$block = $this->block( $merge );

		$this->assertSame( MergedPrint::MARKER, $block->render( array(), '', null ) );

		ob_start();
		$merge->head();
		$source = '<body><header>cover</header>' . MergedPrint::MARKER . '<footer>end</footer>';
		echo $source; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$merge->footer();
		echo '<script>s</script></body>';
		ob_end_flush();
		$page = (string) ob_get_clean();

		$this->assertStringContainsString( '<header>cover</header><div class="books-merge-unit"', $page, 'The units replace the marker.' );
		$this->assertStringContainsString( '<p>unit 5</p></div><!-- books-merge ', $page );
		$this->assertStringContainsString( '<footer>end</footer><script>s</script></body>', $page, 'What follows is left alone.' );
		$this->assertSame( 1, substr_count( $page, 'data-post="5"' ), 'Once.' );
		$this->assertStringNotContainsString( MergedPrint::MARKER, $page );
	}

	/**
	 * A visitor who may not merge gets nothing from the block, whatever the mode.
	 *
	 * @return void
	 */
	public function test_the_block_shows_nothing_to_a_visitor(): void {
		$this->assertSame( '', $this->block( $this->merge( false ) )->render( array(), '', null ) );

		$this->mode = 'web';
		$this->assertSame( '', $this->block( $this->merge( false ) )->render( array(), '', null ) );
	}

	/**
	 * The attribute `modes` of the block: none means every mode (the web too, for who may merge), else only the modes named.
	 *
	 * @return void
	 */
	public function test_the_modes_of_the_merge_block(): void {
		$this->mode = 'web';
		$this->assertSame( MergedPrint::MARKER, $this->block( $this->merge() )->render( array(), '', null ), 'Every mode by default.' );
		$this->assertSame( MergedPrint::MARKER, $this->block( $this->merge() )->render( array( 'modes' => array( 'web', 'print' ) ), '', null ) );
		$this->assertSame( '', $this->block( $this->merge() )->render( array( 'modes' => array( 'print' ) ), '', null ), 'Not in this mode.' );

		$this->mode = 'print';
		$this->assertSame( MergedPrint::MARKER, $this->block( $this->merge() )->render( array( 'modes' => array( 'print' ) ), '', null ) );
	}

	/**
	 * Nothing is merged unless a block asked for it; the first block asks, the others do not (the units are the same).
	 *
	 * @return void
	 */
	public function test_only_a_block_causes_a_merge(): void {
		$merge = $this->merge();

		ob_start();
		$merge->footer();
		$printed = (string) ob_get_clean();
		$this->assertSame( '', $printed );
		$this->assertSame( array(), $this->rendered, 'No block, no merge.' );

		$block = $this->block( $merge );
		$this->assertSame( MergedPrint::MARKER, $block->render( array(), '', null ) );
		$this->assertSame( '', $block->render( array(), '', null ), 'The second block of the page asks for nothing more.' );
	}

	/**
	 * The modes of a block: a list of slugs, empty for all; the slug of the request is empty without the Modes module.
	 *
	 * @return void
	 */
	public function test_the_mode_filter(): void {
		$this->assertTrue( ModeFilter::allows( array(), 'web' ) );
		$this->assertTrue( ModeFilter::allows( array( 'modes' => 'broken' ), 'web' ), 'Not a list: every mode.' );
		$this->assertTrue( ModeFilter::allows( array( 'modes' => array( 'print' ) ), 'print' ) );
		$this->assertFalse( ModeFilter::allows( array( 'modes' => array( 'print' ) ), 'web' ) );
		$this->assertFalse( ModeFilter::allows( array( 'modes' => array( 'print' ) ), '' ), 'Without Modes a restricted block shows nothing.' );
		$this->assertSame( '', ModeFilter::active() );
		$this->assertSame(
			array(
				array(
					'value' => 'web',
					'label' => 'Web',
				),
			),
			ModeFilter::choices( array( new Otherguise\Modes\Mode\ModeDefinition( 'web', 'Web' ) ) )
		);
	}

	/**
	 * Each heading of a unit starts with a marker, numbered in the unit; what a script, a style, a comment or a text area holds is left alone, and
	 * so are the tags that only start like a heading.
	 *
	 * @return void
	 */
	public function test_the_headings_are_marked(): void {
		$marker = static fn( $n ) => '<a class="books-merge-marker" href="https://marker.invalid/heading/7/' . $n . '" tabindex="-1" aria-hidden="true" style="display:inline-block;width:1px;height:1px;vertical-align:top;margin-bottom:-1px;"></a>';
		$html   = '<h1>A</h1><script>var s="<h2>x</h2>";</script><!-- <h3>c</h3> --><H2 class="x" id="b">B</H2><header>h</header><hr><textarea><h4>t</h4></textarea><style>h5{}</style><h6>F</h6><p>no</p>';

		$this->assertSame(
			'<h1>' . $marker( 1 ) . 'A</h1><script>var s="<h2>x</h2>";</script><!-- <h3>c</h3> --><H2 class="x" id="b">' . $marker( 2 ) . 'B</H2><header>h</header><hr><textarea><h4>t</h4></textarea><style>h5{}</style><h6>' . $marker( 3 ) . 'F</h6><p>no</p>',
			Headings::of_unit( $html, 7 )
		);
		$this->assertSame( '<p>No heading</p>', Headings::of_unit( '<p>No heading</p>', 7 ) );
		$this->assertSame( '', Headings::of_unit( '', 7 ) );
	}

	/**
	 * In a merge, the headings of each unit carry the markers of that unit.
	 *
	 * @return void
	 */
	public function test_the_merge_marks_the_headings_of_every_unit(): void {
		$merge = new MergedPrint(
			$this->sections,
			new MergeAccess( null, static fn() => 'key', static fn() => true, static fn() => '' ),
			new MergeLog( static fn() => false, static fn() => '' ),
			fn() => $this->request,
			static fn( $id ) => '<h2>Title ' . $id . '</h2><h3>Sub</h3>',
			static fn() => array(
				'status'   => 'publish',
				'password' => '',
			)
		);
		$html  = $merge->merge();

		$this->assertStringContainsString( 'href="https://marker.invalid/heading/1/1"', $html );
		$this->assertStringContainsString( 'href="https://marker.invalid/heading/1/2"', $html );
		$this->assertStringContainsString( 'href="https://marker.invalid/heading/101/1"', $html );
		$this->assertStringNotContainsString( 'heading/1/3', $html );
	}

	/**
	 * The headings of the page of the section get markers too, numbered in the page (the ones before the units go on after them), and those of
	 * the units keep theirs: the units are put in the page after, not marked again.
	 *
	 * @return void
	 */
	public function test_the_headings_of_the_page_of_the_section_are_marked(): void {
		$merge = new MergedPrint(
			$this->sections,
			new MergeAccess( null, static fn() => 'key', static fn() => true, static fn() => '' ),
			new MergeLog( static fn() => false, static fn() => '' ),
			fn() => $this->request,
			static fn( $id ) => '<h2>Unit ' . $id . '</h2>',
			static fn() => array(
				'status'   => 'publish',
				'password' => '',
			)
		);
		$block = new SectionMergeBlock( $merge, fn() => $this->mode );

		$this->assertSame( MergedPrint::MARKER, $block->render( array(), '', null ) );

		ob_start();
		$merge->head();
		$source = '<body><h1>Cover</h1><script>var h="<h2>x</h2>";</script><h2>Contents</h2>' . MergedPrint::MARKER . '<h2>The end</h2><footer></footer></body>';
		echo $source; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$merge->footer();
		ob_end_flush();
		$page = (string) ob_get_clean();

		foreach ( array( 'heading/100/1', 'heading/100/2', 'heading/100/3', 'heading/101/1', 'heading/1/1' ) as $name ) {
			$this->assertSame( 1, substr_count( $page, 'href="https://marker.invalid/' . $name . '"' ), $name );
		}

		$this->assertStringNotContainsString( 'heading/100/4', $page );
		$this->assertStringNotContainsString( 'heading/101/2', $page, 'The headings of a unit are not marked a second time.' );
		$this->assertStringContainsString( '<script>var h="<h2>x</h2>";</script>', $page );
		$this->assertLessThan( strpos( $page, 'heading/101/1' ), strpos( $page, 'heading/100/2' ), 'Before the units.' );
		$this->assertLessThan( strpos( $page, 'heading/100/3' ), strpos( $page, 'heading/101/1' ), 'The end of the page comes after the units and goes on with the numbers.' );
	}

	/**
	 * Builds a merge that renders units with headings and hands its manifest to a writer.
	 *
	 * @param callable|null $writer Receives the key and the JSON of the manifest; returns whether it was written.
	 * @return MergedPrint
	 */
	private function merge_with_manifest( $writer ) {
		return new MergedPrint(
			$this->sections,
			new MergeAccess( null, static fn() => 'key', static fn() => true, static fn() => '' ),
			new MergeLog( static fn() => false, static fn() => '' ),
			fn() => $this->request,
			static fn( $id ) => 101 === $id ? '<h1>Part &amp; <em>one</em></h1><h2 class="a no-toc">Left   out</h2>' : '<h1>Day ' . $id . '</h1><h3 class="b">Sub</h3><h4></h4>',
			static fn( $id ) => array(
				'status'   => 2 === $id ? 'draft' : 'publish',
				'password' => '',
			),
			$writer
		);
	}

	/**
	 * The merge writes a manifest: the headings of the page of the section and of the units in the order of the document, with their level, their
	 * plain text, whether a table of contents lists them (`no-toc`) and the depth of their unit; its marker, named after its key, goes in front of
	 * the units.
	 *
	 * @return void
	 */
	public function test_the_manifest_of_the_headings(): void {
		$written = array();
		$merge   = $this->merge_with_manifest(
			static function ( $key, $json ) use ( &$written ) {
				$written = array( $key, $json );

				return true;
			}
		);
		$block   = new SectionMergeBlock( $merge, fn() => $this->mode );

		$block->render( array(), '', null );

		ob_start();
		$merge->head();
		$source = '<body><h1>Book</h1><p>x</p>' . MergedPrint::MARKER . '<h2>Afterword</h2></body>';
		echo $source; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$merge->footer();
		ob_end_flush();
		$page = (string) ob_get_clean();

		$this->assertSame( md5( $written[1] ), $written[0], 'The key is the hash of the JSON.' );
		$this->assertStringContainsString( 'href="https://marker.invalid/manifest/' . $written[0] . '"', $page );
		$this->assertLessThan( strpos( $page, 'heading/101/1' ), strpos( $page, 'manifest/' . $written[0] ), 'In front of the units.' );

		$data = json_decode( $written[1], true );

		$this->assertSame( 100, $data['section'] );
		$this->assertSame(
			array( array( 101, 1 ), array( 3, 2 ), array( 4, 2 ), array( 1, 1 ), array( 5, 1 ) ),
			array_map( static fn( $unit ) => array( $unit['id'], $unit['depth'] ), $data['units'] ),
			'The units that were merged, in order, with their depth.'
		);
		$this->assertSame(
			array(
				array( 100, 1, 1, 'Book', true, 0 ),
				array( 101, 1, 1, 'Part & one', true, 1 ),
				array( 101, 2, 2, 'Left out', false, 1 ),
				array( 3, 1, 1, 'Day 3', true, 2 ),
				array( 3, 2, 3, 'Sub', true, 2 ),
				array( 3, 3, 4, '', true, 2 ),
			),
			array_map( static fn( $row ) => array( $row['unit'], $row['n'], $row['level'], $row['text'], $row['toc'], $row['depth'] ), array_slice( $data['headings'], 0, 6 ) ),
			'The section, then its units in the order of the merge (the part, its own units, then the others), each numbered in its unit, with its depth.'
		);

		$last = end( $data['headings'] );

		$this->assertSame( array( 100, 2, 2, 'Afterword', 0 ), array( $last['unit'], $last['n'], $last['level'], $last['text'], $last['depth'] ), 'The end of the page of the section goes on with its numbers.' );
	}

	/**
	 * The table of contents: the block leaves a marker, the merge replaces it, wherever it is in the page (before or after the units), by the entries
	 * made from the headings of the section and of the units (the page of the section is marked and its headings are entries, except `no-toc`).
	 *
	 * @return void
	 */
	public function test_the_table_of_contents_is_made_when_the_units_are_placed(): void {
		$merge = $this->merge_with_manifest( null );
		$merge_block = new SectionMergeBlock( $merge, fn() => $this->mode );
		$toc_all     = new SectionTocBlock( $merge, fn() => $this->mode, null, static fn( $slug ) => 'print' === $slug );
		$toc_one     = new SectionTocBlock( $merge, fn() => $this->mode, null, static fn() => false );

		$this->mode = 'print';
		$first      = $toc_all->render( array( 'levels' => 0 ), '', null );
		$this->mode = 'web';
		$second     = $toc_one->render( array( 'levels' => 1 ), '', null );
		$this->mode = 'print';

		$this->assertSame( MergedPrint::TOC_MARKER . '0 -->', $first );
		$this->assertSame( MergedPrint::TOC_MARKER . '1 -->', $second );
		$this->assertSame( MergedPrint::MARKER, $merge_block->render( array(), '', null ) );
		$this->assertSame( '', $toc_all->render( array( 'modes' => array( 'web' ) ), '', null ), 'Not in this mode.' );

		ob_start();
		$merge->head();
		$source = '<body><h1 class="no-toc">Book</h1>' . $first . '<h2>Intro</h2>' . $second . MergedPrint::MARKER . '<h2>Afterword</h2></body>';
		echo $source; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$merge->footer();
		ob_end_flush();
		$page = (string) ob_get_clean();

		$this->assertStringNotContainsString( MergedPrint::TOC_MARKER, $page, 'Every marker is replaced.' );
		$this->assertSame( 2, substr_count( $page, '<ul class="books-toc">' ) );
		$this->assertStringContainsString( '<span class="books-toc__text">Part &amp; one</span><span class="books-toc__leader" aria-hidden="true"></span><span class="books-toc__page">000</span>', $page, 'Numbers in print: the placeholder until the pages are known.' );
		$this->assertStringContainsString( '<span class="books-toc__text">Day 3</span>', $page );
		$this->assertStringContainsString( '<span class="books-toc__text">Intro</span>', $page, 'The headings of the page of the section are entries.' );
		$this->assertStringContainsString( '<span class="books-toc__text">Afterword</span>', $page, 'The table before the units knows the heading after them.' );
		$this->assertStringContainsString( '<li class="books-toc__entry books-toc__level-1"><a href="https://example.test/?p=101">Part &amp; one</a></li>', $page, 'Not numbered (a mode that is not a print mode): links, one level.' );
		$this->assertStringNotContainsString( 'Left out', $page, 'no-toc.' );
		$this->assertLessThan( strpos( $page, 'Part &amp; one' ), strpos( $page, '<ul class="books-toc">' ), 'The first table is before the units.' );
	}

	/**
	 * With the key of a map of the pages in the address (`books_pages`), the numbered tables print the pages; a heading that is not in the map gets a
	 * dash; an address with no map, or a map that cannot be read, gives the placeholders. The manifest says how many numbered tables there are.
	 *
	 * @return void
	 */
	public function test_the_tables_print_the_pages_of_the_map(): void {
		$key = str_repeat( 'a', 32 );
		$run = function ( $reader, $request ) {
			$written = '';
			$merge   = new MergedPrint(
				$this->sections,
				new MergeAccess( null, static fn() => 'key', static fn() => true, static fn() => '' ),
				new MergeLog( static fn() => false, static fn() => '' ),
				static fn() => $request,
				static fn( $id ) => '<h2>Unit ' . $id . '</h2>',
				static fn() => array(
					'status'   => 'publish',
					'password' => '',
				),
				static function ( $key, $json ) use ( &$written ) {
					$written = $json;

					return true;
				},
				$reader
			);
			$toc     = new SectionTocBlock( $merge, fn() => 'print', null, static fn() => true );
			$block   = new SectionMergeBlock( $merge, fn() => 'print' );
			$marker  = $toc->render( array( 'levels' => 0 ), '', null );

			$block->render( array(), '', null );

			ob_start();
			$merge->head();
			$source = '<body>' . $marker . MergedPrint::MARKER . '</body>';
			echo $source; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$merge->footer();
			ob_end_flush();

			return array( (string) ob_get_clean(), $written );
		};
		$with    = $this->request + array( 'pages' => $key );

		list( $page, $json ) = $run( static fn( $asked ) => $asked === $key ? '{"1#1":7,"101#1":3,"3#1":4}' : null, $with );

		$this->assertStringContainsString( '<span class="books-toc__text">Unit 101</span><span class="books-toc__leader" aria-hidden="true"></span><span class="books-toc__page">3</span>', $page );
		$this->assertStringContainsString( '<span class="books-toc__text">Unit 1</span><span class="books-toc__leader" aria-hidden="true"></span><span class="books-toc__page">7</span>', $page );
		$this->assertStringContainsString( '<span class="books-toc__text">Unit 5</span><span class="books-toc__leader" aria-hidden="true"></span><span class="books-toc__page">–</span>', $page, 'Not in the map.' );
		$this->assertSame( 1, json_decode( $json, true )['tocs'], 'One numbered table.' );

		list( $page ) = $run( static fn() => null, $with );
		$this->assertStringContainsString( '<span class="books-toc__page">000</span>', $page, 'A map that is not found.' );
		$this->assertStringNotContainsString( '–', $page );

		list( $page ) = $run( static fn() => 'not json', $with );
		$this->assertStringContainsString( '<span class="books-toc__page">000</span>', $page, 'A map that cannot be read.' );

		list( $page ) = $run( static fn() => '{"1#1":7}', $this->request );
		$this->assertStringContainsString( '<span class="books-toc__page">000</span>', $page, 'No map in the address.' );
	}

	/**
	 * Without units, or with a visitor who may not merge, the table is nothing: no marker, or the marker goes away.
	 *
	 * @return void
	 */
	public function test_no_table_of_contents_without_a_merge(): void {
		$denied = new MergedPrint(
			$this->sections,
			new MergeAccess( null, static fn() => 'key', static fn() => false, static fn() => '' ),
			new MergeLog( static fn() => false, static fn() => '' ),
			fn() => $this->request
		);

		$this->assertSame( '', ( new SectionTocBlock( $denied, fn() => 'print' ) )->render( array(), '', null ) );
		$this->assertSame( '', $denied->toc_slot( 5, 2, true ), 'Not the post of the section of the page.' );
		$this->assertSame( '', $this->merge()->toc_slot( 5, 2, true ) );
	}

	/**
	 * No manifest marker when the writer fails, when there is no writer, or when there are no units.
	 *
	 * @return void
	 */
	public function test_no_manifest_marker_without_a_manifest(): void {
		foreach ( array( static fn() => false, null ) as $writer ) {
			$merge = $this->merge_with_manifest( $writer );
			( new SectionMergeBlock( $merge, fn() => $this->mode ) )->render( array(), '', null );

			ob_start();
			$merge->head();
			$source = '<body>' . MergedPrint::MARKER . '</body>';
			echo $source; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$merge->footer();
			ob_end_flush();

			$this->assertStringNotContainsString( 'marker.invalid/manifest/', (string) ob_get_clean() );
		}
	}

	/**
	 * Builds the template of a loop, as the filter receives it.
	 *
	 * @param array $query   The `query` attribute of the loop.
	 * @param int   $post_id The post of the context, 0 for none.
	 * @return object
	 */
	private function loop( array $query, $post_id = 100 ) {
		return new class( $query, $post_id ) extends WP_Block {
			/**
			 * Builds the double.
			 *
			 * @param array $query   Attribute.
			 * @param int   $post_id Post.
			 */
			public function __construct( $query, $post_id ) {
				$this->context = array(
					'query'  => $query,
					'postId' => $post_id,
				);
			}
		};
	}

	/**
	 * A loop that carries the key lists the published units of the section in its order, whatever its settings say; another loop is left alone.
	 *
	 * @return void
	 */
	public function test_the_query_loop_lists_the_units(): void {
		$filter = new UnitsQuery( $this->sections, static fn() => 100 );
		$asked  = array(
			'post_type'      => 'post',
			'posts_per_page' => 7,
			'tax_query'      => array( 'x' ),
			'author'         => 3,
			's'              => 'abc',
			'orderby'        => 'date',
		);

		$this->assertSame( $asked, $filter->filter( $asked, $this->loop( array( 'perPage' => 7 ) ) ), 'No key: untouched.' );
		$this->assertSame( $asked, $filter->filter( $asked, null ), 'Not a loop block.' );

		$out = $filter->filter( $asked, $this->loop( array( UnitsQuery::KEY => true ) ) );

		$this->assertSame( array( 101, 1, 2, 5 ), array_map( 'intval', $out['post__in'] ), 'The ids of the section, in its order (the query leaves out what is not published).' );
		$this->assertSame( 'post__in', $out['orderby'] );
		$this->assertSame( 'any', $out['post_type'] );
		$this->assertSame( 'publish', $out['post_status'] );
		$this->assertFalse( $out['has_password'] );
		$this->assertSame( 7, $out['posts_per_page'], 'The size of a page stays the loop\'s.' );

		foreach ( array( 'tax_query', 'author', 's' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $out );
		}

		$empty = $filter->filter( $asked, $this->loop( array( UnitsQuery::KEY => true ), 5 ) );
		$this->assertSame( array( 0 ), $empty['post__in'], 'A post that is not a section lists nothing.' );

		$queried = ( new UnitsQuery( $this->sections, static fn() => 100 ) )->filter( $asked, $this->loop( array( UnitsQuery::KEY => true ), 0 ) );
		$this->assertSame( array( 101, 1, 2, 5 ), array_map( 'intval', $queried['post__in'] ), 'Without a post in the context: the page.' );
	}

	/**
	 * A merge that is going on, or a page that is not the section, gives no marker; a section without units gives nothing.
	 *
	 * @return void
	 */
	public function test_no_marker_when_there_is_nothing_to_merge(): void {
		$merge = $this->merge();

		$this->assertSame( '', $merge->slot( 5 ), 'The post of the block is not the section of the page.' );
		$this->assertSame( MergedPrint::MARKER, $merge->slot( 100 ) );

		$this->request['section'] = 5;
		$this->assertSame( '', $this->merge()->slot( 5 ), 'A post that is not a section.' );
	}

	/**
	 * A unit that is a section renders the same template while the merge is going on: its block shows nothing, the merge follows its units.
	 *
	 * @return void
	 */
	public function test_the_block_is_silent_during_a_merge(): void {
		$merge = $this->merge();
		$block = $this->block( $merge );
		$seen  = array();

		$busy = new MergedPrint(
			$this->sections,
			new MergeAccess( null, static fn() => 'key', static fn() => true, static fn() => '' ),
			new MergeLog( static fn() => false, static fn() => '' ),
			fn() => $this->request,
			static function ( $id ) use ( &$seen, &$busy_block ) {
				$seen[ $id ] = $busy_block->render( array(), '', null );

				return '<p>unit ' . $id . '</p>';
			},
			static fn() => array(
				'status'   => 'publish',
				'password' => '',
			)
		);
		$busy_block = $this->block( $busy );
		$busy->merge();

		$this->assertNotEmpty( $seen );
		$this->assertSame( array( '' ), array_values( array_unique( $seen ) ) );
		$this->assertInstanceOf( SectionMergeBlock::class, $block );
	}

	/**
	 * Without a marker in the page the units go before the end of the body; without units the marker disappears.
	 *
	 * @return void
	 */
	public function test_place_without_marker_or_without_units(): void {
		$merge = $this->merge();
		$merge->slot( 100 );
		$merge->head();
		$merge->footer();

		$this->assertStringContainsString( '<p>unit 1</p></div>', $merge->place( '<body>x</body>' ) );
		$this->assertStringEndsWith( '</body>', $merge->place( '<body>x</body>' ) );

		$this->assertSame( '<body>a</body>', ( $this->merge() )->place( '<body>a' . MergedPrint::MARKER . '</body>' ), 'No units: the marker is removed.' );

		while ( ob_get_level() > 1 ) {
			ob_end_clean();
		}
	}
}
