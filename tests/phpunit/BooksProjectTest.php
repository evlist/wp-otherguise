<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the printer profiles and of the book projects (slice 230).
 *
 * @package Otherguise
 */

use Otherguise\Books\Cli\ProfileCommand;
use Otherguise\Books\Cli\ProjectCommand;
use Otherguise\Books\Project\MetaBoxes;
use Otherguise\Books\Project\PageRules;
use Otherguise\Books\Project\PostTypes;
use Otherguise\Books\Project\PrinterProfile;
use Otherguise\Books\Project\PrintStyles;
use Otherguise\Books\Project\Presets;
use Otherguise\Books\Project\Projects;
use Otherguise\Books\Project\Reference;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress and no database: the posts, the sections and the capabilities are doubles.
 *
 * @covers \Otherguise\Books\Project\PrinterProfile
 * @covers \Otherguise\Books\Project\PageRules
 * @covers \Otherguise\Books\Project\Presets
 * @covers \Otherguise\Books\Project\Projects
 * @covers \Otherguise\Books\Project\Reference
 * @covers \Otherguise\Books\Project\PrintStyles
 * @covers \Otherguise\Books\Project\MetaBoxes
 * @covers \Otherguise\Books\Project\PostTypes
 * @covers \Otherguise\Books\Cli\ProfileCommand
 * @covers \Otherguise\Books\Cli\ProjectCommand
 */
class BooksProjectTest extends TestCase {

	/**
	 * Starts clean.
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
	 * What is not valid is replaced by the default, a missing gutter is the margin, the numbers are rounded.
	 *
	 * @return void
	 */
	public function test_a_profile_is_normalized(): void {
		$this->assertSame( PrinterProfile::defaults(), PrinterProfile::normalize( null ) );
		$this->assertSame( PrinterProfile::defaults(), PrinterProfile::normalize( 'x' ) );

		$profile = PrinterProfile::normalize(
			array(
				'width_mm'       => '150,04',
				'height_mm'      => 5,
				'margin_mm'      => '12',
				'safe_mm'        => 'wide',
				'bleed_mm'       => 3,
				'folio'          => 'outside',
				'pages_multiple' => '4',
				'pages_min'      => -3,
				'final_blank'    => 'on',
				'chapter_recto'  => 'no',
				'cover'          => 'spine',
				'min_ppi'        => '300',
				'colour'         => 'cmyk',
				'max_mb'         => '700',
				'url'            => 'javascript:alert(1)',
				'read_on'        => '2026-02-30',
			)
		);

		$this->assertSame( 150.0, $profile['width_mm'], 'Rounded to a tenth.' );
		$this->assertSame( 210.0, $profile['height_mm'], 'Out of bounds: the default.' );
		$this->assertSame( 12.0, $profile['margin_mm'] );
		$this->assertSame( 12.0, $profile['gutter_mm'], 'No gutter: the margin.' );
		$this->assertSame( 10.0, $profile['safe_mm'], 'Not a number: the default.' );
		$this->assertSame( 3.0, $profile['bleed_mm'] );
		$this->assertSame( 'outside', $profile['folio'] );
		$this->assertSame( 4, $profile['pages_multiple'] );
		$this->assertSame( 0, $profile['pages_min'] );
		$this->assertTrue( $profile['final_blank'] );
		$this->assertFalse( $profile['chapter_recto'] );
		$this->assertSame( 'none', $profile['cover'] );
		$this->assertSame( 300, $profile['min_ppi'] );
		$this->assertSame( 'cmyk', $profile['colour'] );
		$this->assertSame( 700, $profile['max_mb'] );
		$this->assertSame( '', $profile['url'], 'Only http and https.' );
		$this->assertSame( '', $profile['read_on'], 'An impossible date.' );

		$this->assertSame( 20.0, PrinterProfile::normalize( array( 'gutter_mm' => 20 ) )['gutter_mm'] );
		$this->assertSame( 10.0, PrinterProfile::normalize( array( 'gutter_mm' => 90 ) )['gutter_mm'], 'A gutter of more than half the page is refused.' );
		$this->assertLessThan( 74.0, PrinterProfile::normalize( array( 'margin_mm' => 80 ) )['margin_mm'], 'A margin that leaves no page is replaced.' );
		$this->assertSame( 'https://www.coollibri.com/faq', PrinterProfile::normalize( array( 'url' => ' https://www.coollibri.com/faq ' ) )['url'] );
		$this->assertSame( '2026-10-26', PrinterProfile::normalize( array( 'read_on' => '2026-10-26' ) )['read_on'] );
		$this->assertSame(
			array(
				'width_mm'  => 148.0,
				'height_mm' => 210.0,
				'margin_mm' => 10.0,
			),
			PrinterProfile::format( PrinterProfile::defaults() )
		);
	}

