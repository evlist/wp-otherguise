<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the screen of the topics and of their designations (slice 628), on a real database for the statements.
 *
 * @package Otherguise
 */

use Otherguise\Index\Admin\DuplicatesScreen;
use Otherguise\Index\Admin\TopicsScreen;
use Otherguise\Index\Candidate;
use Otherguise\Index\Designation\Assigner;
use Otherguise\Index\Designations;
use Otherguise\Index\Indexer;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Merge\DuplicateFinder;
use Otherguise\Index\Merge\IgnoredPairs;
use Otherguise\Index\Merge\TopicMerger;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Observations;
use Otherguise\Index\Resolver;
use Otherguise\Index\Source;
use Otherguise\Index\Topics;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-designations.php';

/**
 * The statements are real; the topics, the designations and the kinds are in memory; the request, the nonce and the redirection are doubles.
 *
 * @covers \Otherguise\Index\Admin\TopicsScreen
 * @covers \Otherguise\Index\Admin\TopicsViews
 */
class IndexTopicsScreenDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The topics.
	 *
	 * @var Otherguise_Test_Memory_Topics
	 */
	private $topics;

	/**
	 * The designations.
	 *
	 * @var Otherguise_Test_Memory_Designations
	 */
	private $designations;

	/**
	 * The observations.
	 *
	 * @var Observations
	 */
	private $observations;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * The indexer.
	 *
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * What the source says, by post.
	 *
	 * @var array<int, Candidate[]>
	 */
	private $says = array();

	/**
	 * The request, by type and name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $request = array();

	/**
	 * The pairs that are not doubles.
	 *
	 * @var string[]
	 */
	private $saved = array();

	/**
	 * Wires the statements, a source that says what the posts say, and the indexer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Otherguise_Test_Memory_Kinds::install();
		Kinds::add( 'commune', 'Commune', 'place' );
		Kinds::add( 'admin2', 'Province', 'place' );
		otherguise_test_wp_objects( array( new WP_Post( 1 ), new WP_Post( 2 ), new WP_Post( 3 ) ) );

		$module = new IndexModule();
		$site   = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $module ) {
					$module->register_predicates( $registry );
				},
			)
		);

		$statements         = $site->statements;
		$get                = static function () use ( $statements ) {
			return $statements;
		};
		$this->topics       = new Otherguise_Test_Memory_Topics();
		$this->designations = new Otherguise_Test_Memory_Designations();
		$this->observations = new Observations( $get );
		$this->mentions     = new Mentions( $get, $this->observations, $this->designations );
		$this->says         = array();
		$this->request      = array();
		$this->saved        = array();

		$says   = &$this->says;
		$source = new class( $says ) implements Source {
			/**
			 * What it says.
			 *
			 * @var array
			 */
			private $says;

			/**
			 * Builds the source.
			 *
			 * @param array $says What it says.
			 */
			public function __construct( array &$says ) {
				$this->says = &$says;
			}

			/**
			 * The slug.
			 *
			 * @return string
			 */
			public function id() {
				return 'links';
			}

			/**
			 * The leaves.
			 *
			 * @return string[]
			 */
			public function mention_sources() {
				return array( 'post/link' );
			}

			/**
			 * Whether it is sure.
			 *
			 * @return bool
			 */
			public function is_sure() {
				return true;
			}

			/**
			 * The candidates.
			 *
			 * @param int $post_id Post.
			 * @return Candidate[]
			 */
			public function candidates( $post_id ) {
				return $this->says[ $post_id ] ?? array();
			}
		};

		$this->indexer = new Indexer( new Resolver( $this->topics ), $this->mentions, array( $source ), static fn() => true, $this->designations, $this->observations );
	}

	/**
	 * A candidate: a link to a Wikipedia page.
	 *
	 * @param string $label Label.
	 * @param string $page  The page.
	 * @param string $kind  Kind.
	 * @return Candidate
	 */
	private function link( $label, $page, $kind = 'any' ): Candidate {
		return ( new Candidate( $label, $kind, array( 'wikipedia:fr:' . $page ), null, Candidate::PASSING ) )->from( 'post/link', array( 'a' ) );
	}

	/**
	 * The screen.
	 *
	 * @param array<int, string> $urls    Receives the redirections.
	 * @param bool               $allowed Whether the user may.
	 * @param bool               $nonce   Whether the nonce is good.
	 * @return TopicsScreen
	 */
	private function screen( array &$urls, $allowed = true, $nonce = true ): TopicsScreen {
		$can      = static fn() => $allowed;
		$input    = fn( $type, $name, $is_array = false ) => $is_array ? (array) ( $this->request[ $type ][ $name ] ?? array() ) : ( is_array( $this->request[ $type ][ $name ] ?? null ) ? null : $this->request[ $type ][ $name ] ?? null );
		$verify   = static fn() => $nonce;
		$redirect = static function ( $url ) use ( &$urls ) {
			$urls[] = $url;
		};
		$ignored  = new IgnoredPairs(
			fn() => $this->saved,
			function ( array $pairs ) {
				$this->saved = $pairs;
			}
		);

		return new TopicsScreen(
			$this->topics,
			$this->designations,
			$this->observations,
			new Assigner( $this->designations, $this->observations, $this->mentions, $this->topics ),
			new DuplicatesScreen(
				new DuplicateFinder( $this->topics, array( $ignored, 'has' ) ),
				new TopicMerger( $this->topics, $this->mentions ),
				$ignored,
				$this->topics,
				static fn() => array(
					'posts'   => 0,
					'sources' => array(),
				),
				$can,
				$input,
				$verify,
				$redirect
			),
			$can,
			$input,
			$verify,
			$redirect
		);
	}

	/**
	 * The page.
	 *
	 * @param TopicsScreen $screen The screen.
	 * @return string
	 */
	private function page( TopicsScreen $screen ): string {
		ob_start();
		$screen->render();

		return (string) ob_get_clean();
	}

	/**
	 * Posts a form.
	 *
	 * @param array<string, mixed> $fields The fields.
	 * @return void
	 */
	private function post( array $fields ): void {
		$this->request[ INPUT_POST ] = array_merge( array( '_wpnonce' => 'good' ), $fields );
	}

	/**
	 * Makes two topics, the designation of a link assigned to the first, and a lone designation of a tag.
	 *
	 * @return array{department:int,river:int,design:int}
	 */
	private function world(): array {
		$river = $this->topics->create( 'Allier (rivière)', 'waterway', 0, Topics::APPROVED, array( 'wikidata:Q2464' ) );

		$this->says[1] = array( $this->link( 'Allier', 'Allier', 'admin2' ) );
		$this->says[2] = array( $this->link( 'Allier', 'Allier', 'admin2' ) );
		$this->indexer->index_post( 1 );
		$this->indexer->index_post( 2 );

		$design = (int) array_key_first( $this->designations->designations );

		return array(
			'department' => $this->observations->assignment( $design )['topic'],
			'river'      => $river,
			'design'     => $design,
		);
	}

	/**
	 * The list shows the topics with their counts; the search, the kind and the filter of the empty topics narrow it; a stranger sees nothing.
	 *
	 * @return void
	 */
	public function test_the_list_and_its_filters(): void {
		$w    = $this->world();
		$urls = array();

		$page = $this->page( $this->screen( $urls ) );

		$this->assertStringContainsString( 'Allier (rivière)', $page );
		$this->assertStringContainsString( '2 topics.', $page );
		$this->assertStringContainsString( 'nav-tab-active', $page );

		$this->request[ INPUT_GET ] = array( 's' => 'riviere' );
		$page                       = $this->page( $this->screen( $urls ) );

		$this->assertStringContainsString( '1 topic.', $page, 'The search ignores accents and case.' );
		$this->assertStringNotContainsString( '>Allier</a>', $page );

		$this->request[ INPUT_GET ] = array( 'show' => 'empty' );
		$page                       = $this->page( $this->screen( $urls ) );

		$this->assertStringContainsString( 'Allier (rivière)', $page, 'The river has no designation.' );
		$this->assertStringNotContainsString( '>Allier</a>', $page );

		$this->request[ INPUT_GET ] = array( 'kind' => 'waterway' );

		$this->assertStringContainsString( '1 topic.', $this->page( $this->screen( $urls ) ) );
		$this->assertSame( '', $this->page( $this->screen( $urls, false ) ), 'A stranger sees nothing.' );
		$this->assertNotNull( $this->topics->get( $w['river'] ) );
	}

	/**
	 * A topic: its designations with their posts and who assigned them, its identifiers; the designation shows its posts.
	 *
	 * @return void
	 */
	public function test_a_topic_and_a_designation(): void {
		$w    = $this->world();
		$urls = array();

		$this->request[ INPUT_GET ] = array( 'topic' => (string) $this->observations->assignment( $w['design'] )['topic'] );
		$page                       = $this->page( $this->screen( $urls ) );

		$this->assertStringContainsString( 'name="label"', $page );
		$this->assertStringContainsString( 'post/link|wikipedia:fr:Allier', $page );
		$this->assertStringContainsString( '<td>2</td>', $page, 'Two posts observe it.' );
		$this->assertStringContainsString( 'name="designation[]"', $page );
		$this->assertStringContainsString( 'Preview the assignment of all the designations of this topic', $page );
		$this->assertMatchesRegularExpression( '/<select name="new_kind">.*<option value="any" selected=.selected.>/s', $page, 'The kind of a new topic is not the first of the list (slice 633).' );

		$this->request[ INPUT_GET ] = array( 'designation' => (string) $w['design'] );
		$page                       = $this->page( $this->screen( $urls ) );

		$this->assertStringContainsString( 'Found in 2 posts', $page );
		$this->assertStringContainsString( 'system', $page );
	}

	/**
	 * Saving a topic: the label, the kind and the parent are changed **by hand**; a topic is not put under itself or under its own sub-topic; the capability
	 * and the nonce come first.
	 *
	 * @return void
	 */
	public function test_saving_a_topic(): void {
		$w    = $this->world();
		$urls = array();
		$kid  = $this->topics->create( 'Moulins', 'commune', $w['department'], Topics::APPROVED, array() );

		$this->post(
			array(
				'topic'  => (string) $w['river'],
				'label'  => 'Rivière Allier',
				'kind'   => 'waterway',
				'parent' => '0',
			)
		);

		try {
			$this->screen( $urls, false )->save();
		} catch ( Throwable $stopped ) {
			unset( $stopped );
		}

		try {
			$this->screen( $urls, true, false )->save();
		} catch ( Throwable $stopped ) {
			unset( $stopped );
		}

		$this->assertSame( 'Allier (rivière)', $this->topics->get( $w['river'] )['label'], 'Nothing is done without the capability or the nonce.' );

		$this->screen( $urls )->save();

		$topic = $this->topics->get( $w['river'] );

		$this->assertSame( 'Rivière Allier', $topic['label'] );
		$this->assertTrue( $topic['label_by_hand'] );
		$this->assertContains( 'label:0/riviere allier', $topic['keys'] );
		$this->assertStringContainsString( 'saved=1', end( $urls ) );

		$this->post(
			array(
				'topic'  => (string) $w['department'],
				'label'  => 'Allier',
				'kind'   => 'admin2',
				'parent' => (string) $kid,
			)
		);
		$this->screen( $urls )->save();

		$this->assertSame( 0, $this->topics->get( $w['department'] )['parent'], 'A topic is not put under its own sub-topic.' );
		$this->assertStringContainsString( 'saved=0', end( $urls ) );

		$this->post(
			array(
				'topic'  => (string) $kid,
				'label'  => 'Moulins',
				'kind'   => 'commune',
				'parent' => (string) $w['river'],
			)
		);
		$this->screen( $urls )->save();

		$this->assertSame( $w['river'], $this->topics->get( $kid )['parent'] );
	}

	/**
	 * Assigning: the first step makes a preview and changes nothing; the second does it; the mentions follow; a label is found, or a new topic is made; what
	 * cannot be found is said.
	 *
	 * @return void
	 */
	public function test_assigning_after_a_preview(): void {
		$w    = $this->world();
		$urls = array();
		$was  = $this->observations->assignment( $w['design'] )['topic'];

		$this->post(
			array(
				'op'          => 'preview',
				'topic'       => (string) $was,
				'designation' => array( (string) $w['design'] ),
				'to'          => 'Allier (rivière)',
			)
		);
		$this->screen( $urls )->assign();

		$this->assertStringContainsString( 'preview=1', end( $urls ) );
		$this->assertSame( $was, $this->observations->assignment( $w['design'] )['topic'], 'A preview changes nothing.' );

		parse_str( (string) wp_parse_url( end( $urls ), PHP_URL_QUERY ), $query );
		$this->request[ INPUT_GET ] = $query;
		$page                       = $this->page( $this->screen( $urls ) );

		$this->assertStringContainsString( '1 designation(s), 2 observation(s) in 2 post(s)', $page );
		$this->assertStringContainsString( 'will be assigned to &quot;Allier (rivière)&quot;', $page );
		$this->assertStringContainsString( 'name="op" value="apply"', $page );

		$this->post(
			array(
				'op'          => 'apply',
				'topic'       => (string) $was,
				'designation' => array( (string) $w['design'] ),
				'to'          => (string) $w['river'],
				'force'       => '1',
			)
		);
		$this->screen( $urls )->assign();

		$this->assertSame(
			array(
				'topic' => $w['river'],
				'by' => Observations::HAND,
			),
			$this->observations->assignment( $w['design'] )
		);
		$this->assertSame( array( $w['river'] ), array_keys( $this->mentions->of_post( 1 ) ), 'The mentions follow.' );
		$this->assertStringContainsString( 'assigned=1', end( $urls ) );

		$this->post(
			array(
				'op'          => 'preview',
				'designation' => array( (string) $w['design'] ),
				'to'          => 'Nowhere',
			)
		);
		$this->screen( $urls )->assign();

		$this->assertStringContainsString( 'error=', end( $urls ) );
		$this->assertStringNotContainsString( 'preview=1', end( $urls ) );

		$this->post(
			array(
				'op'          => 'apply',
				'designation' => array( (string) $w['design'] ),
				'new_label'   => 'Allier (autre)',
				'new_kind'    => 'admin2',
				'new_parent'  => '0',
				'force'       => '1',
			)
		);
		$this->screen( $urls )->assign();

		$new = $this->observations->assignment( $w['design'] )['topic'];

		$this->assertNotSame( $w['river'], $new );
		$this->assertSame( 'Allier (autre)', $this->topics->get( $new )['label'] );
		$this->assertTrue( $this->topics->get( $new )['kind_by_hand'] );

		$this->post(
			array(
				'op' => 'apply',
				'to' => (string) $w['river'],
			)
		);
		$this->screen( $urls )->assign();

		$this->assertStringContainsString( 'error=Tick', rawurldecode( end( $urls ) ), 'Nothing is ticked.' );
	}

	/**
	 * "All the designations of this topic" is one button; the capability and the nonce are checked.
	 *
	 * @return void
	 */
	public function test_all_the_designations_of_a_topic_and_the_checks(): void {
		$w    = $this->world();
		$urls = array();
		$was  = $this->observations->assignment( $w['design'] )['topic'];

		$this->post(
			array(
				'op'    => 'apply',
				'topic' => (string) $was,
				'all'   => '1',
				'to'    => (string) $w['river'],
				'force' => '1',
			)
		);

		foreach ( array( array( false, true ), array( true, false ) ) as $case ) {
			try {
				$this->screen( $urls, $case[0], $case[1] )->assign();
			} catch ( Throwable $stopped ) {
				unset( $stopped );
			}
		}

		$this->assertSame( $was, $this->observations->assignment( $w['design'] )['topic'] );

		$this->screen( $urls )->assign();

		$this->assertSame( $w['river'], $this->observations->assignment( $w['design'] )['topic'] );
		$this->assertContains( 'wikipedia:fr:Allier', $this->topics->get( $w['river'] )['keys'], 'The keys of the topic follow its designations.' );
	}

	/**
	 * The tab "To resolve" lists the designations that are pending or ambiguous; the tab "Suggestions" shows the suggestions.
	 *
	 * @return void
	 */
	public function test_the_other_tabs(): void {
		$w    = $this->world();
		$urls = array();
		$id   = $this->designations->create( 'post/link|wikipedia:fr:Attente', 'post/link', array( 'label' => 'Attente' ) );

		$this->designations->set_state(
			$id,
			Designations::PENDING,
			array(
				'topic' => 0,
				'why' => 'enrichment',
			)
		);

		$this->request[ INPUT_GET ] = array( 'tab' => 'resolve' );
		$page                       = $this->page( $this->screen( $urls ) );

		$this->assertStringContainsString( 'Attente', $page );
		$this->assertStringNotContainsString( 'post/link|wikipedia:fr:Allier</code>', $page, 'An assigned designation is not to resolve.' );

		$this->request[ INPUT_GET ] = array( 'tab' => 'suggestions' );
		$page                       = $this->page( $this->screen( $urls ) );

		$this->assertStringContainsString( 'class="suggestions"', $page );
		$this->assertNotNull( $this->topics->get( $w['department'] ) );
	}
}
