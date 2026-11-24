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
use Otherguise\Index\Designation\Listing;
use Otherguise\Index\Designation\TopicFinder;
use Otherguise\Index\Designations;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Observations;
use Otherguise\Index\Rest\TopicsRoute;
use Otherguise\Index\Topics;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-designations.php';
require_once __DIR__ . '/support/class-otherguise-test-wp-list-table.php';

/**
 * The statements are real; the topics, the designations and the kinds are in memory; the request, the nonce and the redirection are doubles.
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
		$this->mentions     = new Mentions( $get, $this->observations, $this->designations );
		$this->request      = array();
	}

	/**
	 * A designation, observed in some posts, assigned to a topic by the system or by a person.
	 *
	 * @param string $label  What was designated.
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
		$this->assertSame( array( 'Allier', 'Lyon', 'Moulins', 'Oubliée' ), array_column( $all['rows'], 'label' ) );
		$this->assertSame( array( 3, 1, 1, 0 ), array_column( $all['rows'], 'posts' ), 'The number of posts is asked for the lines shown.' );
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
		$this->assertSame( array( 'Lyon', 'Allier', 'Moulins', 'Oubliée' ), array_column( $list->query( array( 'orderby' => 'topic' ) )['rows'], 'label' ), 'No topic first, then by the label of the topic.' );

		$page = $list->query(
			array(
				'per_page' => 3,
				'paged' => 2,
			)
		);

		$this->assertSame( array( 'Oubliée' ), array_column( $page['rows'], 'label' ) );
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
		$this->assertSame( array( 'cb', 'designation', 'source', 'posts', 'topic', 'by', 'state' ), array_keys( $table->get_columns() ) );
		$this->assertSame( array( 'label', false ), $table->get_sortable_columns()['designation'] );
		$this->assertSame( array( 'assign', 'confirm', 'unassign', 'delete' ), array_keys( $this->call( $table, 'get_bulk_actions' ) ) );

		$views = $this->call( $table, 'get_views' );

		$this->assertSame( array( 'all', 'resolve', 'system', 'hand', 'unobserved' ), array_keys( $views ) );
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
	 * makes the assignment a decision; taking it away puts the designation to resolve and the mentions go; deleting only takes what no post observes.
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

		$this->assertNotNull( $this->designations->get( $a ), 'A designation that a post observes is not deleted.' );
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

		$this->assertStringContainsString( '1 designation(s), 2 observation(s) in 2 post(s), will be assigned to &quot;Allier (rivière)&quot;', $page );
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

		$this->assertSame( array( 'prepare', 'views', 'search:assignment', 'display' ), $table->called );
		$this->assertSame( 2, substr_count( $page, '<form' ), 'The search is a form of its own; the bulk actions post to the screen itself.' );
		$this->assertStringContainsString( 'page=otherguise-index-assignments', $page );

		ob_start();
		$this->screen( $urls, false, true, $table )->render();

		$this->assertSame( '', (string) ob_get_clean(), 'A stranger sees nothing.' );
		$this->assertSame( AssignmentsScreen::PAGE, $this->screen( $urls )->declare_screen( null )[0]['page'] );
	}
}