	/**
	 * The CSS: one rule for equal margins, the gutter on the binding side otherwise, the bleed, the folios, the variables.
	 *
	 * @return void
	 */
	public function test_the_page_rules(): void {
		$plain = PageRules::css( PrinterProfile::defaults() );

		$this->assertSame( ':root{--books-margin:10mm;--books-gutter:10mm;--books-safe:10mm;--books-k:1;}@page{size:148mm 210mm;margin:10mm;}', $plain );

		$gutter = PageRules::css(
			PrinterProfile::normalize(
				array(
					'margin_mm' => 8,
					'gutter_mm' => 20,
					'folio' => 'outside',
				)
			)
		);

		$this->assertStringContainsString( '@page :right{margin-left:20mm;margin-right:8mm;}', $gutter );
		$this->assertStringContainsString( '@page :left{margin-left:8mm;margin-right:20mm;}', $gutter );
		$this->assertStringContainsString( '@page :right{@bottom-right{content:counter(page);', $gutter );
		$this->assertStringContainsString( '@page :left{@bottom-left{content:counter(page);', $gutter );
		$this->assertStringNotContainsString( '@bottom-center', $gutter );

		$this->assertStringContainsString( '@page{@bottom-center{content:counter(page);', PageRules::css( PrinterProfile::normalize( array( 'folio' => 'center' ) ) ) );
		$this->assertStringNotContainsString( 'counter(page)', PageRules::css( PrinterProfile::normalize( array( 'folio' => 'outside' ) ), false ), 'The project can turn the folios off.' );

		// The factor of the page: the usable width over the one the templates were composed for (190 mm, an A4 with margins of 10 mm).
		$a5 = PrinterProfile::defaults();

		$this->assertStringContainsString( '--books-k:0.674;', PageRules::css( $a5, true, 190.0 ), 'An A5 with margins of 10 mm: 128 / 190.' );
		$this->assertStringContainsString( '--books-k:1;', PageRules::css( $a5, true, 128.0 ), 'The same page: 1.' );
		$this->assertStringContainsString( '--books-k:1;', PageRules::css( $a5, true, 0.0 ), 'Unknown design width: 1, nothing changes in a theme that has a fallback.' );
		$this->assertStringContainsString( '--books-k:1.5;', PageRules::css( $a5, true, 85.333 ), 'Three decimals at most, no trailing zero.' );
		$this->assertStringContainsString( '--books-k:4;', PageRules::css( $a5, true, 1.0 ), 'Never more than 4.' );
		$this->assertStringContainsString( '--books-k:0.25;', PageRules::css( $a5, true, 10000.0 ), 'Never less than a quarter.' );
		$this->assertStringContainsString(
			'--books-k:0.632;',
			PageRules::css(
				PrinterProfile::normalize(
					array(
						'margin_mm' => 8,
						'gutter_mm' => 20,
					)
				),
				true,
				190.0
			),
			'The usable width counts the margin and the gutter: (148 - 8 - 20) / 190.'
		);

		$bleed = PageRules::css( PrinterProfile::normalize( array( 'bleed_mm' => 3 ) ) );

		$this->assertStringContainsString( '@page{size:154mm 216mm;margin:13mm;}', $bleed, 'The page and every margin grow by the bleed.' );
		$this->assertStringContainsString( '--books-margin:10mm', $bleed, 'The variables are the profile\'s, without the bleed.' );
	}

	/**
	 * The presets are valid profiles.
	 *
	 * @return void
	 */
	public function test_the_presets(): void {
		$this->assertSame( array( 'coollibri', 'flexilivre' ), array_keys( Presets::all() ) );

		$coollibri = Presets::profile( 'coollibri' );

		$this->assertSame( 20.0, $coollibri['safe_mm'] );
		$this->assertSame( 700, $coollibri['max_mb'] );
		$this->assertTrue( $coollibri['final_blank'] );
		$this->assertSame( 5.0, Presets::profile( 'flexilivre' )['safe_mm'] );
		$this->assertSame( 3000, Presets::profile( 'flexilivre' )['max_mb'] );
		$this->assertNull( Presets::profile( 'imprimerie' ) );
	}

	/**
	 * A double of the relations: the projects 5 (root 10, profile 20), 6 (root 10, profile 21) and 7 (root 11, profile 99, missing) and 8
	 * (none); the section 10 reaches 100, 12 and 101, the section 11 reaches 100.
	 *
	 * @return object
	 */
	private function links() {
		return new class() {
			/**
			 * Roots.
			 *
			 * @var array
			 */
			private $roots = array(
				5 => 10,
				6 => 10,
				7 => 11,
			);

			/**
			 * Profiles.
			 *
			 * @var array
			 */
			private $profiles = array(
				5 => 20,
				6 => 21,
				7 => 99,
			);

			/**
			 * Posts that a section reaches.
			 *
			 * @var array
			 */
			private $reach = array(
				10 => array( 100, 12, 101 ),
				11 => array( 100 ),
			);

			/**
			 * Root.
			 *
			 * @param int $id Project.
			 * @return int
			 */
			public function root( $id ) {
				return $this->roots[ $id ] ?? 0;
			}

			/**
			 * Profile.
			 *
			 * @param int $id Project.
			 * @return int
			 */
			public function profile( $id ) {
				return $this->profiles[ $id ] ?? 0;
			}

			/**
			 * Projects that cover a post.
			 *
			 * @param int $post Post.
			 * @return int[]
			 */
			public function covering( $post ) {
				$ids = array();

				foreach ( $this->roots as $project => $root ) {
					if ( $root === $post || in_array( $post, $this->reach[ $root ] ?? array(), true ) ) {
						$ids[] = $project;
					}
				}

				return $ids;
			}
		};
	}

