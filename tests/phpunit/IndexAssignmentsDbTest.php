<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the screen of the assignments (slice 630), on a real database for the statements.
 *
 * @package Otherguise
 */

use Otherguise\Index\Admin\AssignmentsScreen;
use Otherguise\Index\Admin\AssignmentsTable;
use Otherguise\Index\Designation\Assigner;
use Otherguise\Index\Designation\Exclusion;
use Otherguise\Index\Designation\Listing;
use Otherguise\Index\Designation\Neighbours;
use Otherguise\Index\Designation\Reresolver;
use Otherguise\Index\Resolver;
use Otherguise\Index\Designation\TopicFinder;
use Otherguise\Index\Designation\TopicLabel;
use Otherguise\Index\Designations;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\NoneTopic;
use Otherguise\Index\Observations;
use Otherguise\Index\Rest\AssignmentRoute;
use Otherguise\Index\Rest\TopicsRoute;
use Otherguise\Index\Topics;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-designations.php';
require_once __DIR__ . '/support/class-otherguise-test-wp-list-table.php';

/**
 * The statements are real; the topics, the motifs and the kinds are in memory; the request, the nonce and the redirection are doubles.
 *
 * @covers \Otherguise\Index\Designation\Listing
 * @covers \Otherguise\Index\Designation\TopicFinder
 * @covers \Otherguise\Index\Admin\AssignmentsScreen
 * @covers \Otherguise\Index\Admin\AssignmentsTable
 * @covers \Otherguise\Index\Rest\TopicsRoute
 * @covers \Otherguise\Index\Observations
 */
class IndexAssignmentsDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The topics.
	 *
	 * @var Otherguise_Test_Memory_Topics
	 */
	private $topics;

	/**
	 * The motifs.
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
	 * The request, by type and name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $request = array();

	/**
	 * Wires the statements.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Otherguise_Test_Memory_Kinds::install();
		Kinds::add( 'commune', 'Commune', 'place' );
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
		$this->mentions     = new Mentions( $get, $this->observations, $this->designations, NoneTopic::skipper( $this->topics ) );
		$this->request      = array();
	}

	/**
	 * A motif, observed in some posts, assigned to a topic by the system or by a person.
	 *
	 * @param string $label  Motif.
	 * @param int[]  $posts  The posts that observe it.
	 * @param int    $topic  The topic it is assigned to (0 for none).
	 * @param string $by     `system` or `hand`.
	 * @param string $state  Its state.
	 * @param string $source Its source.
	 * @return int
	 */
	private function designation( $label, array $posts, $topic = 0, $by = Observations::SYSTEM, $state = Designations::ASSIGNED, $source = 'post/link' ): int {
		$id = $this->designations->create( $source . '|' . strtolower( $label ), $source, array( 'label' => $label ) );

		foreach ( $posts as $post ) {
			$this->observations->sync( $post, $source, array_replace( $this->observations->of_post( $post, $source ), array( $id => array( 'strength' => 'main' ) ) ) );
		}

		if ( $topic > 0 ) {
			$this->observations->assign( $id, $topic, $by );
		}

		$this->designations->set_state(
			$id,
			$state,
			array(
				'topic' => $topic,
				'why'   => '',
			)
		);

		return $id;
	}

	/**
	 * The list: counts of the views, the views, the search (accents and topic labels), the sort, the pages and the number of posts.
	 *
	 * @return void
	 */
	public function test_the_list(): void {
		$river = $this->topics->create( 'Allier (rivière)', 'waterway', 0, Topics::APPROVED, array() );
		$ville = $this->topics->create( 'Moulins', 'commune', 0, Topics::APPROVED, array() );

		$this->designation( 'Allier', array( 1, 2, 3 ), $river );
		$this->designation( 'Moulins', array( 1 ), $ville, Observations::HAND );
		$this->designation( 'Lyon', array( 2 ), 0, Observations::SYSTEM, Designations::AMBIGUOUS );
		$this->designation( 'Oubliée', array(), $ville );

		$list = new Listing( $this->designations, $this->observations, $this->topics );
		$all  = $list->query( array() );

		$this->assertSame( 4, $all['total'] );
		$this->assertSame( array( 'Allier', 'Moulins', 'Oubliée', 'Lyon' ), array_column( $all['rows'], 'label' ), 'By the topic, as the topic is the first column; a motif with no topic comes last.' );
		$this->assertSame( array( 3, 1, 0, 1 ), array_column( $all['rows'], 'posts' ), 'The number of posts is asked for the lines shown.' );
		$this->assertSame( 4, $all['counts'][ Listing::ALL ] );
		$this->assertSame( 1, $all['counts'][ Listing::RESOLVE ] );
		$this->assertSame( 2, $all['counts'][ Listing::SYSTEM ] );
		$this->assertSame( 1, $all['counts'][ Listing::HAND ] );
		$this->assertNull( $all['counts'][ Listing::UNOBSERVED ], 'Counted only in its own view: it costs a pass over every observation.' );

		$this->assertSame( array( 'Lyon' ), array_column( $list->query( array( 'view' => Listing::RESOLVE ) )['rows'], 'label' ) );
		$this->assertSame( array( 'Moulins' ), array_column( $list->query( array( 'view' => Listing::HAND ) )['rows'], 'label' ) );

		$unobserved = $list->query( array( 'view' => Listing::UNOBSERVED ) );

		$this->assertSame( array( 'Oubliée' ), array_column( $unobserved['rows'], 'label' ) );
		$this->assertSame( 1, $unobserved['counts'][ Listing::UNOBSERVED ] );

		$this->assertSame( array( 'Allier' ), array_column( $list->query( array( 'search' => 'RIVIERE' ) )['rows'], 'label' ), 'The search looks in the topic too, ignoring accents and case.' );
		$this->assertSame(
			array( 'Allier', 'Lyon', 'Moulins', 'Oubliée' ),
			array_column(
				$list->query(
					array(
						'orderby' => 'posts',
						'order' => 'desc',
					)
				)['rows'],
				'label'
			)
		);
		$this->assertSame( array( 'Allier', 'Moulins', 'Oubliée', 'Lyon' ), array_column( $list->query( array( 'orderby' => 'topic' ) )['rows'], 'label' ), 'By the label of the topic, then the motif; no topic last.' );

		$page = $list->query(
			array(
				'per_page' => 3,
				'paged' => 2,
			)
		);

		$this->assertSame( array( 'Lyon' ), array_column( $page['rows'], 'label' ) );
		$this->assertSame( 2, $page['pages'] );
		$this->assertSame( 4, $page['total'] );
	}

	/**
	 * The assignments in two queries, and the counts of posts with or without a list.
	 *
	 * @return void
	 */
	public function test_the_assignments_and_the_counts(): void {
		$ville = $this->topics->create( 'Moulins', 'commune', 0, Topics::APPROVED, array() );
		$a     = $this->designation( 'Moulins', array( 1, 2 ), $ville, Observations::HAND );
		$b     = $this->designation( 'Autre', array( 3 ) );

		$this->assertSame(
			array(
				$a => array(
					'topic' => $ville,
					'by' => Observations::HAND,
				),
			),
			$this->observations->assignments()
		);
		$this->assertSame(
			array(
				$a => 2,
				$b => 1,
			),
			$this->observations->post_counts()
		);
		$this->assertSame( array( $b => 1 ), $this->observations->post_counts( array( $b ) ) );
	}

	/**
	 * The finder: a number, a label (accents and case ignored), a label that several topics bear; the search puts the labels that begin with the text first
	 * and tells the kind and the topics above.
	 *
	 * @return void
	 */
	public function test_the_finder(): void {
		$allier = $this->topics->create( 'Allier', 'admin2', 0, Topics::APPROVED, array() );
		$a      = $this->topics->create( 'Moulins', 'commune', $allier, Topics::APPROVED, array() );
		$b      = $this->topics->create( 'Moulins', 'waterway', 0, Topics::APPROVED, array() );
		$c      = $this->topics->create( 'Gare de Moulins', 'site', $a, Topics::APPROVED, array() );
		$finder = new TopicFinder( $this->topics );

		$this->assertSame( $allier, $finder->find( (string) $allier )['id'] );
		$this->assertSame( $allier, $finder->find( ' allier ' )['id'] );
		$this->assertStringContainsString( 'Several topics', $finder->find( 'moulins' )['error'] );
		$this->assertStringContainsString( (string) $a . ', ' . $b, $finder->find( 'moulins' )['error'] );
		$this->assertStringContainsString( 'No topic is called', $finder->find( 'Nulle part' )['error'] );

		$found = $finder->search( 'moul' );

		$this->assertSame( array( $a, $b, $c ), array_column( $found, 'id' ), 'The labels that begin with it first.' );
		$this->assertSame( 'Allier', $found[0]['chain'] );
		$this->assertSame( 'Commune', $found[0]['kind'] );
		$this->assertSame( array(), $finder->search( '  ' ) );
		$this->assertCount( 1, $finder->search( 'moul', 1 ) );
	}

	/**
	 * The route: it answers a search with the topics.
	 *
	 * @return void
	 */
	public function test_the_route(): void {
		$this->topics->create( 'Moulins', 'commune', 0, Topics::APPROVED, array() );

		$route   = new TopicsRoute( new TopicFinder( $this->topics ), static fn() => true );
		$request = new class() {
			/**
			 * A parameter.
			 *
			 * @param string $name Name.
			 * @return string
			 */
			public function get_param( $name ) {
				return 'search' === $name ? 'moul' : '';
			}
		};

		$this->assertSame( array( 'Moulins' ), array_column( $route->search( $request ), 'label' ) );
	}

	/**
	 * The screen.
	 *
	 * @param array<int, string> $urls    Receives the redirections.
	 * @param bool               $allowed Whether the user may.
	 * @param bool               $nonce   Whether the nonce is good.
	 * @param object|null        $table   A table, or none for the real one.
	 * @return AssignmentsScreen
	 */
	private function screen( array &$urls, $allowed = true, $nonce = true, $table = null ): AssignmentsScreen {
		$input = fn( $type, $name, $is_array = false ) => $is_array ? (array) ( $this->request[ $type ][ $name ] ?? array() ) : ( is_array( $this->request[ $type ][ $name ] ?? null ) ? null : $this->request[ $type ][ $name ] ?? null );

		return new AssignmentsScreen(
			$this->designations,
			$this->observations,
			$this->topics,
			new Assigner( $this->designations, $this->observations, $this->mentions, $this->topics ),
			$this->mentions,
			new Reresolver( $this->designations, $this->observations, $this->mentions, new Resolver( $this->topics ), $this->topics ),
			fn() => $table ?? $this->make_table(),
			static fn( $file ) => 'https://example.test/' . $file,
			static fn() => $allowed,
			$input,
			static fn() => $nonce,
			static function ( $url ) use ( &$urls ) {
				$urls[] = $url;
			}
		);
	}

	/**
	 * The real table, over the stand-in of the class of WordPress.
	 *
	 * @return AssignmentsTable
	 */
	private function make_table(): AssignmentsTable {
		return new AssignmentsTable(
			new Listing( $this->designations, $this->observations, $this->topics ),
			$this->topics,
			fn( $name ) => (string) ( $this->request[ INPUT_GET ][ $name ] ?? '' ),
			static fn( array $args ) => add_query_arg( array_merge( array( 'page' => AssignmentsScreen::PAGE ), $args ), admin_url( 'admin.php' ) )
		);
	}

	/**
	 * Posts a bulk action of the table.
	 *
	 * @param string               $action The action.
	 * @param array<string, mixed> $fields The other fields.
	 * @return void
	 */
	private function bulk( $action, array $fields ): void {
		$this->request[ INPUT_POST ] = array_merge(
			array(
				'_wpnonce' => 'good',
				'action'   => $action,
			),
			$fields
		);
	}

	/**
	 * The table: the columns, the views with their counts, the bulk actions, the lines.
	 *
	 * @return void
	 */
	public function test_the_table(): void {
		$ville = $this->topics->create( 'Moulins', 'commune', 0, Topics::APPROVED, array() );
		$id    = $this->designation( 'Moulins <b>', array( 1, 2 ), $ville );
		$table = $this->make_table();

		$this->request[ INPUT_GET ] = array( 'view' => 'nonsense' );
		$this->assertSame( Listing::ALL, $table->view(), 'A view that does not exist is the list of all.' );

		$table->prepare_items();

		$this->assertCount( 1, $table->items );
		$this->assertSame( 2, $table->items[0]['posts'] );
		$this->assertSame( 1, $table->pagination['total_items'] );
		$this->assertSame( array_keys( $table->get_columns() ), array_keys( $table->_column_headers[0] ), 'The table names its columns: WordPress prints none otherwise.' );
		$this->assertSame( 'designation', $table->_column_headers[3] );
		$this->assertSame( array( 'cb', 'topic', 'status', 'designation', 'source', 'posts', 'by', 'state' ), array_keys( $table->get_columns() ) );
		$this->assertSame( array( 'label', false ), $table->get_sortable_columns()['designation'] );
		$this->assertSame( array( 'choose', 'assign', 'confirm', 'approve', 'unassign', 'exclude', 'include', 'delete' ), array_keys( $this->call( $table, 'get_bulk_actions' ) ) );

		$views = $this->call( $table, 'get_views' );

		$this->assertSame( array( 'all', 'resolve', 'incompatible', 'excluded', 'topics', 'system', 'hand', 'unobserved' ), array_keys( $views ) );
		$this->assertStringContainsString( 'class="current"', $views['all'] );
		$this->assertStringContainsString( '(1)', $views['system'] );
		$this->assertStringNotContainsString( '(', $views['unobserved'], 'Its count is not known: it costs a pass.' );

		$item = $table->items[0];

		$this->assertStringContainsString( 'value="' . $id . '"', $this->call( $table, 'column_cb', $item ) );
		$this->assertStringContainsString( 'Moulins &lt;b&gt;', $this->call( $table, 'column_designation', $item ), 'The label is escaped.' );
		$this->assertStringContainsString( 'designation=' . $id, $this->call( $table, 'column_designation', $item ) );
		$this->assertStringContainsString( 'topic=' . $ville, $this->call( $table, 'column_topic', $item ) );
		$this->assertSame( 'system', $this->call( $table, 'column_by', $item ) );
		$this->assertSame( '2', $this->call( $table, 'column_posts', $item ) );
		$this->assertStringContainsString( 'assigned', $this->call( $table, 'column_state', $item ) );
		$this->assertSame( '—', $this->call( $table, 'column_topic', array_merge( $item, array( 'topic' => 0 ) ) ) );
	}

	/**
	 * Calls a method of the table that is not public.
	 *
	 * @param object $object The object.
	 * @param string $method The method.
	 * @param mixed  ...$args The arguments.
	 * @return mixed
	 */
	private function call( $object, $method, ...$args ) {
		$reflection = new ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( $object, ...$args );
	}

	/**
	 * The bulk actions: the capability and the nonce first; nothing ticked is said; assigning goes to a confirmation with the topic that was typed; confirming
	 * makes the assignment a decision; taking it away puts the motif to resolve and the mentions go; deleting only takes what no post observes.
	 *
	 * @return void
	 */
	public function test_the_bulk_actions(): void {
		$ville = $this->topics->create( 'Moulins', 'commune', 0, Topics::APPROVED, array() );
		$other = $this->topics->create( 'Moulins-sur-Allier', 'commune', 0, Topics::APPROVED, array() );
		$a     = $this->designation( 'Moulins', array( 1 ), $ville );
		$b     = $this->designation( 'Vide', array(), $ville );
		$urls  = array();

		$this->mentions->derive_post( 1 );

		$this->bulk( 'confirm', array( 'designation' => array( (string) $a ) ) );

		foreach ( array( array( false, true ), array( true, false ) ) as $case ) {
			try {
				$this->screen( $urls, $case[0], $case[1] )->process();
			} catch ( Throwable $stopped ) {
				unset( $stopped );
			}
		}

		$this->assertSame( Observations::SYSTEM, $this->observations->assignment( $a )['by'], 'Nothing is done without the capability or the nonce.' );

		$this->request[ INPUT_POST ] = array( '_wpnonce' => 'good' );
		$this->screen( $urls )->process();
		$this->assertSame( array(), $urls, 'The page was only loaded: no action.' );

		$this->bulk( 'confirm', array() );
		$this->screen( $urls )->process();
		$this->assertStringContainsString( 'error=Tick', rawurldecode( end( $urls ) ) );

		$this->bulk(
			'assign',
			array(
				'designation' => array( (string) $a ),
				'to' => '',
				'to_label' => 'moulins-sur-allier',
			)
		);
		$this->screen( $urls )->process();

		$this->assertStringContainsString( 'confirm=1', end( $urls ) );
		$this->assertStringContainsString( 'to=' . $other, end( $urls ) );
		$this->assertSame( $ville, $this->observations->assignment( $a )['topic'], 'A confirmation first: nothing changed.' );

		$this->bulk(
			'assign',
			array(
				'designation' => array( (string) $a ),
				'to_label' => 'Nulle part',
			)
		);
		$this->screen( $urls )->process();
		$this->assertStringContainsString( 'No topic is called', urldecode( end( $urls ) ) );

		$this->bulk( 'confirm', array( 'designation' => array( (string) $a ) ) );
		$this->screen( $urls )->process();

		$this->assertSame( Observations::HAND, $this->observations->assignment( $a )['by'] );
		$this->assertStringContainsString( 'did=confirm', end( $urls ) );

		$this->bulk( 'delete', array( 'designation' => array( (string) $a, (string) $b ) ) );
		$this->screen( $urls )->process();

		$this->assertNotNull( $this->designations->get( $a ), 'A motif that a post observes is not deleted.' );
		$this->assertNull( $this->designations->get( $b ) );
		$this->assertStringContainsString( 'skipped=1', end( $urls ) );

		$this->bulk( 'unassign', array( 'designation' => array( (string) $a ) ) );
		$this->screen( $urls )->process();

		$this->assertNull( $this->observations->assignment( $a ) );
		$this->assertSame( Designations::AMBIGUOUS, $this->designations->get( $a )['state'] );
		$this->assertSame( array(), $this->mentions->of_post( 1 ), 'The mentions go with the assignment.' );
	}

	/**
	 * The confirmation: a page that says what will happen; the second form assigns, the mentions follow, a conflict of kinds is refused unless forced.
	 *
	 * @return void
	 */
	public function test_the_confirmation_and_the_assignment(): void {
		$river = $this->topics->create( 'Allier (rivière)', 'waterway', 0, Topics::APPROVED, array() );
		$dept  = $this->topics->create( 'Allier', 'commune', 0, Topics::APPROVED, array() );
		$id    = $this->designation( 'Allier', array( 1, 2 ), $dept );
		$urls  = array();

		$this->mentions->derive_post( 1 );
		$this->mentions->derive_post( 2 );

		$this->request[ INPUT_GET ] = array(
			'confirm' => '1',
			'ids'     => (string) $id,
			'to'      => (string) $river,
		);

		ob_start();
		$this->screen( $urls )->render();
		$page = (string) ob_get_clean();

		$this->assertStringContainsString( '1 motif(s), 2 observation(s) in 2 post(s), will be assigned to &quot;Allier (rivière)&quot;', $page );
		$this->assertStringContainsString( 'name="action" value="' . AssignmentsScreen::APPLY . '"', $page );

		$this->request[ INPUT_POST ] = array(
			'_wpnonce'    => 'good',
			'designation' => array( (string) $id ),
			'to'          => (string) $river,
		);
		$this->screen( $urls )->apply();

		$this->assertStringContainsString( 'error=', end( $urls ), 'The kinds exclude each other: refused.' );
		$this->assertSame( $dept, $this->observations->assignment( $id )['topic'] );

		$this->request[ INPUT_POST ]['force'] = '1';
		$this->screen( $urls )->apply();

		$this->assertSame(
			array(
				'topic' => $river,
				'by' => Observations::HAND,
			),
			$this->observations->assignment( $id )
		);
		$this->assertSame( array( $river ), array_keys( $this->mentions->of_post( 2 ) ), 'The mentions follow.' );
		$this->assertStringContainsString( 'did=assign', end( $urls ) );

		$this->request[ INPUT_POST ]['designation'] = array();
		$this->screen( $urls )->apply();
		$this->assertStringContainsString( 'error=Tick', rawurldecode( end( $urls ) ) );
	}

	/**
	 * The page: the table, with its views and its search, in two forms; a stranger sees nothing; the script is loaded on this screen only.
	 *
	 * @return void
	 */
	public function test_the_page(): void {
		$urls  = array();
		$table = new class() {
			/**
			 * What was called.
			 *
			 * @var string[]
			 */
			public $called = array();

			/**
			 * Prepares the items.
			 *
			 * @return void
			 */
			public function prepare_items() {
				$this->called[] = 'prepare';
			}

			/**
			 * Prints the views.
			 *
			 * @return void
			 */
			public function views() {
				$this->called[] = 'views';
			}

			/**
			 * Prints the search box.
			 *
			 * @param string $text The text of the button.
			 * @param string $id   The identifier.
			 * @return void
			 */
			public function search_box( $text, $id ) {
				$this->called[] = 'search:' . $id;
			}

			/**
			 * Prints the bar of the filters.
			 *
			 * @return void
			 */
			public function filter_bar() {
				$this->called[] = 'filters';
			}

			/**
			 * Prints the box of the target.
			 *
			 * @return void
			 */
			public function target_fields() {
				$this->called[] = 'target';
			}

			/**
			 * Prints the table.
			 *
			 * @return void
			 */
			public function display() {
				$this->called[] = 'display';
				echo '<table id="the-table"></table>';
			}
		};

		ob_start();
		$this->screen( $urls, true, true, $table )->render();
		$page = (string) ob_get_clean();

		$this->assertSame( array( 'prepare', 'views', 'search:assignment', 'filters', 'target', 'display' ), $table->called );
		$this->assertStringContainsString( 'Search motifs (top right) looks in the label', $page, 'A line of help says what each field searches.' );
		$this->assertSame( 2, substr_count( $page, '<form' ), 'The search is a form of its own; the bulk actions post to the screen itself.' );
		$this->assertStringContainsString( 'page=otherguise-index-assignments', $page );

		ob_start();
		$this->screen( $urls, false, true, $table )->render();

		$this->assertSame( '', (string) ob_get_clean(), 'A stranger sees nothing.' );
		$this->assertSame( AssignmentsScreen::PAGE, $this->screen( $urls )->declare_screen( null )[0]['page'] );
	}

	/**
	 * A motif that hesitated between topics: the list of the table offers them (with the kind and the topics above each, to tell the namesakes apart); a
	 * motif that has no candidate shows nothing to choose.
	 *
	 * @return void
	 */
	public function test_the_list_of_the_choices(): void {
		$spain = $this->topics->create( 'Espagne', 'country', 0, Topics::APPROVED, array() );
		$a     = $this->topics->create( 'Fisterra', 'commune', $spain, Topics::APPROVED, array() );
		$b     = $this->topics->create( 'Fisterra', 'site', 0, Topics::APPROVED, array() );
		$id    = $this->designation( 'A Atalaia', array( 1 ), 0, Observations::SYSTEM, Designations::AMBIGUOUS );

		$this->designations->set_state(
			$id,
			Designations::AMBIGUOUS,
			array(
				'topic'     => 0,
				'why'       => 'label',
				'ambiguity' => array(
					'level'      => 'Fisterra',
					'key'        => 'label:' . $spain . '/fisterra',
					'candidates' => array( $a, $b ),
				),
			)
		);
		$plain = $this->designation( 'Sans candidat', array( 2 ), 0, Observations::SYSTEM, Designations::AMBIGUOUS );
		$table = $this->make_table();

		$table->prepare_items();

		$rows = array_column( $table->items, null, 'id' );
		$html = $this->call( $table, 'column_topic', $rows[ $id ] );

		$this->assertStringContainsString( 'Which &quot;Fisterra&quot;?', $html );
		$this->assertStringContainsString( 'name="choice[' . $id . ']"', $html );
		$this->assertStringContainsString( 'Fisterra (Espagne) · Commune #' . $a, $html, 'The label of the index, then the kind: they tell the namesakes apart (slice 639).' );
		$this->assertStringContainsString( 'Fisterra · Site (below the commune) #' . $b, $html );
		$this->assertSame( '—', $this->call( $table, 'column_topic', $rows[ $plain ] ), 'Nothing to choose from.' );
	}

	/**
	 * A motif whose only topic is of a kind that the source cannot mean (slice 632): it is said **incompatible**, with both kinds, in a view of its own, and the
	 * list offers to keep the topic.
	 *
	 * @return void
	 */
	public function test_an_incompatible_designation_is_said_so(): void {
		$vaucluse = $this->topics->create( 'Vaucluse', 'admin2', 0, Topics::APPROVED, array() );
		$apt      = $this->topics->create( 'Apt', 'species', $vaucluse, Topics::APPROVED, array() );
		$id       = $this->designation( 'Apt (chain)', array( 1 ), 0, Observations::SYSTEM, Designations::AMBIGUOUS );

		$this->designations->set_state(
			$id,
			Designations::AMBIGUOUS,
			array(
				'topic'     => 0,
				'why'       => 'label',
				'ambiguity' => array(
					'type'       => 'incompatible',
					'level'      => 'Apt',
					'key'        => 'label:' . $vaucluse . '/apt',
					'candidates' => array( $apt ),
					'found_kind' => 'species',
					'asked_kind' => 'commune',
				),
			)
		);

		$list = ( new Listing( $this->designations, $this->observations, $this->topics ) )->query( array( 'view' => Listing::INCOMPATIBLE ) );

		$this->assertSame( array( $id ), array_column( $list['rows'], 'id' ) );
		$this->assertSame( 1, $list['counts'][ Listing::INCOMPATIBLE ] );

		$table = $this->make_table();

		$table->prepare_items();

		$rows = array_column( $table->items, null, 'id' );
		$html = $this->call( $table, 'column_topic', $rows[ $id ] );

		$this->assertStringContainsString( 'is incompatible: the topic is a Species, the source says Commune.', html_entity_decode( $html ) );
		$this->assertStringNotContainsString( 'Which', $html );
		$this->assertStringContainsString( 'Keep Apt (Vaucluse) · Species #' . $apt, $html );
		$this->assertStringContainsString( 'incompatible', $this->call( $table, 'column_state', $rows[ $id ] ) );
	}

	/**
	 * The bulk action "Apply the choices": a line with a choice counts, ticked or not; a topic that is not a candidate is refused and said; nothing chosen is said.
	 *
	 * @return void
	 */
	public function test_applying_the_choices(): void {
		$spain = $this->topics->create( 'Espagne', 'country', 0, Topics::APPROVED, array( 'label:0/espagne' ) );
		$a     = $this->topics->create( 'Fisterra', 'commune', $spain, Topics::APPROVED, array( 'label:' . $spain . '/fisterra' ) );
		$b     = $this->topics->create( 'Fisterra', 'commune', $spain, Topics::APPROVED, array( 'label:' . $spain . '/fisterra' ) );
		$other = $this->topics->create( 'Ailleurs', 'commune', 0, Topics::APPROVED, array() );
		$id    = $this->designation( 'A Atalaia', array( 1 ), 0, Observations::SYSTEM, Designations::AMBIGUOUS );

		$this->designations->designations[ $id ]['evidence'] = array(
			'label'  => 'A Atalaia',
			'kind'   => 'commune',
			'keys'   => array(),
			'levels' => array(
				array(
					'label' => 'Espagne',
					'kind' => 'country',
					'keys' => array(),
				),
				array(
					'label' => 'Fisterra',
					'kind' => 'commune',
					'keys' => array(),
				),
				array(
					'label' => 'A Atalaia',
					'kind' => 'commune',
					'keys' => array(),
				),
			),
		);
		$this->designations->set_state(
			$id,
			Designations::AMBIGUOUS,
			array(
				'topic'     => 0,
				'why'       => 'label',
				'ambiguity' => array(
					'level'      => 'Fisterra',
					'key'        => 'label:' . $spain . '/fisterra',
					'candidates' => array( $a, $b ),
				),
			)
		);

		$urls = array();

		$this->bulk( 'choose', array( 'choice' => array( (string) $id => '' ) ) );
		$this->screen( $urls )->process();
		$this->assertStringContainsString( 'Choose a topic in at least one list', urldecode( end( $urls ) ) );

		$this->bulk( 'choose', array( 'choice' => array( (string) $id => (string) $other ) ) );
		$this->screen( $urls )->process();
		$this->assertStringContainsString( 'not one of those', urldecode( end( $urls ) ) );
		$this->assertNull( $this->observations->assignment( $id ) );

		$this->bulk( 'choose', array( 'choice' => array( (string) $id => (string) $b ) ) );
		$this->screen( $urls )->process();

		$this->assertStringContainsString( 'did=choose', end( $urls ) );

		$have = $this->observations->assignment( $id );

		$this->assertSame( Observations::HAND, $have['by'] );
		$this->assertSame( 'A Atalaia', $this->topics->get( $have['topic'] )['label'] );
		$this->assertSame( $b, $this->topics->get( $have['topic'] )['parent'], 'The level that was missing is made under the topic that was chosen.' );
		$this->assertSame( Designations::ASSIGNED, $this->designations->get( $id )['state'] );
		$this->assertSame( array( $have['topic'] ), array_keys( $this->mentions->of_post( 1 ) ) );
		$this->assertSame( $b, $this->topics->pinned( 'label:' . $spain . '/fisterra' ), 'The choice is kept for the next motif that hesitates the same way.' );
	}

	/**
	 * A new topic in the assignment: the confirmation says "a new topic"; the second step makes it (the kind chosen is a decision of a person) and assigns; an
	 * assignment that is refused does not leave the topic behind.
	 *
	 * @return void
	 */
	public function test_assigning_to_a_new_topic(): void {
		$river = $this->topics->create( 'Allier (rivière)', 'waterway', 0, Topics::APPROVED, array() );
		$id    = $this->designation( 'Allier', array( 1, 2 ), $river );
		$urls  = array();

		$this->bulk(
			'assign',
			array(
				'designation' => array( (string) $id ),
				'to' => '',
				'to_label' => '',
				'new_label' => 'Allier (département)',
				'new_kind' => 'commune',
				'new_parent' => '0',
			)
		);
		$this->screen( $urls )->process();

		$this->assertStringContainsString( 'confirm=1', end( $urls ) );
		$this->assertStringContainsString( 'new_label=', end( $urls ) );

		parse_str( (string) wp_parse_url( end( $urls ), PHP_URL_QUERY ), $query );
		$this->request[ INPUT_GET ] = $query;

		ob_start();
		$this->screen( $urls )->render();
		$page = (string) ob_get_clean();

		$this->assertStringContainsString( 'will be assigned to &quot;Allier (département)&quot; (a new topic)', $page );
		$this->assertStringContainsString( 'name="new_label" value="Allier (département)"', $page );

		$before = count( $this->topics->topics );

		$this->request[ INPUT_POST ] = array(
			'_wpnonce'    => 'good',
			'designation' => array( (string) $id ),
			'to'          => '',
			'new_label'   => 'Allier (département)',
			'new_kind'    => 'commune',
			'new_parent'  => '0',
		);
		$this->screen( $urls )->apply();

		$this->assertSame( $before, count( $this->topics->topics ), 'The kinds exclude each other: refused, and the topic made for it is not kept.' );
		$this->assertStringContainsString( 'error=', end( $urls ) );

		$this->request[ INPUT_POST ]['force'] = '1';
		$this->screen( $urls )->apply();

		$new = $this->observations->assignment( $id )['topic'];

		$this->assertSame( 'Allier (département)', $this->topics->get( $new )['label'] );
		$this->assertTrue( $this->topics->get( $new )['kind_by_hand'] );
		$this->assertSame( Observations::HAND, $this->observations->assignment( $id )['by'] );
	}

	/**
	 * The box of the target: an existing topic (a field with suggestions), or a new one (its label, its kind, the topic it goes under, which a field searches
	 * too); every field has a visible label, and the kind that is chosen first is "not classified".
	 *
	 * @return void
	 */
	public function test_the_box_of_the_target(): void {
		$table = $this->make_table();

		ob_start();
		$table->target_fields();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<legend><strong>Where &quot;Assign to the topic…&quot; puts the ticked lines', $html );
		$this->assertStringContainsString( 'An existing topic', $html );
		$this->assertStringContainsString( 'data-topic-picker="assignment-topic-id"', $html );
		$this->assertStringContainsString( 'data-topic-picker="assignment-parent-id"', $html, 'The topic above is searched as one types, not given by a number.' );
		$this->assertStringContainsString( 'name="new_parent" value=""', $html );
		$this->assertMatchesRegularExpression( '/<option value="any" selected="selected">/', $html, 'The kind that is chosen first is "not classified".' );

		foreach ( array( 'Label', 'Kind', 'Under the topic' ) as $label ) {
			$this->assertStringContainsString( '>' . $label . '</label>', $html );
		}
	}

	/**
	 * The search of the list looks in the identifier of Wikidata and in the state too; a search keeps the sort and the view.
	 *
	 * @return void
	 */
	public function test_the_search_looks_in_the_identifier_and_the_state(): void {
		$id = $this->designation( 'Allier', array( 1 ) );

		$this->designations->set_enrichment(
			$id,
			array(
				'state' => 'done',
				'qid' => 'Q2464',
			)
		);
		$this->designation( 'Lyon', array( 2 ), 0, Observations::SYSTEM, Designations::AMBIGUOUS );

		$list = new Listing( $this->designations, $this->observations, $this->topics );

		$this->assertSame( array( 'Allier' ), array_column( $list->query( array( 'search' => 'q2464' ) )['rows'], 'label' ) );
		$this->assertSame( array( 'Lyon' ), array_column( $list->query( array( 'search' => 'ambiguous' ) )['rows'], 'label' ) );
	}

	/**
	 * A new topic under a topic that was typed by its name: the number is found; a name that names no topic is said; what the page was showing (the view, the
	 * search, the sort, the page) is kept after an action.
	 *
	 * @return void
	 */
	public function test_the_new_topic_under_a_name_and_what_the_page_shows_is_kept(): void {
		$fist = $this->topics->create( 'Fisterra', 'commune', 0, Topics::APPROVED, array() );
		$id   = $this->designation( 'A Atalaia', array( 1 ) );
		$urls = array();

		$this->bulk(
			'assign',
			array(
				'designation' => array( (string) $id ),
				'new_label' => 'A Atalaia',
				'new_kind' => 'site',
				'new_parent' => '',
				'new_parent_label' => 'fisterra',
			)
		);
		$this->screen( $urls )->process();

		$this->assertStringContainsString( 'new_parent=' . $fist, end( $urls ) );

		$this->bulk(
			'assign',
			array(
				'designation' => array( (string) $id ),
				'new_label' => 'A Atalaia',
				'new_parent_label' => 'Nulle part',
			)
		);
		$this->screen( $urls )->process();

		$this->assertStringContainsString( 'No topic is called', urldecode( end( $urls ) ) );
		$this->assertStringNotContainsString( 'confirm=1', end( $urls ) );

		$this->bulk(
			'confirm',
			array(
				'designation' => array( (string) $id ),
				'view'        => 'system',
				's'           => 'atalaia',
				'orderby'     => 'posts',
				'order'       => 'desc',
				'paged'       => '2',
			)
		);
		$this->screen( $urls )->process();

		foreach ( array( 'view=system', 's=atalaia', 'orderby=posts', 'order=desc', 'paged=2', 'did=confirm' ) as $kept ) {
			$this->assertStringContainsString( $kept, end( $urls ) );
		}
	}

	/**
	 * The confirmation says the kinds only when they exclude each other, and what the page was showing is kept through the confirmation and the assignment
	 * (slice 633).
	 *
	 * @return void
	 */
	public function test_the_warning_is_true_and_the_search_is_kept(): void {
		$river = $this->topics->create( 'Allier (rivière)', 'waterway', 0, Topics::APPROVED, array() );
		$dept  = $this->topics->create( 'Allier', 'commune', 0, Topics::APPROVED, array() );
		$town  = $this->topics->create( 'Moulins', 'admin3', 0, Topics::APPROVED, array() );
		$id    = $this->designation( 'Allier', array( 1 ), $dept );
		$urls  = array();
		$kept  = array(
			's'      => 'post/tag',
			'view'   => 'hand',
			'paged'  => '2',
			'orderby' => 'posts',
		);

		$this->request[ INPUT_GET ] = array_merge(
			$kept,
			array(
				'confirm' => '1',
				'ids'     => (string) $id,
				'to'      => (string) $river,
			)
		);

		ob_start();
		$this->screen( $urls )->render();
		$page = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $page );
		$this->assertStringContainsString( 'is assigned to a topic of the kind Commune, and the topic chosen is of the kind Waterway', $page );
		$this->assertStringContainsString( 'name="force"', $page );
		$this->assertStringContainsString( 'name="s" value="post/tag"', $page, 'The search goes back with the form.' );
		$this->assertStringContainsString( 's=post%2Ftag', $page, 'And the link that cancels.' );

		$this->request[ INPUT_GET ]['to'] = (string) $town;

		ob_start();
		$this->screen( $urls )->render();
		$page = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'notice-warning', $page, 'A commune is a sort of third-level division: nothing to say.' );
		$this->assertStringNotContainsString( 'name="force"', $page );

		$this->request[ INPUT_POST ] = array_merge(
			$kept,
			array(
				'_wpnonce'    => 'good',
				'designation' => array( (string) $id ),
				'to'          => (string) $town,
			)
		);
		$this->screen( $urls )->apply();

		$this->assertStringContainsString( 's=post%2Ftag', end( $urls ) );
		$this->assertStringContainsString( 'view=hand', end( $urls ) );
		$this->assertStringContainsString( 'paged=2', end( $urls ) );
		$this->assertStringContainsString( 'did=assign', end( $urls ) );

		$this->bulk(
			'assign',
			array_merge(
				$kept,
				array(
					'designation' => array( (string) $id ),
					'to' => (string) $river,
				)
			)
		);
		$this->screen( $urls )->process();

		$this->assertStringContainsString( 'confirm=1', end( $urls ) );
		$this->assertStringContainsString( 's=post%2Ftag', end( $urls ), 'The confirmation knows what the page was showing.' );
	}

	/**
	 * The close topics of a motif: the same name once the parentheses are left out, from its label and from the label of its topic; not the topic it has.
	 *
	 * @return void
	 */
	public function test_the_close_topics(): void {
		$river = $this->topics->create( "Aisne (affluent de l'Oise)", 'waterway', 0, Topics::APPROVED, array() );
		$other = $this->topics->create( 'Aisne', 'admin2', 0, Topics::APPROVED, array() );
		$dept  = $this->topics->create( 'Aisne (02)', 'admin2', 0, Topics::APPROVED, array() );
		$long  = $this->topics->create( 'Aisne-Marne', 'any', 0, Topics::APPROVED, array() );
		$gone  = $this->topics->create( 'Aisne (refusé)', 'any', 0, Topics::REFUSED, array() );
		$this->topics->create( 'Allier', 'admin2', 0, Topics::APPROVED, array() );
		$id = $this->designation( 'aisne (02)', array( 1 ), $dept, Observations::SYSTEM, Designations::ASSIGNED, 'post/tag' );

		$close = ( new Neighbours( $this->designations, $this->observations, $this->topics, new TopicFinder( $this->topics ) ) )->of( $id );
		$ids   = array_column( $close, 'id' );

		$this->assertNotContains( $dept, $ids, 'The topic that it has is not offered.' );
		$this->assertNotContains( $gone, $ids, 'A refused topic is not offered.' );
		$this->assertEqualsCanonicalizing( array( $river, $other, $long ), $ids );
		$this->assertEqualsCanonicalizing( array( $river, $other ), array_slice( $ids, 0, 2 ), 'The same name once the parentheses are left out comes first.' );
		$this->assertSame( $long, $ids[2], 'What only begins with it comes after.' );
		$this->assertSame( array(), ( new Neighbours( $this->designations, $this->observations, $this->topics, new TopicFinder( $this->topics ) ) )->of( 9999 ) );
	}

	/**
	 * The quick edit through the REST API: the close topics; the assignment by hand; the kinds that exclude each other are said and nothing changes until it is forced.
	 *
	 * @return void
	 */
	public function test_the_routes_of_the_quick_edit(): void {
		$river = $this->topics->create( 'Allier (rivière)', 'waterway', 0, Topics::APPROVED, array() );
		$dept  = $this->topics->create( 'Allier', 'commune', 0, Topics::APPROVED, array() );
		$id    = $this->designation( 'Allier', array( 1 ), $dept );
		$finder = new TopicFinder( $this->topics );
		$route  = new AssignmentRoute(
			$this->designations,
			$this->topics,
			new Assigner( $this->designations, $this->observations, $this->mentions, $this->topics ),
			new Neighbours( $this->designations, $this->observations, $this->topics, $finder ),
			$finder,
			static fn() => true
		);
		$ask    = static fn( array $params ) => new class( $params ) {
			/**
			 * The parameters.
			 *
			 * @var array
			 */
			private $params;

			/**
			 * Builds the request.
			 *
			 * @param array $params The parameters.
			 */
			public function __construct( array $params ) {
				$this->params = $params;
			}

			/**
			 * A parameter.
			 *
			 * @param string $name Name.
			 * @return mixed
			 */
			public function get_param( $name ) {
				return $this->params[ $name ] ?? null;
			}
		};

		$this->assertSame( array( $river ), array_column( $route->neighbours( $ask( array( 'id' => $id ) ) ), 'id' ) );

		$no = $route->assign(
			$ask(
				array(
					'id' => $id,
					'topic' => $river,
				)
			)
		);

		$this->assertFalse( $no['ok'] );
		$this->assertTrue( $no['conflict'] );
		$this->assertStringContainsString( 'The topic that it has is a Commune, the one you chose is a Waterway', $no['error'] );
		$this->assertSame( $dept, $this->observations->assignment( $id )['topic'], 'Nothing changed.' );

		$yes = $route->assign(
			$ask(
				array(
					'id' => $id,
					'topic' => $river,
					'force' => true,
				)
			)
		);

		$this->assertTrue( $yes['ok'] );
		$this->assertSame( 'Allier (rivière)', $yes['topic']['label'] );
		$this->assertSame( 'hand', $yes['by'] );
		$this->assertSame( Topics::APPROVED, $yes['status'], 'The status of the topic, for the column of the line.' );
		$this->assertStringContainsString( 'topic=' . $river, $yes['topic']['url'], 'The line changed in place gets the link of its topic.' );
		$this->assertSame(
			array(
				'topic' => $river,
				'by' => Observations::HAND,
			),
			$this->observations->assignment( $id )
		);

		$this->assertFalse(
			$route->assign(
				$ask(
					array(
						'id' => 9999,
						'topic' => $river,
					)
				)
			)['ok']
		);
		$this->assertFalse(
			$route->assign(
				$ask(
					array(
						'id' => $id,
						'topic' => 9999,
					)
				)
			)['ok']
		);
	}

	/**
	 * Excluding motifs from the index (slice 635): the bulk action takes the motif out (no topic, the mentions go), the view *Excluded* lists it and says so in the
	 * column of the topic, the same action in the quick edit does it by REST, and *Include again* puts it back to wait for a reading.
	 *
	 * @return void
	 */
	public function test_excluding_a_motif_from_the_index(): void {
		$ville = $this->topics->create( 'Moulins', 'commune', 0, Topics::APPROVED, array() );
		$a     = $this->designation( 'allemagne', array( 1 ), $ville, Observations::SYSTEM, Designations::ASSIGNED, 'post/tag' );
		$b     = $this->designation( 'voyages', array( 1 ), $ville, Observations::SYSTEM, Designations::ASSIGNED, 'post/tag' );
		$urls  = array();

		$this->mentions->derive_post( 1 );

		$this->bulk( 'exclude', array( 'designation' => array( (string) $a ) ) );
		$this->screen( $urls )->process();

		$this->assertStringContainsString( 'did=exclude', end( $urls ) );
		$none = ( new NoneTopic( $this->topics ) )->id();

		$this->assertGreaterThan( 0, $none, 'The topic _None_ is made the first time it is used.' );
		$this->assertSame(
			array(
				'topic' => $none,
				'by'    => Observations::HAND,
			),
			$this->observations->assignment( $a ),
			'Assigned to _None_, by hand.'
		);
		$this->assertSame( Topics::REFUSED, $this->topics->get( $none )['status'] );
		$this->assertSame( $ville, $this->observations->assignment( $b )['topic'], 'Only the ticked motif.' );

		$list = ( new Listing( $this->designations, $this->observations, $this->topics ) )->query( array( 'view' => Listing::EXCLUDED ) );

		$this->assertSame( array( $a ), array_column( $list['rows'], 'id' ) );
		$this->assertSame( 1, $list['counts'][ Listing::EXCLUDED ] );

		$table = $this->make_table();

		$table->prepare_items();

		$rows = array_column( $table->items, null, 'id' );

		$this->assertStringContainsString( '>_None_</a> <small>not in the index</small>', $this->call( $table, 'column_topic', $rows[ $a ] ), 'It is a topic like the others, named _None_.' );
		$this->assertSame( 'refused', $this->call( $table, 'column_status', $rows[ $a ] ) );

		$route = $this->route();
		$done  = $route->assign(
			$this->ask(
				array(
					'id' => $b,
					'exclude' => true,
				)
			)
		);

		$this->assertTrue( $done['ok'] );
		$this->assertTrue( $done['excluded'] );
		$this->assertSame( 'refused', $done['status'] );
		$this->assertSame( $none, $this->observations->assignment( $b )['topic'] );
		$this->assertFalse(
			$route->assign(
				$this->ask(
					array(
						'id' => 9999,
						'exclude' => true,
					)
				)
			)['ok'],
			'A motif that does not exist.'
		);

		$this->bulk( 'include', array( 'designation' => array( (string) $a, (string) $b ) ) );
		$this->screen( $urls )->process();

		$this->assertStringContainsString( 'did=include', end( $urls ) );
		$this->assertSame( Designations::PENDING, $this->designations->get( $a )['state'] );
		$this->assertSame( Designations::PENDING, $this->designations->get( $b )['state'] );
	}

	/**
	 * The routes of the quick edit, built over the doubles of this test.
	 *
	 * @return AssignmentRoute
	 */
	private function route(): AssignmentRoute {
		$finder = new TopicFinder( $this->topics );

		return new AssignmentRoute(
			$this->designations,
			$this->topics,
			new Assigner( $this->designations, $this->observations, $this->mentions, $this->topics ),
			new Neighbours( $this->designations, $this->observations, $this->topics, $finder ),
			$finder,
			static fn() => true,
			new Exclusion( $this->designations, $this->observations, $this->mentions, new NoneTopic( $this->topics ) )
		);
	}

	/**
	 * A request, for the routes.
	 *
	 * @param array<string, mixed> $params The parameters.
	 * @return object
	 */
	private function ask( array $params ) {
		return new class( $params ) {
			/**
			 * The parameters.
			 *
			 * @var array
			 */
			private $params;

			/**
			 * Builds the request.
			 *
			 * @param array $params The parameters.
			 */
			public function __construct( array $params ) {
				$this->params = $params;
			}

			/**
			 * A parameter.
			 *
			 * @param string $name Name.
			 * @return mixed
			 */
			public function get_param( $name ) {
				return $this->params[ $name ] ?? null;
			}
		};
	}

	/**
	 * A filter by column (slice 637): the topic and the motif (a text, accents and case ignored), the status of the topic (also "no topic"), the source (a source
	 * stands for the sources below it), the number of posts (none, or at least N), who assigned it, the state; they combine with each other, with the view and with the
	 * search; a filter that is empty filters nothing.
	 *
	 * @return void
	 */
	public function test_the_filters_of_the_columns(): void {
		$france = $this->topics->create( 'France', 'country', 0, Topics::APPROVED, array() );
		$tag    = $this->topics->create( 'Allemagne', 'any', 0, Topics::AMBIGUOUS, array() );
		$bird   = $this->topics->create( 'Mésange bleue', 'species', 0, Topics::APPROVED, array() );
		$none   = ( new NoneTopic( $this->topics ) )->id( true );
		$a      = $this->designation( 'Moulins (France)', array( 1, 2, 3 ), $france, Observations::SYSTEM, Designations::ASSIGNED, 'post/link' );
		$b      = $this->designation( 'allemagne', array( 1 ), $tag, Observations::SYSTEM, Designations::ASSIGNED, 'post/tag' );
		$c      = $this->designation( 'mésange', array( 2, 3 ), $bird, Observations::HAND, Designations::ASSIGNED, 'post/mark/whobird' );
		$d      = $this->designation( 'Sans topic', array(), 0, Observations::SYSTEM, Designations::PENDING, 'post/mark' );
		$e      = $this->designation( 'Exclu', array( 1 ), $none, Observations::SYSTEM, Designations::ASSIGNED, 'post/tag' );
		$list   = new Listing( $this->designations, $this->observations, $this->topics );
		$ids    = static fn( array $args ) => array_column( $list->query( $args )['rows'], 'id' );
		$sorted = static function ( array $found ) {
			sort( $found );

			return $found;
		};

		$this->assertSame( array( $a ), $ids( array( 'filters' => array( 'topic' => 'FRANCE' ) ) ), 'The topic: a text.' );
		$this->assertSame( array( $c ), $ids( array( 'filters' => array( 'motif' => 'MESANGE' ) ) ), 'The motif: a text, accents ignored.' );
		$this->assertSame( array( $b ), $ids( array( 'filters' => array( 'status' => 'ambiguous' ) ) ), 'The status of the topic.' );
		$this->assertSame( array( $d ), $ids( array( 'filters' => array( 'status' => 'none' ) ) ), 'A motif with no topic.' );
		$this->assertSame(
			array( $e ),
			$ids(
				array(
					'filters' => array(
						'status' => 'refused',
						'topic' => 'none',
					),
				)
			),
			'A motif assigned to _None_: the topic is a topic like the others.'
		);
		$this->assertSame( $sorted( array( $b, $e ) ), $sorted( $ids( array( 'filters' => array( 'source' => 'post/tag' ) ) ) ) );
		$this->assertSame( array( $c ), $ids( array( 'filters' => array( 'source' => 'post/mark/whobird' ) ) ) );
		$this->assertSame( $sorted( array( $c, $d ) ), $sorted( $ids( array( 'filters' => array( 'source' => 'post/mark' ) ) ) ), 'A source stands for the sources below it.' );
		$this->assertSame( array( $c ), $ids( array( 'filters' => array( 'by' => 'hand' ) ) ) );
		$this->assertSame( array( $a ), $ids( array( 'filters' => array( 'posts' => '3' ) ) ), 'At least 3 posts.' );
		$this->assertSame( $sorted( array( $a, $c ) ), $sorted( $ids( array( 'filters' => array( 'posts' => '2' ) ) ) ) );
		$this->assertSame( array( $d ), $ids( array( 'filters' => array( 'posts' => 'none' ) ) ), 'No post.' );
		$this->assertSame( array( $d ), $ids( array( 'filters' => array( 'state' => Designations::PENDING ) ) ) );

		$this->assertSame(
			array( $b ),
			$ids(
				array(
					'filters' => array(
						'source' => 'post/tag',
						'status' => 'ambiguous',
					),
				)
			),
			'The filters combine.'
		);
		$this->assertSame(
			array(),
			$ids(
				array(
					'filters' => array(
						'source' => 'post/tag',
						'by' => 'hand',
					),
				)
			)
		);
		$this->assertSame(
			array( $e ),
			$ids(
				array(
					'view' => Listing::EXCLUDED,
					'filters' => array( 'source' => 'post/tag' ),
				)
			),
			'With the view.'
		);
		$this->assertSame(
			array( $b ),
			$ids(
				array(
					'search' => 'allemagne',
					'filters' => array( 'source' => 'post/tag' ),
				)
			),
			'With the search.'
		);
		$this->assertCount(
			5,
			$ids(
				array(
					'filters' => array(
						'topic' => '  ',
						'posts' => '',
						'bogus' => 'x',
					),
				)
			),
			'Empty or unknown: nothing is filtered.'
		);

		$sorted_by_status = $list->query( array( 'orderby' => 'status' ) )['rows'];

		$this->assertSame( '', $sorted_by_status[0]['topic_status'], 'A sort by the status of the topic.' );
		$this->assertSame( 5, $list->query( array( 'filters' => array( 'posts' => 'none' ) ) )['counts'][ Listing::ALL ], 'The counts of the views are not filtered.' );
	}

	/**
	 * The table (slice 637): the topic is the first column and its status the second, the motif stays the main one; the status of the topic is said; the bar of
	 * the filters has one field per column and keeps what was asked; the links of the views keep the search and the filters; a link resets the filters.
	 *
	 * @return void
	 */
	public function test_the_bar_of_the_filters_and_the_columns(): void {
		$tag = $this->topics->create( 'Allemagne', 'any', 0, Topics::AMBIGUOUS, array() );
		$id  = $this->designation( 'allemagne', array( 1 ), $tag, Observations::SYSTEM, Designations::ASSIGNED, 'post/tag' );

		$this->request[ INPUT_GET ] = array(
			's'        => 'allem',
			'f_source' => 'post/tag',
			'f_status' => 'ambiguous',
			'f_topic'  => 'Alle',
			'f_posts'  => '1',
			'view'     => 'system',
		);

		$table = $this->make_table();

		$table->prepare_items();

		$this->assertSame( array( $id ), array_column( $table->items, 'id' ) );
		$this->assertSame( 'designation', $table->_column_headers[3], 'The motif stays the main column.' );
		$this->assertSame( 'ambiguous', $this->call( $table, 'column_status', array_column( $table->items, null, 'id' )[ $id ] ) );
		$this->assertSame( '—', $this->call( $table, 'column_status', array( 'topic_status' => '' ) ) );

		ob_start();
		$table->filter_bar();
		$bar = (string) ob_get_clean();

		foreach ( array( 'f_topic', 'f_status', 'f_motif', 'f_source', 'f_posts', 'f_by', 'f_state' ) as $field ) {
			$this->assertStringContainsString( 'name="' . $field . '"', $bar );
		}

		$this->assertStringContainsString( 'name="f_topic" value="Alle"', $bar );
		$this->assertSame( 2, substr_count( $bar, 'style="float:left' ), 'The text fields float like the lists of WordPress: the fields stay in the order of the columns.' );
		$this->assertStringContainsString( '<option value="ambiguous" selected="selected">', $bar );
		$this->assertStringContainsString( '<option value="post/tag" selected="selected">', $bar );
		$this->assertStringContainsString( '<option value="1" selected="selected">At least 1 post', $bar );
		$this->assertStringContainsString( 'Reset the filters', $bar );
		$this->assertStringNotContainsString( 'f_topic=', (string) preg_replace( '/^.*Reset the filters/s', '', $bar ), 'Nothing after the link.' );

		$views = $this->call( $table, 'get_views' );

		$this->assertStringContainsString( 'f_source=post%2Ftag', $views['resolve'] );
		$this->assertStringContainsString( 'f_status=ambiguous', $views['resolve'] );
		$this->assertStringContainsString( 's=allem', $views['resolve'], 'A view keeps the search and the filters.' );

		$this->request[ INPUT_GET ] = array();

		$table = $this->make_table();

		ob_start();
		$table->filter_bar();

		$this->assertStringNotContainsString( 'Reset the filters', (string) ob_get_clean(), 'Nothing to reset.' );

		$urls = array();

		$this->request[ INPUT_POST ] = array(
			'_wpnonce'    => 'good',
			'action'      => 'exclude',
			'designation' => array( (string) $id ),
			'f_source'    => 'post/tag',
			'f_status'    => 'ambiguous',
		);
		$this->screen( $urls )->process();

		$this->assertStringContainsString( 'f_source=post%2Ftag', end( $urls ), 'An action keeps the filters.' );
		$this->assertStringContainsString( 'f_status=ambiguous', end( $urls ) );
	}

	/**
	 * The topics that no motif is assigned to are lines of the table (slice 638): the broader levels of a place, here *Aragon* above *Ansó*. They are in *All* (and in
	 * its count) and in their own view, in no other view; the filters of the topic and of its status reach them, the filters of the columns of the motifs leave them
	 * out; a line of a topic has the number of the topic, negative, and says so in its columns; the action *Approve the topic* works on it and on the topic of a
	 * motif that is ticked.
	 *
	 * @return void
	 */
	public function test_the_topics_with_no_motif_are_lines_of_the_table(): void {
		$spain  = $this->topics->create( 'Espagne', 'country', 0, Topics::APPROVED, array() );
		$aragon = $this->topics->create( 'Aragon', 'admin1', $spain, Topics::APPROVED, array( 'wikidata:Q4040' ) );
		$anso   = $this->topics->create( 'Ansó', 'commune', $aragon, Topics::APPROVED, array() );
		$tag    = $this->topics->create( 'aragon', 'any', 0, Topics::AMBIGUOUS, array() );
		$m1     = $this->designation( 'Ansó', array( 1 ), $anso, Observations::SYSTEM, Designations::ASSIGNED, 'image/address' );
		$m2     = $this->designation( 'aragon', array( 1 ), $tag, Observations::SYSTEM, Designations::ASSIGNED, 'post/tag' );
		$list   = new Listing( $this->designations, $this->observations, $this->topics );
		$all    = $list->query( array() );

		$this->assertSame( 4, $all['counts'][ Listing::ALL ], '2 motifs and 2 topics with no motif (Espagne and Aragon).' );
		$this->assertSame( 2, $all['counts'][ Listing::TOPICS ], 'Espagne and Aragon: no motif is assigned to them.' );
		$this->assertSame( array( -$aragon, -$spain ), array_values( array_intersect( array_column( $all['rows'], 'id' ), array( -$spain, -$aragon ) ) ), 'In the table, by the label of the topic.' );

		$only = $list->query( array( 'view' => Listing::TOPICS ) );

		$this->assertSame( array( 'Aragon', 'Espagne' ), array_column( $only['rows'], 'topic_label' ), 'Their own view, by the label of the topic.' );
		$this->assertSame( array( 'topic', 'topic' ), array_column( $only['rows'], 'type' ) );
		$this->assertSame( array( 0, 0 ), array_column( $only['rows'], 'posts' ) );
		$this->assertSame( 'Q4040', $only['rows'][0]['wikidata'] );
		$this->assertNotContains( -$aragon, array_column( $list->query( array( 'view' => Listing::SYSTEM ) )['rows'], 'id' ), 'In no other view.' );

		$this->assertSame(
			array( -$aragon ),
			array_column(
				$list->query(
					array(
						'filters' => array(
							'topic' => 'ARAGON',
							'status' => 'approved',
						),
					)
				)['rows'],
				'id'
			),
			'The filters of the topic and of its status reach a topic with no motif.'
		);
		$this->assertSame(
			array( $m2 ),
			array_column(
				$list->query(
					array(
						'filters' => array(
							'topic' => 'aragon',
							'status' => 'ambiguous',
						),
					)
				)['rows'],
				'id'
			)
		);
		$this->assertSame(
			array( $m1 ),
			array_column(
				$list->query(
					array(
						'filters' => array(
							'topic' => 'anso',
							'source' => 'image',
						),
					)
				)['rows'],
				'id'
			),
			'The filters of the motifs leave the topics out.'
		);
		$this->assertSame( array( -$aragon ), array_column( $list->query( array( 'search' => 'Q4040' ) )['rows'], 'id' ), 'The search finds the identifier of a topic.' );

		$this->request[ INPUT_GET ] = array( 'view' => 'topics' );
		$table                      = $this->make_table();

		$table->prepare_items();

		$line = array_column( $table->items, null, 'id' )[ -$aragon ];

		$this->assertStringContainsString( 'name="topic[]" value="' . $aragon . '"', $this->call( $table, 'column_cb', $line ) );
		$this->assertStringContainsString( 'No motif', $this->call( $table, 'column_designation', $line ) );
		$this->assertStringContainsString( 'topic=' . $aragon, $this->call( $table, 'column_designation', $line ), 'The way to the topic.' );
		$this->assertStringContainsString( 'Aragon', $this->call( $table, 'column_topic', $line ) );
		$this->assertStringContainsString( '>Aragon (Espagne)</a> <small>First-level division</small>', $this->call( $table, 'column_topic', $line ), 'The label of the index, then the kind (slice 639).' );
		$this->assertSame( 'approved', $this->call( $table, 'column_status', $line ) );

		foreach ( array( 'column_source', 'column_posts', 'column_by', 'column_state' ) as $column ) {
			$this->assertSame( '—', $this->call( $table, $column, $line ) );
		}

		$candidate = $this->topics->create( 'Voyages', 'any', 0, Topics::AMBIGUOUS, array() );
		$urls      = array();

		$this->bulk(
			'approve',
			array(
				'topic' => array( (string) $candidate ),
				'designation' => array( (string) $m2 ),
			)
		);
		$this->screen( $urls )->process();

		$this->assertStringContainsString( 'did=approve', end( $urls ) );
		$this->assertStringContainsString( 'count=2', end( $urls ), 'The topic of a line with no motif and the topic of a motif.' );
		$this->assertSame( Topics::APPROVED, $this->topics->get( $candidate )['status'] );
		$this->assertSame( Topics::APPROVED, $this->topics->get( $tag )['status'] );

		$this->bulk( 'approve', array() );
		$this->screen( $urls )->process();

		$this->assertStringContainsString( 'error=', end( $urls ), 'Nothing ticked.' );
	}

	/**
	 * A topic is shown as the index prints it (slice 639): the label, the broader topic in parentheses (the nearest one that is not of a skipped kind), then the kind, in
	 * the table, in the lists of the quick edit, in the search of the topics and in the answer of the route; the confirmation names the topic the same way.
	 *
	 * @return void
	 */
	public function test_a_topic_is_shown_as_the_index_prints_it(): void {
		$spain  = $this->topics->create( 'Espagne', 'country', 0, Topics::APPROVED, array() );
		$aragon = $this->topics->create( 'Aragon', 'admin1', $spain, Topics::APPROVED, array() );
		$huesca = $this->topics->create( 'Huesca', 'admin2', $aragon, Topics::APPROVED, array() );
		$third  = $this->topics->create( 'Sobrarbe', 'admin3', $huesca, Topics::APPROVED, array() );
		$anso   = $this->topics->create( 'Ansó', 'commune', $third, Topics::APPROVED, array() );
		$lone   = $this->topics->create( 'Aragon (communauté autonome)', 'place', 0, Topics::APPROVED, array() );
		$labels = new TopicLabel( $this->topics );

		$this->assertSame( 'Espagne', $labels->of( $this->topics->get( $spain ) ), 'No broader topic: the label alone.' );
		$this->assertSame( 'Aragon (Espagne)', $labels->of( $this->topics->get( $aragon ) ) );
		$this->assertSame( 'Ansó (Huesca)', $labels->of( $this->topics->get( $anso ) ), 'A third division is skipped, as in the index.' );
		$this->assertSame( 'Aragon (communauté autonome)', $labels->of( $this->topics->get( $lone ) ), 'The label has its own parentheses.' );

		$found = ( new TopicFinder( $this->topics ) )->search( 'aragon' );

		$this->assertSame( array( 'Aragon (Espagne)', 'Aragon (communauté autonome)' ), array_column( $found, 'display' ) );
		$this->assertSame( 'Aragon', $found[0]['label'], 'The label stays, for the ones that need it.' );

		$id    = $this->designation( 'aragon', array( 1 ), $aragon, Observations::SYSTEM, Designations::ASSIGNED, 'post/tag' );
		$table = $this->make_table();

		$table->prepare_items();

		$html = $this->call( $table, 'column_topic', array_column( $table->items, null, 'id' )[ $id ] );

		$this->assertStringContainsString( '>Aragon (Espagne)</a> <small>First-level division</small>', $html );
		$this->assertSame(
			'Aragon (Espagne)',
			$this->route()->assign(
				$this->ask(
					array(
						'id' => $id,
						'topic' => $aragon,
					)
				)
			)['topic']['display']
		);
	}
}
