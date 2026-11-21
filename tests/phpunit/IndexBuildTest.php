<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the builder of the index, its output and its command (slice 610), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Index\Build\IndexBuilder;
use Otherguise\Index\Build\IndexRenderer;
use Otherguise\Index\Cli\BuildCommand;
use Otherguise\Index\Entries;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Module as IndexModule;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-entries.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * The statements are real; the entries and the kinds are in memory, the posts are stubs.
 *
 * @covers \Otherguise\Index\Build\IndexBuilder
 * @covers \Otherguise\Index\Build\IndexModel
 * @covers \Otherguise\Index\Build\IndexRenderer
 * @covers \Otherguise\Index\Cli\BuildCommand
 */
class IndexBuildTest extends Otherguise_Test_Database_Case {

	/**
	 * The entries.
	 *
	 * @var Otherguise_Test_Memory_Entries
	 */
	private $entries;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * The builder.
	 *
	 * @var IndexBuilder
	 */
	private $builder;

	/**
	 * Ids of the entries by name.
	 *
	 * @var array<string, int>
	 */
	private $id = array();

	/**
	 * Wires the statements, and a small book: five posts, six entries of several kinds.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Otherguise_Test_Memory_Kinds::install();
		Kinds::add( 'hamlet', 'Hamlet', 'place' );

		otherguise_test_wp_objects( array( new WP_Post( 1 ), new WP_Post( 2 ), new WP_Post( 3 ), new WP_Post( 4 ), new WP_Post( 5 ) ) );

		$module     = new IndexModule();
		$site       = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $module ) {
					$module->register_predicates( $registry );
				},
			)
		);
		$statements = $site->statements;

		$this->entries  = new Otherguise_Test_Memory_Entries();
		$this->mentions = new Mentions(
			static function () use ( $statements ) {
				return $statements;
			}
		);
		$this->builder  = new IndexBuilder( $this->entries, $this->mentions );

		$this->id['beaufort'] = $this->entries->create( 'Beaufort', 'place', 0, Entries::APPROVED, array() );
		$this->id['arberons'] = $this->entries->create( 'Les Arberons', 'hamlet', $this->id['beaufort'], Entries::APPROVED, array() );
		$this->id['doron']    = $this->entries->create( 'Doron', 'waterway', 0, Entries::APPROVED, array() );
		$this->id['merle']    = $this->entries->create( 'Étourneau', 'species', 0, Entries::APPROVED, array() );
		$this->id['eglise']   = $this->entries->create( 'église Saint-Jacques', 'other', 0, Entries::APPROVED, array() );
		$this->id['404']      = $this->entries->create( '1958', 'event', 0, Entries::APPROVED, array() );
		$this->id['pending']  = $this->entries->create( 'Pending', 'place', 0, Entries::CANDIDATE, array() );
		$this->id['refused']  = $this->entries->create( 'Refused', 'place', 0, Entries::REFUSED, array() );

		$say = fn( $post, $entry, $strength = 'passing' ) => $this->mentions->sync( $post, 'test' . $entry, array( $this->id[ $entry ] => $strength ) );

		$say( 1, 'arberons' );
		$say( 1, 'doron' );
		$say( 2, 'beaufort', 'main' );
		$say( 2, 'merle' );
		$say( 3, 'arberons', 'main' );
		$say( 3, 'eglise' );
		$say( 4, 'merle' );
		$say( 4, '404' );
		$say( 4, 'pending' );
		$say( 5, 'refused' );
	}

	/**
	 * The labels of the lines of a model, in order.
	 *
	 * @param array<int, array<string, mixed>> $lines Lines.
	 * @return string[]
	 */
	private function labels( array $lines ): array {
		return array_column( $lines, 'label' );
	}

	/**
	 * One line per entry, sorted without regard to accents or case, the candidate and the refused entries left out, the broader entry in
	 * parentheses, and the locators in the order of the posts given.
	 *
	 * @return void
	 */
	public function test_one_line_per_entry_sorted(): void {
		$lines = $this->builder->build( array( 1, 2, 3, 4, 5 ) )->lines();

		$this->assertSame( array( '1958', 'Beaufort', 'Doron', 'église Saint-Jacques', 'Étourneau', 'Les Arberons (Beaufort)' ), $this->labels( $lines ) );

		$by = array_column( $lines, null, 'label' );

		$this->assertSame(
			array(
				array(
					'post'     => 1,
					'strength' => 'passing',
				),
				array(
					'post'     => 3,
					'strength' => 'main',
				),
			),
			$by['Les Arberons (Beaufort)']['locators']
		);
		$this->assertSame( 'E', $by['Étourneau']['letter'] );
		$this->assertSame( '#', $by['1958']['letter'] );
	}