	/**
	 * Builds the service with the double of the relations; the project 6 has no folios.
	 *
	 * @return Projects
	 */
	private function projects() {
		$rows     = array(
			array(
				'id'    => 7,
				'title' => 'Orphan',
				'meta'  => array(),
			),
			array(
				'id'    => 5,
				'title' => 'CoolLibri',
				'meta'  => array(),
			),
			array(
				'id'    => 6,
				'title' => 'Flexilivre',
				'meta'  => array(
					'folios' => '0',
					'author' => 'Eric',
				),
			),
			array(
				'id'    => 8,
				'title' => 'No root',
				'meta'  => 'broken',
			),
		);
		$profiles = array(
			20 => array(
				'safe_mm' => 20,
				'folio'   => 'center',
			),
			21 => array( 'width_mm' => 150 ),
		);

		return new Projects(
			static fn() => $rows,
			static fn( $id ) => $profiles[ $id ] ?? null,
			$this->links()
		);
	}

	/**
	 * The reference of the factor: what the theme declares when it is valid, else the global format; the usable area counts the margin and the
	 * gutter on the width and the margin above and below on the height.
	 *
	 * @return void
	 */
	public function test_the_reference_of_the_theme(): void {
		$global    = static fn() => array(
			'width_mm'  => 210.0,
			'height_mm' => 297.0,
			'margin_mm' => 10.0,
		);
		$declared  = array(
			'width'  => 210,
			'height' => 297,
			'margin' => 10,
			'safety' => 5,
		);
		$reference = new Reference( static fn() => $declared, $global );

		$this->assertSame(
			array(
				'width'  => 190.0,
				'height' => 277.0,
			),
			$reference->usable(),
			'A4 with margins of 1 cm.'
		);
		$this->assertSame( 'theme', $reference->source() );

		$gutter = Reference::normalize( $declared + array( 'gutter' => 20 ) );
		$this->assertSame( 20.0, $gutter['gutter'] );
		$this->assertSame( 10.0, Reference::normalize( $declared )['gutter'], 'A missing gutter is the margin.' );
		$this->assertSame( 5.0, $gutter['safety'] );

		foreach ( array(
			'nothing'        => null,
			'a string'       => 'A4',
			'no margin'      => array(
				'width'  => 210,
				'height' => 297,
			),
			'too small'      => array(
				'width'  => 20,
				'height' => 297,
				'margin' => 10,
			),
			'no room'        => array(
				'width'  => 210,
				'height' => 297,
				'margin' => 120,
			),
			'not a number'   => array(
				'width'  => 'wide',
				'height' => 297,
				'margin' => 10,
			),
			'negative'       => array(
				'width'  => 210,
				'height' => 297,
				'margin' => -1,
			),
		) as $case => $input ) {
			$this->assertNull( Reference::normalize( $input ), $case );

			$fallback = new Reference( static fn() => $input, $global );
			$this->assertSame( 'global', $fallback->source(), $case );
			$this->assertSame(
				array(
					'width'  => 190.0,
					'height' => 277.0,
				),
				$fallback->usable(),
				$case . ': the global format of the settings is the reference.'
			);
		}
	}

	/**
	 * The factor is the smaller of the width ratio and the height ratio, and the margins change it as the paper does.
	 *
	 * @return void
	 */
	public function test_the_factor_follows_the_limiting_dimension(): void {
		$design = array(
			'width'  => 190.0,
			'height' => 277.0,
		);
		$a5     = PrinterProfile::defaults();

		$this->assertSame( 0.674, PageRules::factor( $a5, $design ), 'A5: the width (128 / 190) is smaller than the height (190 / 277 = 0.686).' );
		$this->assertSame(
			0.85,
			PageRules::factor(
				PrinterProfile::normalize(
					array(
						'width_mm' => 210,
						'height_mm' => 297,
						'margin_mm' => 20,
					)
				),
				array(
					'width' => 200.0,
					'height' => 287.0,
				)
			),
			'The same paper with larger margins: 170 / 200.'
		);
		$this->assertSame(
			0.628,
			PageRules::factor(
				PrinterProfile::normalize(
					array(
						'width_mm' => 210,
						'height_mm' => 194,
						'margin_mm' => 10,
					)
				),
				$design
			),
			'The height limits: 174 / 277, the width gives 1.'
		);
		$this->assertSame( 1.0, PageRules::factor( $a5, array() ), 'No reference: 1.' );
		$this->assertSame( 0.674, PageRules::factor( $a5, 190.0 ), 'A number is a width alone.' );
		$this->assertSame( 0.686, PageRules::factor( $a5, array( 'height' => 277.0 ) ), 'A height alone.' );
		$this->assertSame(
			1.0,
			PageRules::factor(
				$a5,
				array(
					'width' => 128.0,
					'height' => 190.0,
				)
			),
			'The same area: 1.'
		);
	}

	/**
	 * `zoom` writes a zoom of the page and leaves the variable at 1; `scale` leaves both to the print server; `adapt` writes the variable.
	 *
	 * @return void
	 */
	public function test_the_three_scalings(): void {
		$a5     = PrinterProfile::defaults();
		$design = array(
			'width'  => 190.0,
			'height' => 277.0,
		);

		$adapt = PageRules::css( $a5, true, $design );
		$this->assertStringContainsString( '--books-k:0.674;', $adapt );
		$this->assertStringNotContainsString( 'zoom', $adapt );
		$this->assertSame( $adapt, PageRules::css( $a5, true, $design, 'adapt' ) );

		$zoom = PageRules::css( $a5, true, $design, 'zoom' );
		$this->assertStringContainsString( '--books-k:1;', $zoom, 'The theme must not adapt a page that is zoomed.' );
		$this->assertStringContainsString( 'html{zoom:0.674;}', $zoom );

		$scale = PageRules::css( $a5, true, $design, 'scale' );
		$this->assertStringContainsString( '--books-k:1;', $scale );
		$this->assertStringNotContainsString( 'zoom', $scale );

		$this->assertStringNotContainsString(
			'zoom',
			PageRules::css(
				$a5,
				true,
				array(
					'width' => 128.0,
					'height' => 190.0,
				),
				'zoom'
			),
			'A factor of 1 writes no zoom.'
		);
		$this->assertStringNotContainsString( 'zoom', PageRules::css( $a5, true, array(), 'zoom' ), 'No reference: no zoom.' );
	}

