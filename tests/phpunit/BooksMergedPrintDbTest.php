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
use Otherguise\Books\Block\UnitsQuery;
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
 * @covers \Otherguise\Books\Block\SectionMergeBlock
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