	/**
	 * The order of the locators is the order of the posts given, not the order of the ids.
	 *
	 * @return void
	 */
	public function test_the_order_of_the_book_is_the_order_of_the_locators(): void {
		$lines = array_column( $this->builder->build( array( 4, 2 ) )->lines(), null, 'label' );

		$this->assertSame( array( 4, 2 ), array_column( $lines['Étourneau']['locators'], 'post' ) );
	}

	/**
	 * A post that is not in the book leaves no trace, and an entry that no post of the book mentions is not listed.
	 *
	 * @return void
	 */
	public function test_only_the_posts_of_the_book_count(): void {
		$lines = $this->builder->build( array( 2 ) )->lines();

		$this->assertSame( array( 'Beaufort', 'Étourneau' ), $this->labels( $lines ) );
		$this->assertTrue( $this->builder->build( array() )->is_empty() );
	}

	/**
	 * `include` takes the sub-kinds too, `exclude` wins over it, and by default every kind is in.
	 *
	 * @return void
	 */
	public function test_include_and_exclude_with_sub_kinds(): void {
		$all = array( 1, 2, 3, 4 );

		$places = $this->builder->build( $all, array( 'include' => 'place' ) )->lines();

		$this->assertSame( array( 'Beaufort', 'Doron', 'Les Arberons (Beaufort)' ), $this->labels( $places ), 'A hamlet and a waterway are places.' );

		$no_water = $this->builder->build(
			$all,
			array(
				'include' => array( 'place' ),
				'exclude' => 'waterway',
			)
		)->lines();

		$this->assertSame( array( 'Beaufort', 'Les Arberons (Beaufort)' ), $this->labels( $no_water ) );

		$none = $this->builder->build( $all, array( 'exclude' => 'other,event' ) )->lines();

		$this->assertNotContains( 'église Saint-Jacques', $this->labels( $none ) );
		$this->assertNotContains( '1958', $this->labels( $none ) );
		$this->assertContains( 'Étourneau', $this->labels( $none ) );
	}

	/**
	 * The classes of a line are those of its kind and of its ancestors; the context can be left out.
	 *
	 * @return void
	 */
	public function test_classes_and_context(): void {
		$lines = array_column( $this->builder->build( array( 1, 3 ), array( 'context' => false ) )->lines(), null, 'label' );

		$this->assertSame( array( 'index-kind-hamlet', 'index-kind-place' ), $lines['Les Arberons']['classes'] );
		$this->assertSame( array( 'index-kind-waterway', 'index-kind-place' ), $lines['Doron']['classes'] );
	}

	/**
	 * The comparison of the language can be given; the groups follow the letters in order.
	 *
	 * @return void
	 */
	public function test_a_comparison_and_the_groups(): void {
		$reverse = new IndexBuilder( $this->entries, $this->mentions, static fn( $a, $b ) => strcmp( $b, $a ) );
		$model   = $reverse->build( array( 1, 2, 3, 4 ) );

		$this->assertSame( 'Les Arberons (Beaufort)', $model->lines()[0]['label'] );
		$this->assertSame( array( 'L', 'E', 'D', 'B', '#' ), array_keys( $model->groups() ) );
	}