	/**
	 * Chromium zooms the margins of `@page` and the folios with the page, so `zoom` divides them by the factor to get the profile's margins and
	 * the folios at their size; `adapt` and `scale` leave them as the profile says (measured on the PDF of an A5 book: a margin of 10 mm came
	 * out as 6.7 mm with a zoom of 0.674).
	 *
	 * @return void
	 */
	public function test_zoom_undoes_its_effect_on_the_margins_and_the_folios(): void {
		$design = array(
			'width'  => 190.0,
			'height' => 277.0,
		);
		$a5     = PrinterProfile::normalize( array( 'folio' => 'outside' ) );

		$zoom = PageRules::css( $a5, true, $design, 'zoom' );
		$this->assertStringContainsString( '@page{size:148mm 210mm;margin:14.84mm;}', $zoom, '10 / 0.674: the size of the page is not divided.' );
		$this->assertStringContainsString( 'font:13.35pt sans-serif', $zoom, '9 / 0.674.' );
		$this->assertStringContainsString( '--books-margin:10mm;', $zoom, 'The variable keeps what the profile says.' );

		foreach ( array( 'adapt', 'scale' ) as $scaling ) {
			$plain = PageRules::css( $a5, true, $design, $scaling );
			$this->assertStringContainsString( '@page{size:148mm 210mm;margin:10mm;}', $plain, $scaling );
			$this->assertStringContainsString( 'font:9pt sans-serif', $plain, $scaling );
		}

		$gutter = PageRules::css(
			PrinterProfile::normalize(
				array(
					'margin_mm' => 8,
					'gutter_mm' => 20,
				)
			),
			false,
			$design,
			'zoom'
		);
		$this->assertStringContainsString( 'html{zoom:0.632;}', $gutter );
		$this->assertStringContainsString( '@page :right{margin-left:31.65mm;margin-right:12.66mm;}', $gutter, 'The gutter is divided too.' );
		$this->assertStringContainsString( '@page :left{margin-left:12.66mm;margin-right:31.65mm;}', $gutter );

		$same = PageRules::css(
			$a5,
			true,
			array(
				'width' => 128.0,
				'height' => 190.0,
			),
			'zoom'
		);
		$this->assertStringContainsString( '@page{size:148mm 210mm;margin:10mm;}', $same, 'A factor of 1: nothing to undo.' );
		$this->assertStringContainsString( 'font:9pt sans-serif', $same );
	}

	/**
	 * A project asks for a scaling; the print server is given a scale only for `scale` and a factor other than 1; the choice is validated.
	 *
	 * @return void
	 */
	public function test_a_project_gives_its_scale(): void {
		$rows     = array(
			array(
				'id'    => 5,
				'title' => 'Adapted',
				'meta'  => array( 'scaling' => 'adapt' ),
			),
			array(
				'id'    => 6,
				'title' => 'Scaled',
				'meta'  => array( 'scaling' => 'scale' ),
			),
			array(
				'id'    => 7,
				'title' => 'Wrong',
				'meta'  => array( 'scaling' => 'shrink' ),
			),
		);
		$projects = new Projects(
			static fn() => $rows,
			static fn( $id ) => 1 === $id ? array() : null,
			new class() {
				/**
				 * Root.
				 *
				 * @param int $id Project.
				 * @return int
				 */
				public function root( $id ) {
					return 10;
				}

				/**
				 * Profile.
				 *
				 * @param int $id Project.
				 * @return int
				 */
				public function profile( $id ) {
					return 1;
				}

				/**
				 * Projects that cover a post.
				 *
				 * @param int $post Post.
				 * @return int[]
				 */
				public function covering( $post ) {
					return array( 5, 6, 7 );
				}
			},
			static fn() => array(
				'width'  => 190.0,
				'height' => 277.0,
			)
		);

		$covers = $projects->covering( 1 );

		$this->assertSame( 'adapt', $covers[7]['project']['scaling'], 'A value that is not offered is replaced.' );
		$this->assertNull( $covers[5]['scale'] );
		$this->assertSame( 0.674, $covers[5]['factor'] );
		$this->assertSame( 0.674, $covers[6]['scale'], 'The print server scales.' );
		$this->assertStringContainsString( '--books-k:1;', $covers[6]['css'] );
		$this->assertStringContainsString( '--books-k:0.674;', $covers[5]['css'] );
	}

	/**
	 * The projects are read as valid values, by id.
	 *
	 * @return void
	 */
	public function test_the_projects(): void {
		$projects = $this->projects();

		$this->assertSame( array( 5, 6, 7, 8 ), array_keys( $projects->all() ) );
		$this->assertSame(
			array(
				'id'      => 6,
				'title'   => 'Flexilivre',
				'root'    => 10,
				'profile' => 21,
				'author'  => 'Eric',
				'folios'  => false,
				'scaling' => 'adapt',
			),
			$projects->get( 6 )
		);
		$this->assertTrue( $projects->get( 5 )['folios'], 'The folios are on by default.' );
		$this->assertSame( 0, $projects->get( 8 )['root'], 'No relation: no root.' );
		$this->assertSame(
			array(
				'author'  => '',
				'folios'  => true,
				'scaling' => 'adapt',
			),
			Projects::normalize( 'broken' )
		);
		$this->assertNull( $projects->get( 99 ) );
		$this->assertSame( 150.0, $projects->profile( 21 )['width_mm'] );
		$this->assertNull( $projects->profile( 99 ) );
		$this->assertNull( $projects->profile( 0 ) );
	}

