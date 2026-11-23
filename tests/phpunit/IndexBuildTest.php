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
use Otherguise\Index\Topics;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Module as IndexModule;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * The statements are real; the topics and the kinds are in memory, the posts are stubs.
 *
 * @covers \Otherguise\Index\Build\IndexBuilder
 * @covers \Otherguise\Index\Build\IndexModel
 * @covers \Otherguise\Index\Build\IndexRenderer
 * @covers \Otherguise\Index\Cli\BuildCommand
 */
class IndexBuildTest extends Otherguise_Test_Database_Case {

	/**
	 * The topics.
	 *
	 * @var Otherguise_Test_Memory_Topics
	 */
	private $topics;

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
	 * Ids of the topics by name.
	 *
	 * @var array<string, int>
	 */
	private $id = array();

	/**
	 * Wires the statements, and a small book: five posts, six topics of several kinds.
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

		$this->topics  = new Otherguise_Test_Memory_Topics();
		$this->mentions = new Mentions(
			static function () use ( $statements ) {
				return $statements;
			}
		);
		$this->builder  = new IndexBuilder( $this->topics, $this->mentions );

		$this->id['beaufort'] = $this->topics->create( 'Beaufort', 'place', 0, Topics::APPROVED, array() );
		$this->id['arberons'] = $this->topics->create( 'Les Arberons', 'hamlet', $this->id['beaufort'], Topics::APPROVED, array() );
		$this->id['doron']    = $this->topics->create( 'Doron', 'waterway', 0, Topics::APPROVED, array() );
		$this->id['merle']    = $this->topics->create( 'Étourneau', 'species', 0, Topics::APPROVED, array() );
		$this->id['eglise']   = $this->topics->create( 'église Saint-Jacques', 'other', 0, Topics::APPROVED, array() );
		$this->id['404']      = $this->topics->create( '1958', 'event', 0, Topics::APPROVED, array() );
		$this->id['pending']  = $this->topics->create( 'Pending', 'place', 0, Topics::CANDIDATE, array() );
		$this->id['refused']  = $this->topics->create( 'Refused', 'place', 0, Topics::REFUSED, array() );

		$say = fn( $post, $topic, $strength = 'passing' ) => $this->mentions->sync( $post, 'test' . $topic, array( $this->id[ $topic ] => $strength ) );

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
	 * One line per topic, sorted without regard to accents or case, the candidate and the refused topics left out, the broader topic in
	 * parentheses, and the locators in the order of the posts given.
	 *
	 * @return void
	 */
	public function test_one_line_per_topic_sorted(): void {
		$lines = $this->builder->build( array( 1, 2, 3, 4, 5 ) )->lines();

		$this->assertSame( array( '1958', 'Beaufort', 'Doron', 'église Saint-Jacques', 'Étourneau', 'Les Arberons (Beaufort)' ), $this->labels( $lines ) );

		$by = array_column( $lines, null, 'label' );

		$this->assertSame(
			array(
				array(
					'post'     => 1,
					'strength' => 'passing',
					'sources'  => array( 'testarberons' ),
					'via'      => array(),
				),
				array(
					'post'     => 3,
					'strength' => 'main',
					'sources'  => array( 'testarberons' ),
					'via'      => array(),
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
	 * A post that is not in the book leaves no trace, and a topic that no post of the book mentions is not listed.
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
		$reverse = new IndexBuilder( $this->topics, $this->mentions, static fn( $a, $b ) => strcmp( $b, $a ) );
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
		$this->topics->topics[ $this->id['doron'] ]['label'] = 'Le "Doron" <de Beaufort>, rivière';

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
				'topic'    => 1,
				'label'    => 'plain',
				'kind'     => 'place',
				'classes'  => array(),
				'letter'   => 'P',
				'locators' => array(),
			),
			array(
				'topic'    => 2,
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

	/**
	 * What the index owes to others: a topic that has a Wikipedia or Wikidata key owes to the Wikimedia projects, a mention that comes from an image
	 * or a track owes to OpenStreetMap; nothing owed, nothing said; a line that the selection drops owes nothing.
	 *
	 * @return void
	 */
	public function test_the_attribution(): void {
		$this->assertSame(
			array(
				'osm'       => false,
				'wikimedia' => false,
			),
			$this->builder->build( array( 1, 2, 3, 4 ) )->attribution()
		);

		$this->topics->add_keys( $this->id['merle'], array( 'wikidata:Q1234' ) );
		$this->topics->add_keys( $this->id['doron'], array( 'wikipedia:fr:Doron' ) );

		$this->assertSame(
			array(
				'osm'       => false,
				'wikimedia' => true,
			),
			$this->builder->build( array( 2, 4 ) )->attribution(),
			'The blackbird has a Wikidata key.'
		);
		$this->assertFalse( $this->builder->build( array( 2, 4 ), array( 'exclude' => 'species' ) )->attribution()['wikimedia'], 'A topic that is left out owes nothing.' );

		$this->mentions->sync( 3, 'image/address', array( $this->id['beaufort'] => 'passing' ) );

		$this->assertTrue( $this->builder->build( array( 3 ), array( 'include' => 'source:image' ) )->attribution()['osm'], 'A photograph: its place comes from OpenStreetMap.' );
		$this->assertFalse( $this->builder->build( array( 3 ), array( 'exclude' => 'source:image' ) )->attribution()['osm'] );
	}

	/**
	 * A country, a department and a hamlet below it, mentioned by three posts: the hamlet by 1 and 3, the department itself by 2.
	 *
	 * @param int $country_parent The topic above the country, 0 for none.
	 * @return void
	 */
	private function tree( $country_parent = 0 ): void {
		$this->id['france'] = $this->topics->create( 'France', 'place', $country_parent, Topics::APPROVED, array() );
		$this->id['savoie'] = $this->topics->create( 'Savoie', 'place', $this->id['france'], Topics::APPROVED, array() );
		$this->id['hameau'] = $this->topics->create( 'Hameau', 'hamlet', $this->id['savoie'], Topics::APPROVED, array() );

		$this->mentions->sync( 1, 'testhameau', array( $this->id['hameau'] => 'passing' ) );
		$this->mentions->sync( 3, 'testhameau', array( $this->id['hameau'] => 'main' ) );
		$this->mentions->sync( 2, 'testsavoie', array( $this->id['savoie'] => 'passing' ) );
	}

	/**
	 * The roll-up (slice 613): a broader topic of a kind asked for gets a line with the posts of everything below it, once per post, the strongest
	 * strength, in the order of the book; the same topic keeps its own mentions; without the option nothing changes.
	 *
	 * @return void
	 */
	public function test_rollup_gives_the_broader_topics_their_descendants(): void {
		$this->tree();

		$before = array_column( $this->builder->build( array( 3, 1, 2 ) )->lines(), null, 'label' );

		$this->assertArrayNotHasKey( 'France', $before, 'Flat by default.' );
		$this->assertSame( array( 2 ), array_column( $before['Savoie (France)']['locators'], 'post' ) );

		$lines = array_column( $this->builder->build( array( 3, 1, 2 ), array( 'rollup' => 'kind:place' ) )->lines(), null, 'label' );

		$this->assertSame( array( 3, 1, 2 ), array_column( $lines['Savoie (France)']['locators'], 'post' ), 'The posts of the hamlet and its own post, in the order of the book.' );
		$this->assertSame( array( 'main', 'passing', 'passing' ), array_column( $lines['Savoie (France)']['locators'], 'strength' ) );
		$this->assertSame( array( 3, 1, 2 ), array_column( $lines['France']['locators'], 'post' ), 'And up to the country, through the department.' );
		$this->assertSame( array( 3, 1 ), array_column( $lines['Hameau (Savoie)']['locators'], 'post' ), 'The hamlet keeps its own line.' );

		// Only the kinds asked for: the hamlet is a sub-kind of place, so asking for the hamlets rolls nothing up above it.
		$none = array_column( $this->builder->build( array( 3, 1, 2 ), array( 'rollup' => 'kind:hamlet' ) )->lines(), null, 'label' );

		$this->assertArrayNotHasKey( 'France', $none );
		$this->assertSame( array( 2 ), array_column( $none['Savoie (France)']['locators'], 'post' ) );

		$all = array_column( $this->builder->build( array( 3, 1, 2 ), array( 'rollup' => 'all' ) )->lines(), null, 'label' );

		$this->assertSame( array( 3, 1, 2 ), array_column( $all['France']['locators'], 'post' ) );
	}

	/**
	 * What is rolled up is what the selection kept; an unapproved broader topic gets no line; a wrong option is an error and rolls nothing up.
	 *
	 * @return void
	 */
	public function test_rollup_follows_the_selection_and_the_approval(): void {
		$candidate = $this->topics->create( 'Occitanie', 'place', 0, Topics::CANDIDATE, array() );

		$this->tree( $candidate );

		$lines = array_column( $this->builder->build( array( 1, 2, 3 ), array( 'rollup' => 'kind:place' ) )->lines(), null, 'label' );

		$this->assertArrayNotHasKey( 'Occitanie', $lines, 'A candidate is not in the index, rolled up or not.' );
		$this->assertArrayHasKey( 'France (Occitanie)', $lines, 'Its own line is there; the label shows the broader topic.' );

		// The hamlet is excluded by source: only the post 2, mentioned directly, rolls up.
		$kept = array_column(
			$this->builder->build(
				array( 1, 2, 3 ),
				array(
					'exclude' => 'source:testhameau',
					'rollup' => 'kind:place',
				)
			)->lines(),
			null,
			'label'
		);

		$this->assertSame( array( 2 ), array_column( $kept['France (Occitanie)']['locators'], 'post' ) );

		$this->assertSame( array(), IndexBuilder::rollup_errors( '' ) );
		$this->assertSame( array(), IndexBuilder::rollup_errors( 'all' ) );
		$this->assertSame( array(), IndexBuilder::rollup_errors( 'kind:place species' ) );
		$this->assertNotSame( array(), IndexBuilder::rollup_errors( 'source:post' ) );
		$this->assertNotSame( array(), IndexBuilder::rollup_errors( 'visibility:print' ) );
		$this->assertNotSame( array(), IndexBuilder::rollup_errors( 'colour:red' ) );

		$wrong = array_column( $this->builder->build( array( 1, 2, 3 ), array( 'rollup' => 'source:post' ) )->lines(), null, 'label' );

		$this->assertArrayNotHasKey( 'France (Occitanie)', $wrong, 'An invalid option rolls nothing up.' );
	}
}
