<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the sections (slice 223) on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Books\Module as BooksModule;
use Otherguise\Books\Section\SectionException;
use Otherguise\Books\Section\Sections;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-section-posts.php';

/**
 * The statements are real (MariaDB), the posts are a double.
 *
 * @covers \Otherguise\Books\Section\Sections
 * @covers \Otherguise\Books\Module::register_predicates
 */
class BooksSectionDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The posts.
	 *
	 * @var Otherguise_Test_Section_Posts
	 */
	private $posts;

	/**
	 * The service.
	 *
	 * @var Sections
	 */
	private $sections;

	/**
	 * The statements.
	 *
	 * @var \Otherguise\Triples\Statements
	 */
	private $statements;

	/**
	 * Builds a site with a section (100, a page), a draft section (101) and five days.
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

		$this->statements = $site->statements;
		$this->posts      = new Otherguise_Test_Section_Posts();
		$this->posts->add( 100, 'Pyrenees', '2026-05-01 08:00:00', 'draft', 'page' );
		$this->posts->add( 101, 'Alps', '2026-06-01 08:00:00', 'publish', 'page' );
		$this->posts->add( 1, 'Day 1', '2026-05-16 10:00:00' );
		$this->posts->add( 2, 'Day 2', '2026-05-17 10:00:00' );
		$this->posts->add( 3, 'Day 3', '2026-05-18 10:00:00' );
		$this->posts->add( 4, 'Day 4', '2026-05-19 10:00:00', 'draft' );
		$this->posts->add( 5, 'Day 5', '2026-05-20 10:00:00' );
		$this->posts->add( 200, 'A photo', '2026-05-16 10:00:00', 'inherit', 'attachment' );

		$statements     = $this->statements;
		$this->sections = new Sections(
			static function () use ( $statements ) {
				return $statements;
			},
			$this->posts
		);
	}

	/**
	 * The code of the refusal of a call, or null.
	 *
	 * @param callable $call Call.
	 * @return string|null
	 */
	private function refusal( $call ) {
		try {
			$call();
		} catch ( SectionException $refusal ) {
			return $refusal->error_code();
		}

		return null;
	}

	/**
	 * Posts are added, appear at their date whatever the order of the additions, and the section is marked by the first addition.
	 *
	 * @return void
	 */
	public function test_adding_and_the_natural_order(): void {
		$this->assertFalse( $this->sections->is_section( 100 ) );

		$report = $this->sections->add( 100, array( 3, 1, '2', 4 ) );

		$this->assertSame( array( 3, 1, 2, 4 ), $report['added'] );
		$this->assertSame( array(), $report['already'] );
		$this->assertSame( array(), $report['skipped'] );
		$this->assertSame( array( 1, 2, 3, 4 ), $this->sections->order( 100 ), 'By date, oldest first.' );
		$this->assertTrue( $this->sections->is_section( 100 ), 'Marked by the first addition.' );

		$again = $this->sections->add( 100, array( 2, 5 ) );

		$this->assertSame( array( 5 ), $again['added'] );
		$this->assertSame( array( 2 ), $again['already'], 'Not an error, not added twice.' );
		$this->assertSame( array( 1, 2, 3, 4, 5 ), $this->sections->order( 100 ) );
	}

	/**
	 * A post with the same date is ordered by id.
	 *
	 * @return void
	 */
	public function test_ties_are_ordered_by_id(): void {
		$this->posts->add( 6, 'Same day, later id', '2026-05-16 10:00:00' );

		$this->sections->add( 100, array( 6, 1 ) );

		$this->assertSame( array( 1, 6 ), $this->sections->order( 100 ) );
	}

	/**
	 * What is skipped, and why.
	 *
	 * @return void
	 */
	public function test_skipped_posts(): void {
		$this->posts->hidden = array( 3 );
		$this->sections->mark( 101 );

		$report = $this->sections->add( 100, array( 999, 200, 3, 100, 101, 1, 0, -4, 'x', 1.5 ) );

		$this->assertSame( array( 101, 1 ), $report['added'], 'Another section is a post like any other.' );
		$this->assertSame(
			array(
				999 => 'missing',
				200 => 'type',
				3   => 'forbidden',
				100 => 'cycle',
			),
			$report['skipped']
		);
	}

	/**
	 * A section cannot contain itself or, however deep, a section that contains it.
	 *
	 * @return void
	 */
	public function test_cycles(): void {
		$this->posts->add( 102, 'Chapter', '2026-05-02 08:00:00', 'draft', 'page' );
		$this->posts->add( 103, 'Sub-chapter', '2026-05-03 08:00:00', 'draft', 'page' );

		$this->assertSame( array( 102 ), $this->sections->add( 100, array( 102 ) )['added'] );
		$this->assertSame( array( 103 ), $this->sections->add( 102, array( 103 ) )['added'] );

		$this->assertSame( array( 100 => 'cycle' ), $this->sections->add( 103, array( 100 ) )['skipped'], 'Grandparent.' );
		$this->assertSame( array( 102 => 'cycle' ), $this->sections->add( 103, array( 102 ) )['skipped'], 'Parent.' );
		$this->assertSame( array( 103 => 'cycle' ), $this->sections->add( 103, array( 103 ) )['skipped'], 'Itself.' );
		$this->assertTrue( $this->sections->reaches( 100, 103 ) );
		$this->assertFalse( $this->sections->reaches( 103, 100 ) );

		$this->assertSame( array( 103 ), $this->sections->add( 100, array( 103 ) )['added'], 'A shortcut is not a cycle.' );
	}

	/**
	 * A post can be in several sections, found through the object index.
	 *
	 * @return void
	 */
	public function test_sections_of_a_post(): void {
		$this->sections->add( 100, array( 1, 2 ) );
		$this->sections->add( 101, array( 2 ) );

		$this->assertSame( array( 100, 101 ), $this->sections->containing( 2 ) );
		$this->assertSame( array( 100 ), $this->sections->containing( 1 ) );
		$this->assertSame( array(), $this->sections->containing( 5 ) );

		$state = $this->sections->state( 2 );

		$this->assertFalse( $state['section'] );
		$this->assertSame( array( 100, 101 ), array_column( $state['containing'], 'id' ) );
		$this->assertTrue( $state['containing'][0]['can_edit'] );

		$this->posts->hidden = array( 101 );

		$this->assertSame( array( 100 ), array_column( $this->sections->state( 2 )['containing'], 'id' ), 'A section the user cannot read is not shown.' );
	}

	/**
	 * The capabilities and the types.
	 *
	 * @return void
	 */
	public function test_the_refusals(): void {
		$this->assertSame( SectionException::NOT_FOUND, $this->refusal( fn() => $this->sections->add( 999, array( 1 ) ) ) );
		$this->assertSame( SectionException::BAD_TYPE, $this->refusal( fn() => $this->sections->add( 200, array( 1 ) ) ) );
		$this->assertSame( SectionException::BAD_TYPE, $this->refusal( fn() => $this->sections->mark( 200 ) ) );

		$this->posts->editable = array( 101 );

		$this->assertSame( SectionException::FORBIDDEN, $this->refusal( fn() => $this->sections->add( 100, array( 1 ) ) ) );
		$this->assertSame( SectionException::FORBIDDEN, $this->refusal( fn() => $this->sections->mark( 100 ) ) );
		$this->assertSame( SectionException::FORBIDDEN, $this->refusal( fn() => $this->sections->remove( 100, 1 ) ) );
		$this->assertSame( SectionException::FORBIDDEN, $this->refusal( fn() => $this->sections->move( 100, 1, 'down' ) ) );
		$this->assertSame( SectionException::FORBIDDEN, $this->refusal( fn() => $this->sections->reset_order( 100 ) ) );
		$this->assertSame( SectionException::FORBIDDEN, $this->refusal( fn() => $this->sections->candidates( 100, array() ) ) );
		$this->assertNull( $this->refusal( fn() => $this->sections->add( 101, array( 1 ) ) ) );

		$this->posts->editable = null;

		$ids = range( 1000, 1000 + Sections::MAX_BATCH );

		$this->assertSame( SectionException::TOO_MANY, $this->refusal( fn() => $this->sections->add( 100, $ids ) ) );
		$this->assertSame( array(), $this->sections->add( 100, array() )['added'] );
		$this->assertFalse( $this->sections->is_section( 100 ), 'Nothing added: not marked.' );
	}

	/**
	 * Marking, and unmarking only an empty section.
	 *
	 * @return void
	 */
	public function test_marking(): void {
		$this->sections->mark( 100 );
		$this->sections->mark( 100 );

		$this->assertTrue( $this->sections->is_section( 100 ) );
		$this->assertTrue( $this->sections->state( 100 )['section'] );

		$this->sections->add( 100, array( 1 ) );

		$this->assertSame( SectionException::NOT_EMPTY, $this->refusal( fn() => $this->sections->unmark( 100 ) ) );
		$this->assertTrue( $this->sections->is_section( 100 ) );

		$this->assertTrue( $this->sections->remove( 100, 1 ) );
		$this->assertFalse( $this->sections->remove( 100, 1 ), 'Run twice.' );
		$this->sections->unmark( 100 );
		$this->sections->unmark( 100 );

		$this->assertFalse( $this->sections->is_section( 100 ) );
	}

	/**
	 * Up and down exchange two posts, exactly, whatever the positions the other posts already have.
	 *
	 * @return void
	 */
	public function test_up_and_down(): void {
		$this->sections->add( 100, array( 1, 2, 3, 5 ) );

		$this->assertSame( array( 2, 1, 3, 5 ), $this->sections->move( 100, 1, 'down' ) );
		$this->assertSame( array( 2, 3, 1, 5 ), $this->sections->move( 100, 1, 'down' ) );
		$this->assertSame( array( 2, 1, 3, 5 ), $this->sections->move( 100, 1, 'up' ) );

		$pinned = array_map(
			static fn( $entry ) => $entry['pinned'],
			$this->sections->entries( 100 )
		);

		$this->assertSame( array( 1, 2, 3, null ), $pinned, 'Each exchange gives a position to the posts whose rank changes, and to no other.' );

		$this->assertSame( SectionException::BAD_MOVE, $this->refusal( fn() => $this->sections->move( 100, 2, 'up' ) ), 'First.' );
		$this->assertSame( SectionException::BAD_MOVE, $this->refusal( fn() => $this->sections->move( 100, 5, 'down' ) ), 'Last.' );
		$this->assertSame( SectionException::NOT_IN, $this->refusal( fn() => $this->sections->move( 100, 4, 'up' ) ) );
		$this->assertSame( SectionException::BAD_MOVE, $this->refusal( fn() => $this->sections->move( 100, 1, 'sideways' ) ) );
	}

	/**
	 * A rank pins only the post: the others keep their order around it.
	 *
	 * @return void
	 */
	public function test_a_rank(): void {
		$this->sections->add( 100, array( 1, 2, 3, 5 ) );

		$this->assertSame( array( 5, 1, 2, 3 ), $this->sections->move( 100, 5, 1 ) );
		$this->assertSame( array( 5, 1, 3, 2 ), $this->sections->move( 100, 2, '4' ) );

		$this->assertSame( SectionException::BAD_MOVE, $this->refusal( fn() => $this->sections->move( 100, 1, 0 ) ) );
		$this->assertSame( SectionException::BAD_MOVE, $this->refusal( fn() => $this->sections->move( 100, 1, 5 ) ) );
	}

	/**
	 * A move to a rank after an exchange: the posts that were pinned by the exchange do not keep the others out of their place (found
	 * on a real WordPress: the first version left the post that was displaced at the end).
	 *
	 * @return void
	 */
	public function test_a_rank_after_an_exchange(): void {
		$this->posts->add( 6, 'Day 6', '2026-05-21 10:00:00' );
		$this->sections->add( 100, array( 1, 2, 3, 5, 6 ) );

		$this->assertSame( array( 1, 3, 2, 5, 6 ), $this->sections->move( 100, 2, 'down' ) );
		$this->assertSame( array( 5, 1, 3, 2, 6 ), $this->sections->move( 100, 5, 1 ) );
		$this->assertSame( array( 5, 1, 3, 6, 2 ), $this->sections->move( 100, 2, 5 ) );
		$this->assertSame( array( 5, 1, 2, 3, 6 ), $this->sections->move( 100, 2, 3 ) );

		$this->sections->reset_order( 100 );

		$this->assertSame( array( 1, 2, 3, 5, 6 ), $this->sections->order( 100 ) );
	}

	/**
	 * Adding after a move leaves the moved posts where they are and the new one at its date.
	 *
	 * @return void
	 */
	public function test_adding_after_a_move_and_resetting(): void {
		$this->sections->add( 100, array( 1, 2, 3 ) );
		$this->sections->move( 100, 3, 1 );
		$this->sections->add( 100, array( 5 ) );

		$this->assertSame( array( 3, 1, 2, 5 ), $this->sections->order( 100 ) );

		$this->assertSame( 3, $this->sections->reset_order( 100 ), 'The three posts of the range had a position.' );
		$this->assertSame( array( 1, 2, 3, 5 ), $this->sections->order( 100 ) );
		$this->assertSame( 0, $this->sections->reset_order( 100 ), 'Run twice.' );
	}

	/**
	 * Taking a post out gives the remaining posts that had a position the rank they now have, so the order stays the old order without
	 * the post (an absolute rank beyond the end would otherwise put them in another order).
	 *
	 * @return void
	 */
	public function test_removal_repairs_the_positions(): void {
		$this->sections->add( 100, array( 1, 2, 3 ) );

		$this->assertSame( array( 3, 1, 2 ), $this->sections->move( 100, 3, 1 ) );
		$this->assertTrue( $this->sections->remove( 100, 3 ) );
		$this->assertSame( array( 1, 2 ), $this->sections->order( 100 ) );
		$this->assertSame( array( 1, 2 ), array_map( static fn( $entry ) => $entry['pinned'], $this->sections->entries( 100 ) ), 'Re-ranked.' );

		$this->sections->add( 100, array( 5 ) );
		$this->sections->move( 100, 5, 1 );

		$this->assertSame( array( 5, 1, 2 ), $this->sections->order( 100 ) );
		$this->assertTrue( $this->sections->remove( 100, 1 ) );
		$this->assertSame( array( 5, 2 ), $this->sections->order( 100 ), 'A post in the middle leaves: the others close up.' );
	}

	/**
	 * A post that is deleted leaves its sections without upsetting the order of the others, and its statements go.
	 *
	 * @return void
	 */
	public function test_deleting_a_post(): void {
		$this->posts->add( 6, 'Day 6', '2026-05-21 10:00:00' );
		$this->sections->add( 100, array( 1, 2, 3, 5, 6 ) );
		$this->sections->add( 101, array( 2 ) );
		$this->sections->move( 100, 6, 1 );

		$this->assertSame( array( 6, 1, 2, 3, 5 ), $this->sections->order( 100 ) );

		$this->sections->forget_post( 2 );
		$this->statements->forget( \Otherguise\Triples\Entity\Ref::post( 2 ) );

		$this->assertSame( array( 6, 1, 3, 5 ), $this->sections->order( 100 ) );
		$this->assertSame( array(), $this->sections->order( 101 ) );
		$this->assertSame( array(), $this->sections->containing( 2 ) );

		$this->sections->forget_post( 999 );
	}

	/**
	 * The search among the sections finds only marked posts the user may edit.
	 *
	 * @return void
	 */
	public function test_searching_the_sections(): void {
		$this->sections->add( 100, array( 1 ) );
		$this->sections->mark( 101 );

		$all = $this->sections->search( '' );

		$this->assertSame( array( 101, 100 ), array_column( $all['items'], 'id' ) );
		$this->assertSame( 2, $all['total'] );
		$this->assertSame( array( 100 ), array_column( $this->sections->search( 'pyr' )['items'], 'id' ) );
		$this->assertSame( array(), $this->sections->search( 'day' )['items'], 'Days are not sections.' );

		$this->posts->editable = array( 101 );

		$this->assertSame( array( 101 ), array_column( $this->sections->search( '' )['items'], 'id' ) );
	}

	/**
	 * The candidates: what is in the section already is flagged, the section itself is not offered.
	 *
	 * @return void
	 */
	public function test_candidates(): void {
		$this->sections->add( 100, array( 2 ) );

		$found = $this->sections->candidates( 100, array( 'statuses' => array( 'publish', 'draft', 'page' ) ) );
		$ids   = array_column( $found['items'], 'id' );

		$this->assertContains( 4, $ids, 'A draft, when the statuses ask for it.' );
		$this->assertNotContains( 100, $ids );
		$this->assertNotContains( 200, $ids, 'An attachment is not offered.' );

		$by_id = array_column( $found['items'], 'in_section', 'id' );

		$this->assertTrue( $by_id[2] );
		$this->assertFalse( $by_id[1] );

		$range = $this->sections->candidates(
			100,
			array(
				'after'  => '2026-05-17',
				'before' => '2026-05-18',
			)
		);

		$this->assertSame( array( 2, 3 ), array_column( $range['items'], 'id' ) );
		$this->assertSame( array( 3 ), array_column( $this->sections->candidates( 100, array( 'search' => 'day 3' ) )['items'], 'id' ) );
	}

	/**
	 * The predicates are registered: a post is marked once, and the relation relates two posts.
	 *
	 * @return void
	 */
	public function test_the_predicates_are_registered(): void {
		$post = \Otherguise\Triples\Entity\Ref::post( 100 );

		$mark = $this->statements->triple( $post, Sections::MARK, true );

		$this->assertSame( $mark->id(), $this->statements->triple( $post, Sections::MARK, 1 )->id(), 'Marked once.' );
		$this->assertTrue( $this->refused( fn() => $this->statements->triple( $post, Sections::MARK, 'maybe' ) ), 'Not a boolean.' );
		$this->assertTrue( $this->statements->check( $post, Sections::PREDICATE, \Otherguise\Triples\Entity\Ref::post( 1 ) ) );
	}

	/**
	 * Tells whether Triples refuses a call.
	 *
	 * @param callable $call Call.
	 * @return bool
	 */
	private function refused( $call ) {
		try {
			$call();
		} catch ( \Otherguise\Triples\Service\InvalidStatementException $problem ) {
			return true;
		}

		return false;
	}

	/**
	 * Builds a request double.
	 *
	 * @param array $params Parameters.
	 * @return object
	 */
	private function request( array $params ) {
		return new class( $params ) {
			/**
			 * Parameters.
			 *
			 * @var array
			 */
			private $params;

			/**
			 * Builds the request.
			 *
			 * @param array $params Parameters.
			 */
			public function __construct( array $params ) {
				$this->params = $params;
			}

			/**
			 * Reads a parameter.
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
	 * The routes: registered with their methods and permissions, and answering with the state, or with the refusal and its status.
	 *
	 * @return void
	 */
	public function test_the_routes(): void {
		$allowed = array( 100 );
		$routes  = new \Otherguise\Books\Rest\SectionRoutes( $this->sections, static fn( $id ) => in_array( $id, $allowed, true ), static fn() => true );

		$routes->register();

		$registered = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_rest_route' === $call[0] ) );
		$paths      = array_map( static fn( $call ) => $call[2], $registered );

		$this->assertSame(
			array(
				'/sections',
				'/sections/(?P<id>[0-9]+)',
				'/sections/(?P<id>[0-9]+)/contents',
				'/sections/(?P<id>[0-9]+)/contents/(?P<post>[0-9]+)',
				'/sections/(?P<id>[0-9]+)/contents/(?P<post>[0-9]+)/move',
				'/sections/(?P<id>[0-9]+)/order',
				'/sections/(?P<id>[0-9]+)/candidates',
			),
			$paths
		);
		$this->assertSame( 'books/v1', $registered[0][1] );
		$this->assertSame( array( $routes, 'can_write' ), $registered[0][3]['permission_callback'] );
		$this->assertSame( array( 'GET', 'PUT' ), array_column( $registered[1][3], 'methods' ) );
		$this->assertSame( 'POST', $registered[2][3]['methods'] );
		$this->assertSame( 'DELETE', $registered[3][3]['methods'] );
		$this->assertTrue( $registered[2][3]['args']['ids']['required'] );

		foreach ( array_slice( $registered, 1 ) as $call ) {
			$definitions = isset( $call[3]['methods'] ) ? array( $call[3] ) : $call[3];

			foreach ( $definitions as $definition ) {
				$this->assertSame( array( $routes, 'can' ), $definition['permission_callback'], $call[2] );
			}
		}

		$this->assertTrue( $routes->can( $this->request( array( 'id' => '100' ) ) ) );
		$this->assertFalse( $routes->can( $this->request( array( 'id' => '101' ) ) ) );
		$this->assertFalse( $routes->can( $this->request( array( 'id' => '0' ) ) ) );
		$this->assertTrue( $routes->can_write() );
		$this->assertFalse( ( new \Otherguise\Books\Rest\SectionRoutes( $this->sections, null, static fn() => false ) )->can_write() );

		$added = $routes->add(
			$this->request(
				array(
					'id'  => 100,
					'ids' => array( 2, 1, 100 ),
				)
			)
		);

		$this->assertSame( array( 2, 1 ), $added['report']['added'] );
		$this->assertSame( array( 100 => 'cycle' ), $added['report']['skipped'] );
		$this->assertTrue( $added['section'] );
		$this->assertSame( array( 1, 2 ), array_column( $added['contents'], 'id' ) );

		$moved = $routes->move(
			$this->request(
				array(
					'id'   => 100,
					'post' => 1,
					'to'   => 'down',
				)
			)
		);

		$this->assertSame( array( 2, 1 ), array_column( $moved['contents'], 'id' ) );
		$this->assertSame(
			array( 2, 1 ),
			array_column(
				$routes->move(
					$this->request(
						array(
							'id'   => 100,
							'post' => 1,
							'to'   => '2',
						)
					)
				)['contents'],
				'id'
			)
		);
		$this->assertSame( array( 1, 2 ), array_column( $routes->reset( $this->request( array( 'id' => 100 ) ) )['contents'], 'id' ) );

		$error = $routes->move(
			$this->request(
				array(
					'id'   => 100,
					'post' => 1,
					'to'   => 'up',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'books_section_bad_move', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );

		$this->assertTrue(
			$routes->remove(
				$this->request(
					array(
						'id'   => 100,
						'post' => 1,
					)
				)
			)['removed']
		);
		$this->assertSame( array( 2 ), array_column( $routes->state( $this->request( array( 'id' => 100 ) ) )['contents'], 'id' ) );

		$unmark = $routes->mark(
			$this->request(
				array(
					'id'      => 100,
					'section' => false,
				)
			)
		);

		$this->assertSame( 409, $unmark->get_error_data()['status'], 'Not empty.' );
		$this->assertSame( 'books_section_not_empty', $unmark->get_error_code() );

		$this->assertSame(
			array( 100 ),
			array_column(
				$routes->search(
					$this->request(
						array(
							'search' => '',
							'page'   => 1,
						)
					)
				)['items'],
				'id'
			)
		);

		$candidates = $routes->candidates(
			$this->request(
				array(
					'id'       => 100,
					'after'    => '2026-05-18',
					'before'   => 'nonsense',
					'statuses' => array( 'publish' ),
					'page'     => 1,
				)
			)
		);

		$this->assertSame( array( 3, 5, 101 ), array_column( $candidates['items'], 'id' ), 'A bad date is ignored; another section is a candidate too.' );
		$this->assertSame( '2026-05-17', $routes->sanitize_date( '2026-05-17' ) );
		$this->assertSame( '', $routes->sanitize_date( '17/05/2026' ) );
		$this->assertSame( '', $routes->sanitize_date( array( '2026-05-17' ) ) );
	}

	/**
	 * Runs a command and returns what it printed; a failure stops it with an exception, as `WP_CLI::error` stops the process.
	 *
	 * @param string $method Subcommand.
	 * @param array  $args   Arguments.
	 * @param array  $assoc  Named arguments.
	 * @return array{out:string[],error:?string}
	 */
	private function command( $method, array $args, array $assoc = array() ) {
		$out     = array();
		$command = new \Otherguise\Books\Cli\SectionCommand(
			$this->sections,
			static function ( $line ) use ( &$out ) {
				$out[] = $line;
			},
			static function ( $line ) use ( &$out ) {
				$out[] = 'Success: ' . $line;
			},
			static function ( $line ) {
				throw new RuntimeException( esc_html( $line ) );
			}
		);

		try {
			$command->$method( $args, $assoc );
		} catch ( RuntimeException $error ) {
			return array(
				'out'   => $out,
				'error' => $error->getMessage(),
			);
		}

		return array(
			'out'   => $out,
			'error' => null,
		);
	}

	/**
	 * The commands: adding by id and by selection, removing, showing and listing, each one twice, with a dry run.
	 *
	 * @return void
	 */
	public function test_the_commands(): void {
		$none = $this->command( 'add', array( '100' ) );

		$this->assertSame( 'Give ids, or --search, --from and --to.', $none['error'] );

		$dry = $this->command( 'add', array( '100', '1,2' ), array( 'dry-run' => true ) );

		$this->assertSame( array( 'Would add 2 post(s) to section 100.' ), $dry['out'] );
		$this->assertSame( array(), $this->sections->order( 100 ) );

		$first = $this->command( 'add', array( '100', '1,2', '999' ) );

		$this->assertSame( array( 'Skipped 999: missing.', 'Success: 2 added, 0 already there, 1 skipped.' ), $first['out'] );

		$again = $this->command( 'add', array( '100', '1', '2' ) );

		$this->assertSame( array( 'Success: 0 added, 2 already there, 0 skipped.' ), $again['out'], 'Run twice.' );

		$selected = $this->command(
			'add',
			array( '100' ),
			array(
				'from' => '2026-05-17',
				'to'   => '2026-05-20',
			)
		);

		$this->assertSame( array( 'Success: 2 added, 0 already there, 0 skipped.' ), $selected['out'], 'Days 3 and 5: day 2 is there, day 4 is a draft.' );
		$this->assertSame( array( 1, 2, 3, 5 ), $this->sections->order( 100 ) );

		$drafts = $this->command(
			'add',
			array( '100' ),
			array(
				'search' => 'day 4',
				'status' => 'draft,publish',
			)
		);

		$this->assertSame( array( 'Success: 1 added, 0 already there, 0 skipped.' ), $drafts['out'] );

		$shown = $this->command( 'show', array( '100' ) );

		$this->assertSame( "1\t1\t2026-05-16 10:00:00\tpublish\t\tDay 1", $shown['out'][0] );
		$this->assertCount( 5, $shown['out'] );
		$this->assertSame( 'This post does not exist.', $this->command( 'show', array( '999' ) )['error'] );
		$this->assertSame( 'The section is the id of a post.', $this->command( 'show', array( 'x' ) )['error'] );
		$this->assertSame( '--format: table or json.', $this->command( 'show', array( '100' ), array( 'format' => 'xml' ) )['error'] );
		$this->assertSame( 5, count( json_decode( $this->command( 'show', array( '100' ), array( 'format' => 'json' ) )['out'][0], true )['contents'] ) );

		$listed = $this->command( 'list', array() );

		$this->assertSame( array( "100\tPyrenees\tdraft\t5" ), $listed['out'] );
		$this->assertSame( '[{"id":100,"title":"Pyrenees","status":"draft","contains":5}]', $this->command( 'list', array(), array( 'format' => 'json' ) )['out'][0] );
		$this->assertSame( array(), $this->command( 'list', array(), array( 'search' => 'nothing' ) )['out'] );

		$dry_remove = $this->command( 'remove', array( '100', '1,2,999' ), array( 'dry-run' => true ) );

		$this->assertSame( array( 'Would remove 2 post(s) from section 100.' ), $dry_remove['out'] );

		$removed = $this->command( 'remove', array( '100', '1,2,999' ) );

		$this->assertSame( array( 'Success: 2 removed, 1 were not in the section.' ), $removed['out'] );
		$this->assertSame( array( 3, 4, 5 ), $this->sections->order( 100 ) );
		$this->assertSame( array( 'Success: 0 removed, 1 were not in the section.' ), $this->command( 'remove', array( '100', '1' ) )['out'], 'Run twice.' );
		$this->assertSame( 'Give the ids of the posts to remove.', $this->command( 'remove', array( '100' ) )['error'] );
		$this->assertSame( 'This post does not exist.', $this->command( 'add', array( '999', '1' ) )['error'] );
	}
}