	/**
	 * A post is covered by the project whose root is the post or reaches it; the one asked for wins, then the lowest id, then the filter.
	 *
	 * @return void
	 */
	public function test_the_project_of_a_post(): void {
		$projects = $this->projects();
		$found    = $projects->for_post( 100 );

		$this->assertSame( 5, $found['project']['id'], 'Two projects cover the post: the lowest id.' );
		$this->assertSame( 20.0, $found['profile']['safe_mm'] );
		$this->assertStringContainsString( '@bottom-center', $found['css'] );
		$this->assertSame( 6, $projects->for_post( 100, 6 )['project']['id'], 'The one asked for.' );
		$this->assertStringNotContainsString( 'counter(page)', $projects->for_post( 100, 6 )['css'], 'Folios off in that project.' );
		$this->assertSame( 5, $projects->for_post( 100, 7 )['project']['id'], 'A project that does not cover the post is ignored.' );
		$this->assertSame( array( 5, 6 ), array_keys( $projects->covering( 100 ) ), 'Two projects cover it; the one without a profile is left out.' );
		$this->assertSame( 5, $projects->for_post( 10 )['project']['id'], 'The root itself.' );
		$this->assertNull( $projects->for_post( 55 ), 'No project covers it.' );
		$this->assertNull( $projects->for_post( 11 ), 'The profile of the project 7 is missing: no rules.' );
		$this->assertSame( 5, $projects->for_post( 100, 7 )['project']['id'], 'A project without a profile is not used.' );

		$GLOBALS['otherguise_test_filters']['books_project_for_post'] = 6;
		$this->assertSame( 6, $projects->for_post( 100 )['project']['id'], 'The filter chooses.' );
		$GLOBALS['otherguise_test_filters']['books_project_for_post'] = 7;
		$this->assertSame( 5, $projects->for_post( 100 )['project']['id'], 'A choice that does not cover the post is ignored.' );
	}