	/**
	 * The three outputs: data, CSV (quotes, accents, main), HTML (escaped, classes, the locator function).
	 *
	 * @return void
	 */
	public function test_the_outputs(): void {
		$this->entries->entries[ $this->id['doron'] ]['label'] = 'Le "Doron" <de Beaufort>, rivière';

		$model = $this->builder->build( array( 1, 3 ) );

		$data = IndexRenderer::to_array( $model );

		$this->assertSame( array( 'E', 'L' ), array_column( $data, 'letter' ) );

		$csv = IndexRenderer::to_csv( $model );

		$this->assertStringStartsWith( "letter,label,kind,posts,main\n", $csv );
		$this->assertStringContainsString( '"Le ""Doron"" <de Beaufort>, rivière"', $csv );
		$this->assertStringContainsString( "L,\"Les Arberons (Beaufort)\",hamlet,\"1 3\",3\n", $csv );

		$html = IndexRenderer::to_html( $model, static fn( $post, $strength ) => 'main' === $strength ? '<b>p' . $post . '</b>' : 'p' . $post );

		$this->assertStringContainsString( '<h3 class="index-letter">L</h3>', $html );
		$this->assertStringContainsString( 'class="index-entry index-kind-hamlet index-kind-place"', $html );
		$this->assertStringContainsString( 'Le &quot;Doron&quot; &lt;de Beaufort&gt;, rivière', $html );
		$this->assertStringContainsString( '<span class="index-locators">p1, <b>p3</b></span>', $html );
		$this->assertStringNotContainsString( '<de Beaufort>', $html );
	}

	/**
	 * Builds the command around the builder, with a section of posts 1, 3 and 4.
	 *
	 * @param array $out  Receives what is printed.
	 * @param array $fail Receives the errors.
	 * @return BuildCommand
	 */
	private function command( array &$out, array &$fail ): BuildCommand {
		return new BuildCommand(
			$this->builder,
			static fn( $section ) => 58840 === $section ? array( 1, 3, 4 ) : array(),
			static fn( $post, $strength ) => 'main' === $strength ? '<b>' . $post . '</b>' : (string) $post,
			static function ( $line ) use ( &$out ) {
				$out[] = $line;
			},
			static function ( $line ) use ( &$fail ) {
				$fail[] = $line;
			}
		);
	}

	/**
	 * The command: a section, some posts, the three formats, the options and the errors.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$out  = array();
		$fail = array();
		$cmd  = $this->command( $out, $fail );

		$cmd( array(), array( 'section' => '58840' ) );

		$decoded = json_decode( $out[0], true );

		$this->assertSame( 'D', $decoded[1]['letter'] ?? null );
		$this->assertSame( array(), $fail );

		$out = array();
		$cmd(
			array(),
			array(
				'posts' => '4,1',
				'format' => 'csv',
				'include' => 'place',
				'no-context' => true,
			)
		);

		$this->assertSame( "letter,label,kind,posts,main\nD,Doron,waterway,1,\nL,\"Les Arberons\",hamlet,1,", $out[0] );

		$out = array();
		$cmd(
			array(),
			array(
				'section' => '58840',
				'format' => 'html',
				'exclude' => 'waterway,species,event,other',
			)
		);

		$this->assertStringContainsString( '<span class="index-locators">1, <b>3</b></span>', $out[0] );

		$cmd( array(), array() );
		$cmd(
			array(),
			array(
				'section' => '1',
				'posts' => '1',
			)
		);
		$cmd( array(), array( 'section' => '999' ) );
		$cmd( array(), array( 'posts' => 'x,0' ) );
		$cmd(
			array(),
			array(
				'posts' => '1',
				'format' => 'xml',
			)
		);

		$this->assertStringContainsString( 'not both', $fail[0] );
		$this->assertStringContainsString( 'not both', $fail[1] );
		$this->assertStringContainsString( 'empty', $fail[2] );
		$this->assertStringContainsString( 'No post id', $fail[3] );
		$this->assertStringContainsString( 'format', $fail[4] );
	}

	/**
	 * The CSV quotes what must be quoted: a backslash, a line break, a tab, a space, a comma and a quote (the same rule as `fputcsv()`); a plain
	 * field is left as it is.
	 *
	 * @return void
	 */
	public function test_the_csv_quoting(): void {
		$lines = array(
			array(
				'entry'    => 1,
				'label'    => 'plain',
				'kind'     => 'place',
				'classes'  => array(),
				'letter'   => 'P',
				'locators' => array(),
			),
			array(
				'entry'    => 2,
				'label'    => "a\\b\nc\td",
				'kind'     => 'other',
				'classes'  => array(),
				'letter'   => 'A',
				'locators' => array(
					array(
						'post'     => 7,
						'strength' => 'main',
					),
				),
			),
		);

		$this->assertSame( "letter,label,kind,posts,main\nP,plain,place,,\nA,\"a\\b\nc\td\",other,7,7\n", IndexRenderer::to_csv( new \Otherguise\Index\Build\IndexModel( $lines ) ) );
	}
}