	/**
	 * The style is added in a print mode only, for a post that a project covers.
	 *
	 * @return void
	 */
	public function test_the_print_styles(): void {
		$print    = true;
		$post     = 100;
		$asked    = 0;
		$styles   = new PrintStyles(
			$this->projects(),
			static function () use ( &$print ) {
				return $print;
			},
			static function () use ( &$post ) {
				return $post;
			},
			static function () use ( &$asked ) {
				return $asked;
			}
		);
		$recorded = static function ( $name ) {
			return array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => $call[0] === $name ) );
		};

		$print = false;
		$styles->enqueue();
		$this->assertSame( array(), $recorded( 'wp_add_inline_style' ), 'Not in the print mode: nothing.' );

		$print = true;
		$post  = 55;
		$styles->enqueue();
		$this->assertSame( array(), $recorded( 'wp_add_inline_style' ), 'No project covers the post.' );

		$post = 0;
		$styles->enqueue();
		$this->assertSame( array(), $recorded( 'wp_add_inline_style' ), 'Not a single page.' );

		$post  = 100;
		$asked = 6;
		$styles->enqueue();
		$added = $recorded( 'wp_add_inline_style' );

		$this->assertCount( 1, $added );
		$this->assertSame( PrintStyles::HANDLE, $added[0][1] );
		$this->assertStringContainsString( '@page{size:150mm 210mm', $added[0][2], 'The project asked for in the address.' );
		$this->assertCount( 1, $recorded( 'wp_register_style' ) );
		$this->assertCount( 1, $recorded( 'wp_enqueue_style' ) );
	}

	/**
	 * A double of the relations that records what is set, and reads root 11 and profile 20 for any project.
	 *
	 * @param array $calls   Receives the calls: `array( 'root'|'profile', project, id )`.
	 * @param int   $root    The root that is read.
	 * @param int   $profile The profile that is read.
	 * @return object
	 */
	private function recorder( array &$calls, $root = 11, $profile = 20 ) {
		return new class( $calls, $root, $profile ) {
			/**
			 * Calls.
			 *
			 * @var array
			 */
			private $calls;

			/**
			 * The root that is read.
			 *
			 * @var int
			 */
			private $root;

			/**
			 * The profile that is read.
			 *
			 * @var int
			 */
			private $profile;

			/**
			 * Builds the double.
			 *
			 * @param array $calls   Receives the calls.
			 * @param int   $root    The root that is read.
			 * @param int   $profile The profile that is read.
			 */
			public function __construct( array &$calls, $root, $profile ) {
				$this->calls   = &$calls;
				$this->root    = $root;
				$this->profile = $profile;
			}

			/**
			 * Root.
			 *
			 * @param int $id Project.
			 * @return int
			 */
			public function root( $id ) {
				return $this->root;
			}

			/**
			 * Profile.
			 *
			 * @param int $id Project.
			 * @return int
			 */
			public function profile( $id ) {
				return $this->profile;
			}

			/**
			 * Sets the root.
			 *
			 * @param int $project Project.
			 * @param int $section Section.
			 * @return bool
			 */
			public function set_root( $project, $section ) {
				$this->calls[] = array( 'root', $project, $section );

				return true;
			}

			/**
			 * Sets the profile.
			 *
			 * @param int $project Project.
			 * @param int $profile Profile.
			 * @return bool
			 */
			public function set_profile( $project, $profile ) {
				$this->calls[] = array( 'profile', $project, $profile );

				return true;
			}
		};
	}

	/**
	 * Builds the boxes with doubles; the posted form is `$posted`.
	 *
	 * @param array|null $posted Posted form.
	 * @param bool       $can    Whether the user may edit the post.
	 * @param bool       $valid  Whether the nonce is valid.
	 * @param array      $stored Receives what is stored.
	 * @param array      $linked Receives the relations that are set.
	 * @return MetaBoxes
	 */
	private function boxes( $posted, $can, $valid, array &$stored, array &$linked = array() ) {
		return new MetaBoxes(
			static fn() => array(
				10 => 'Book 2025',
				11 => '',
			),
			static fn() => array(
				20 => 'CoolLibri A5',
			),
			$this->recorder( $linked ),
			static fn( $id, $key ) => array(
				'author'   => 'Eric <b>',
				'width_mm' => 150,
			),
			static function ( $id, $key, $value ) use ( &$stored ) {
				$stored[] = array( $id, $key, $value );
			},
			static fn() => $can,
			static fn() => null === $posted ? null : $posted + array( '__nonce' => 'n' ),
			static fn() => $valid
		);
	}

	/**
	 * The forms show what is stored and escape it; the box is added to the two types.
	 *
	 * @return void
	 */
	public function test_the_forms(): void {
		$stored = array();
		$boxes  = $this->boxes( null, true, true, $stored );

		$boxes->add();
		$added = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'add_meta_box' === $call[0] ) );
		$this->assertSame( array( PostTypes::PROFILE, PostTypes::PROJECT ), array( $added[0][4], $added[1][4] ) );

		ob_start();
		$boxes->render_profile( (object) array( 'ID' => 3 ) );
		$profile = (string) ob_get_clean();

		$this->assertStringContainsString( 'nonce-stub', $profile );
		$this->assertStringContainsString( 'name="books_profile[width_mm]"', $profile );
		$this->assertStringContainsString( 'value="150"', $profile );
		$this->assertStringContainsString( '<option value="outside">', $profile );
		$this->assertStringContainsString( 'name="books_profile[final_blank]"', $profile );

		ob_start();
		$boxes->render_project( (object) array( 'ID' => 4 ) );
		$project = (string) ob_get_clean();

		$this->assertStringContainsString( '<option value="10">Book 2025</option>', $project );
		$this->assertStringContainsString( '<option value="11" selected="selected">#11</option>', $project, 'An untitled section is shown by its id.' );
		$this->assertStringContainsString( '<option value="20" selected="selected">CoolLibri A5</option>', $project );
		$this->assertStringContainsString( 'value="Eric &lt;b&gt;"', $project );
		$this->assertStringContainsString( 'checked="checked"', $project, 'Folios on by default.' );
	}

	/**
	 * What is posted is normalized and stored; nothing is stored without the capability, the nonce or the form.
	 *
	 * @return void
	 */
	public function test_saving(): void {
		$stored = array();

		$this->boxes(
			array(
				'width_mm' => '155,5',
				'folio'    => 'sideways',
			),
			true,
			true,
			$stored
		)->save_profile( 3 );

		$this->assertCount( 1, $stored );
		$this->assertSame( array( 3, '_books_profile' ), array( $stored[0][0], $stored[0][1] ) );
		$this->assertSame( 155.5, $stored[0][2]['width_mm'] );
		$this->assertSame( 'none', $stored[0][2]['folio'], 'A value that is not offered is replaced.' );
		$this->assertArrayNotHasKey( '__nonce', $stored[0][2] );

		$stored = array();
		$linked = array();

		$this->boxes(
			array(
				'root'    => '10',
				'profile' => '20',
				'folios'  => '0',
				'scaling' => 'zoom',
				'extra'   => 'x',
			),
			true,
			true,
			$stored,
			$linked
		)->save_project( 4 );

		$this->assertSame(
			array(
				'author'  => '',
				'folios'  => false,
				'scaling' => 'zoom',
			),
			$stored[0][2],
			'The meta holds the author, the folios and the scaling only.'
		);
		$this->assertSame( '_books_project', $stored[0][1] );
		$this->assertSame(
			array(
				array( 'root', 4, 10 ),
				array( 'profile', 4, 20 ),
			),
			$linked,
			'The root and the profile are relations.'
		);

		$stored = array();
		$linked = array();
		$this->boxes( array( 'root' => 'x' ), true, true, $stored, $linked )->save_project( 4 );
		$this->assertSame( array( array( 'root', 4, 0 ), array( 'profile', 4, 0 ) ), $linked, 'Not a number: the relation is taken away.' );

		$stored = array();
		$linked = array();
		$this->boxes( array( 'width_mm' => 1 ), false, true, $stored, $linked )->save_profile( 3 );
		$this->boxes( array( 'width_mm' => 1 ), true, false, $stored, $linked )->save_profile( 3 );
		$this->boxes( null, true, true, $stored, $linked )->save_profile( 3 );
		$this->boxes( array( 'root' => '10' ), false, true, $stored, $linked )->save_project( 3 );
		$this->boxes( array( 'root' => '10' ), true, false, $stored, $linked )->save_project( 3 );
		$this->assertSame( array(), array_merge( $stored, $linked ), 'No capability, no valid nonce, no form: nothing is stored.' );
	}

	/**
	 * The two types are not public, are edited by those who can edit others' posts, and are exported.
	 *
	 * @return void
	 */
	public function test_the_post_types(): void {
		( new PostTypes() )->register();

		$calls = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_post_type' === $call[0] ) );

		$this->assertSame( array( 'books_printer', 'books_project' ), array( $calls[0][1], $calls[1][1] ) );

		foreach ( $calls as $call ) {
			$arguments = $call[2];

			$this->assertFalse( $arguments['public'] );
			$this->assertFalse( $arguments['publicly_queryable'] );
			$this->assertFalse( $arguments['show_in_rest'], 'Not in the REST API: edited with a meta box.' );
			$this->assertFalse( $arguments['rewrite'] );
			$this->assertTrue( $arguments['exclude_from_search'] );
			$this->assertTrue( $arguments['can_export'] );
			$this->assertSame( 'options-general.php', $arguments['show_in_menu'] );
			$this->assertSame( array( 'title' ), $arguments['supports'] );
			$this->assertSame( array( 'edit_others_posts' ), array_values( array_unique( $arguments['capabilities'] ) ) );
		}
	}

	/**
	 * Builds a fake store of posts.
	 *
	 * @param array $created Receives what is created.
	 * @return object
	 */
	private function store( array &$created ) {
		return new class( $created ) {
			/**
			 * Created posts.
			 *
			 * @var array
			 */
			private $created;

			/**
			 * Builds the double.
			 *
			 * @param array $created Receives what is created.
			 */
			public function __construct( array &$created ) {
				$this->created = &$created;
			}

			/**
			 * Profiles.
			 *
			 * @return array
			 */
			public function profiles() {
				return array(
					20 => 'CoolLibri A5',
					21 => 'Flexilivre',
				);
			}

			/**
			 * The stored projects: one made before the relations, one after.
			 *
			 * @return array
			 */
			public function projects() {
				return array(
					array(
						'id'    => 5,
						'title' => 'Old',
						'meta'  => array(
							'root'    => 10,
							'profile' => 20,
							'author'  => 'Eric',
						),
					),
					array(
						'id'    => 6,
						'title' => 'New',
						'meta'  => array( 'author' => 'x' ),
					),
				);
			}

			/**
			 * Creates.
			 *
			 * @param string $type  Type.
			 * @param string $title Title.
			 * @param array  $meta  Meta.
			 * @return int
			 */
			public function create( $type, $title, array $meta ) {
				$this->created[] = array( $type, $title, $meta );

				return 40 + count( $this->created );
			}

			/**
			 * Updates a project.
			 *
			 * @param int   $id   Id.
			 * @param array $meta Meta.
			 * @return void
			 */
			public function update_project( $id, array $meta ) {
				$this->created[] = array( 'update', $id, $meta );
			}
		};
	}

	/**
	 * The commands on profiles: list, show, create (with a preset, twice, with --dry-run).
	 *
	 * @return void
	 */
	public function test_the_profile_commands(): void {
		$out     = array();
		$created = array();
		$log     = static function ( $line ) use ( &$out ) {
			$out[] = $line;
		};
		$fail    = static function ( $message ) {
			throw new RuntimeException( esc_html( $message ) );
		};
		$command = new ProfileCommand( $this->projects(), $this->store( $created ), $log, $log, $fail );

		$command->list( array(), array() );
		$this->assertSame( "20\tCoolLibri A5\t148x210\t20", $out[0], 'A profile that is not a post is shown with the defaults.' );

		$out = array();
		$command->show( array( '21' ), array() );
		$this->assertContains( 'width_mm: 150', $out );
		$this->assertContains( 'final_blank: no', $out );

		$this->expectException( RuntimeException::class );
		$command->show( array( '99' ), array() );
	}

	/**
	 * Creating a profile.
	 *
	 * @return void
	 */
	public function test_creating_a_profile(): void {
		$out     = array();
		$created = array();
		$log     = static function ( $line ) use ( &$out ) {
			$out[] = $line;
		};
		$fail    = static function ( $message ) {
			throw new RuntimeException( esc_html( $message ) );
		};
		$command = new ProfileCommand( $this->projects(), $this->store( $created ), $log, $log, $fail );

		$command->create(
			array( 'New' ),
			array(
				'dry-run' => true,
				'preset' => 'coollibri',
			)
		);
		$this->assertSame( array(), $created );
		$this->assertSame( array( 'Would create the profile "New".' ), $out );

		$command->create( array( 'New' ), array( 'preset' => 'coollibri' ) );
		$this->assertSame( PostTypes::PROFILE, $created[0][0] );
		$this->assertSame( 700, $created[0][2]['max_mb'] );

		$out = array();
		$command->create( array( 'Flexilivre' ), array() );
		$this->assertCount( 1, $created, 'A profile with that title exists: left as it is.' );
		$this->assertStringContainsString( 'exists already', $out[0] );

		foreach ( array( array( array( '' ), array() ), array( array( 'X' ), array( 'preset' => 'nope' ) ) ) as $case ) {
			try {
				$command->create( $case[0], $case[1] );
				$this->fail( 'An error was expected.' );
			} catch ( RuntimeException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}
	}

	/**
	 * The commands on projects: list, show, create, set-profile, each run twice.
	 *
	 * @return void
	 */
	public function test_the_project_commands(): void {
		$out     = array();
		$created = array();
		$log     = static function ( $line ) use ( &$out ) {
			$out[] = $line;
		};
		$fail    = static function ( $message ) {
			throw new RuntimeException( esc_html( $message ) );
		};
		$linked  = array();
		$command = new ProjectCommand( $this->projects(), $this->store( $created ), $this->recorder( $linked ), static fn( $id ) => in_array( $id, array( 10, 11, 12 ), true ), $log, $log, $fail );

		$command->list( array(), array() );
		$this->assertSame( "5\tCoolLibri\t10\t20", $out[0] );

		$out = array();
		$command->show( array( '5' ), array() );
		$this->assertContains( 'root: 10', $out );
		$this->assertStringContainsString( '@page{size:148mm 210mm', end( $out ) );
		$this->assertContains( 'factor: 1', $out, 'The factor of the project is shown (1 when the reference is not known).' );
		$this->assertContains( 'scaling: adapt', $out );

		$out = array();
		$command->show( array( '7' ), array() );
		$this->assertStringContainsString( 'none', end( $out ), 'No rules without a profile.' );

		$out = array();
		$command->create(
			array( 'Book 2026' ),
			array(
				'root' => '12',
				'profile' => '20',
				'dry-run' => true,
			)
		);
		$this->assertSame( array(), $created );
		$command->create(
			array( 'Book 2026' ),
			array(
				'root' => '12',
				'profile' => '20',
			)
		);
		$this->assertSame(
			array(
				'author'  => '',
				'folios'  => true,
				'scaling' => 'adapt',
			),
			$created[0][2],
			'The meta of a new project.'
		);
		$this->assertSame(
			array(
				array( 'root', 41, 12 ),
				array( 'profile', 41, 20 ),
			),
			$linked,
			'The root and the profile are relations.'
		);
		$command->create(
			array( 'CoolLibri' ),
			array(
				'root' => '12',
				'profile' => '20',
			)
		);
		$this->assertCount( 1, $created, 'A project with that title exists: left as it is.' );
		$command->create(
			array( 'Zoomed' ),
			array(
				'root'    => '12',
				'profile' => '20',
				'scaling' => 'zoom',
			)
		);
		$this->assertSame( 'zoom', $created[1][2]['scaling'], 'The scaling of a new project.' );
		$created = array_slice( $created, 0, 1 );
		$linked  = array_slice( $linked, 0, 2 );

		$command->set_profile( array( '5', '20' ), array() );
		$this->assertCount( 1, $created, 'Already that profile: nothing changes.' );
		$this->assertCount( 2, $linked );
		$command->set_profile( array( '5', '21' ), array( 'dry-run' => true ) );
		$this->assertCount( 1, $created );
		$this->assertCount( 2, $linked );
		$command->set_profile( array( '5', '21' ), array() );
		$this->assertCount( 1, $created );
		$this->assertSame( array( 'profile', 5, 21 ), $linked[2] );

		foreach ( array(
			array(
				'create',
				array( 'T' ),
				array(
					'root' => '99',
					'profile' => '20',
				),
			),
			array(
				'create',
				array( 'T' ),
				array(
					'root' => '12',
					'profile' => '99',
				),
			),
			array(
				'create',
				array( '' ),
				array(
					'root' => '12',
					'profile' => '20',
				),
			),
			array(
				'create',
				array( 'T' ),
				array(
					'root'    => '12',
					'profile' => '20',
					'scaling' => 'shrink',
				),
			),
			array( 'set_profile', array( '99', '20' ), array() ),
			array( 'set_profile', array( '5', '99' ), array() ),
			array( 'show', array( '99' ), array() ),
		) as $case ) {
			try {
				$command->{$case[0]}( $case[1], $case[2] );
				$this->fail( 'An error was expected for ' . $case[0] );
			} catch ( RuntimeException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}
	}

	/**
	 * The migration moves the root and the profile of the projects made before the relations from their meta, and can be run twice.
	 *
	 * @return void
	 */
	public function test_the_migration(): void {
		$out     = array();
		$created = array();
		$linked  = array();
		$log     = static function ( $line ) use ( &$out ) {
			$out[] = $line;
		};
		$command = new ProjectCommand( $this->projects(), $this->store( $created ), $this->recorder( $linked, 0, 0 ), static fn() => true, $log, $log, $log );

		$command->migrate( array(), array( 'dry-run' => true ) );
		$this->assertSame( array(), $linked );
		$this->assertSame( array(), $created );
		$this->assertSame( array( 'Would move the root and the profile of the project 5 to relations.', '1 project(s) to migrate.' ), $out );

		$out = array();
		$command->migrate( array(), array() );
		$this->assertSame(
			array(
				array( 'root', 5, 10 ),
				array( 'profile', 5, 20 ),
			),
			$linked
		);
		$this->assertSame(
			array(
				'update',
				5,
				array(
					'author'  => 'Eric',
					'folios'  => true,
					'scaling' => 'adapt',
				),
			),
			$created[0],
			'The legacy keys are dropped from the meta.'
		);
		$this->assertSame( array( '1 project(s) migrated.' ), $out );

		$linked = array();
		$other  = new ProjectCommand( $this->projects(), $this->store( $created ), $this->recorder( $linked, 10, 20 ), static fn() => true, $log, $log, $log );
		$other->migrate( array(), array() );
		$this->assertSame( array(), $linked, 'The relations exist already: they are not set again.' );
	}
}
