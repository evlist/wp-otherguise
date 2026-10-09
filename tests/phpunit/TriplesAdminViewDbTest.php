<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the data behind the administration screen, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Admin\OrphanScanner;
use Otherguise\Triples\Admin\RegisteredView;
use Otherguise\Triples\Admin\StatementsFilters;
use Otherguise\Triples\Admin\StatementsView;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Module;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\StatementQuery;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';

/**
 * The filters, the pages, the rows, the orphan scan and the registered tab.
 *
 * @covers \Otherguise\Triples\Admin\OrphanScanner
 * @covers \Otherguise\Triples\Admin\RegisteredView
 * @covers \Otherguise\Triples\Admin\StatementsFilters
 * @covers \Otherguise\Triples\Admin\StatementsView
 * @covers \Otherguise\Triples\Storage\StatementStore
 */
class TriplesAdminViewDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Module.
	 *
	 * @var Module
	 */
	private $module;

	/**
	 * Service.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Scanner.
	 *
	 * @var OrphanScanner
	 */
	private $scanner;

	/**
	 * View.
	 *
	 * @var StatementsView
	 */
	private $view;

	/**
	 * Builds the module, the view and a few statements.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->module     = Otherguise_Test_Fixtures::module( $this->wpdb );
		$this->statements = $this->module->statements();
		$this->scanner    = new OrphanScanner( $this->module->store(), $this->module->entity_types() );
		$this->view       = new StatementsView( $this->statements, $this->module->store(), $this->module->predicates(), $this->scanner );
	}

	/**
	 * Filters from a query string.
	 *
	 * @param array $request  Query string.
	 * @param int   $per_page Page size.
	 * @return StatementsFilters
	 */
	private function filters( array $request = array(), $per_page = 20 ) {
		return StatementsFilters::from_request( $request, $per_page );
	}

	/**
	 * Returns the ids of the rows of a page.
	 *
	 * @param array $page Page.
	 * @return int[]
	 */
	private function ids( array $page ) {
		return array_map( static fn( $row ) => $row['id'], $page['rows'] );
	}

	/**
	 * Invalid values fall back to the defaults, and the filters come back as query arguments.
	 *
	 * @return void
	 */
	public function test_the_filters_are_checked(): void {
		$filters = $this->filters(
			array(
				'predicate'       => 'Not A Slug',
				'entity_type'     => 'Bad Type',
				'entity'          => 'nonsense',
				'orderby'         => 'predicate',
				'order'           => 'sideways',
				'paged'           => '-4',
				'after'           => 'x',
				'with_qualifiers' => '',
			),
			9999
		);

		$this->assertNull( $filters->predicate );
		$this->assertNull( $filters->type );
		$this->assertNull( $filters->entity );
		$this->assertSame( 'id', $filters->orderby );
		$this->assertFalse( $filters->descending );
		$this->assertSame( 1, $filters->page );
		$this->assertSame( 0, $filters->after );
		$this->assertSame( 200, $filters->per_page );
		$this->assertSame( array(), $filters->to_args() );

		$good = $this->filters(
			array(
				'predicate'       => 'modes/mode',
				'entity_type'     => 'post',
				'entity'          => 'post:12',
				'orderby'         => 'created_gmt',
				'order'           => 'DESC',
				'with_qualifiers' => '1',
				'orphans'         => '1',
			)
		);

		$this->assertSame(
			array(
				'predicate'       => 'modes/mode',
				'entity_type'     => 'post',
				'entity'          => 'post:12',
				'with_qualifiers' => '1',
				'orphans'         => '1',
				'orderby'         => 'created_gmt',
				'order'           => 'desc',
			),
			$good->to_args()
		);
		$this->assertSame( StatementsFilters::UNREGISTERED, $this->filters( array( 'predicate' => '!unregistered' ) )->predicate );
		$this->assertNull( $this->filters( array( 'predicate' => array( 'x' ) ) )->predicate );
	}

	/**
	 * The page: statements about statements are hidden by default, the total follows the filters, the pages are cut.
	 *
	 * @return void
	 */
	public function test_pages_and_filters(): void {
		$a = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$b = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 90 ) );
		$c = $this->statements->triple( get_post( 13 ), 'books/contains', get_post( 12 ) );
		$m = $this->statements->triple( $a, 'modes/mode', new EntityRef( 'mode', 'print' ) );

		$all = $this->view->page( $this->filters() );

		$this->assertSame( array( $a->id(), $b->id(), $c->id() ), $this->ids( $all ) );
		$this->assertSame( 3, $all['total'] );
		$this->assertNull( $all['next_after'] );

		$this->assertSame( array( $a->id(), $b->id(), $c->id(), $m->id() ), $this->ids( $this->view->page( $this->filters( array( 'with_qualifiers' => '1' ) ) ) ) );
		$this->assertSame( array( $c->id(), $b->id(), $a->id() ), $this->ids( $this->view->page( $this->filters( array( 'order' => 'desc' ) ) ) ) );
		$this->assertSame( array( $a->id(), $b->id() ), $this->ids( $this->view->page( $this->filters( array( 'predicate' => 'media/illustrated-by' ) ) ) ) );
		$this->assertSame( array( $a->id() ), $this->ids( $this->view->page( $this->filters( array( 'entity' => 'attachment:88' ) ) ) ) );
		$this->assertSame( array( $a->id(), $b->id(), $c->id() ), $this->ids( $this->view->page( $this->filters( array( 'entity' => 'post:12' ) ) ) ) );
		$this->assertSame(
			array( $c->id() ),
			$this->ids(
				$this->view->page(
					$this->filters(
						array(
							'entity_type' => 'post',
							'predicate' => 'books/contains',
						)
					)
				)
			)
		);

		$second = $this->view->page( $this->filters( array( 'paged' => '2' ), 2 ) );

		$this->assertSame( array( $c->id() ), $this->ids( $second ) );
		$this->assertSame( 3, $second['total'] );
	}

	/**
	 * The rows carry what the screen needs: labels, links, missing entities, literals, the statements about each statement.
	 *
	 * @return void
	 */
	public function test_rows(): void {
		otherguise_test_wp_objects( array( new WP_Post( 12, 'post', 'Col du Tourmalet' ), new WP_Post( 88, 'attachment', 'photo.jpg' ) ) );

		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) );
		$this->statements->triple( $link, 'triples/position', 3 );
		$this->statements->triple( get_post( 12 ), 'test/tag', 'GR10' );

		otherguise_test_wp_objects( array( new WP_Post( 12, 'post', 'Col du Tourmalet' ) ) );

		$rows = $this->view->page( $this->filters() )['rows'];

		$this->assertSame( 'Col du Tourmalet', $rows[0]['subject']['label'] );
		$this->assertSame( 'post.php?post=12&action=edit', $rows[0]['subject']['url'] );
		$this->assertFalse( $rows[0]['subject']['missing'] );
		$this->assertTrue( $rows[0]['object']['missing'], 'The media item no longer exists.' );
		$this->assertSame( 'attachment:88', $rows[0]['object']['raw'] );
		$this->assertSame( 'Illustrated by', $rows[0]['predicate']['label'] );
		$this->assertSame( array( 'modes/mode', 'triples/position' ), array_map( static fn( $about ) => $about['predicate']['slug'], $rows[0]['about'] ) );
		$this->assertSame( 'mode:print', $rows[0]['about'][0]['object']['label'] );
		$this->assertTrue( $rows[1]['object']['literal'] );
		$this->assertSame( 'string', $rows[1]['object']['datatype'] );
		$this->assertSame( 'GR10', $rows[1]['object']['label'] );
		$this->assertArrayHasKey( 'created', $rows[0] );
	}

	/**
	 * The scan goes through the table in batches, finds the missing ends, and handles holes in the ids.
	 *
	 * @return void
	 */
	public function test_orphan_scan_in_batches(): void {
		$ok     = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$gone   = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 90 ) );
		$mode   = $this->statements->triple( $gone, 'modes/mode', new EntityRef( 'mode', 'web' ) );
		$other  = $this->statements->triple( get_post( 13 ), 'media/illustrated-by', get_post( 91 ) );
		$nocheck = $this->statements->triple( get_post( 13 ), 'test/anything', new EntityRef( 'mode', 'print' ) );

		$this->statements->delete( $nocheck );
		$this->module->store()->insert( new Statement( new EntityRef( 'ghost', '1' ), 'test/anything', Ref::post( 12 ) ) );
		$about_gone = $this->module->store()->insert( new Statement( new EntityRef( 'statement', '9999' ), 'modes/mode', new EntityRef( 'mode', 'web' ) ) );

		otherguise_test_wp_objects( array( new WP_Post( 12 ), new WP_Post( 13 ), new WP_Post( 88, 'attachment' ), new WP_Post( 91, 'attachment' ) ) );

		$first = $this->scanner->scan( 0, null, 2 );

		$this->assertSame( array( $gone->id() ), array_map( static fn( $s ) => $s->id(), $first['orphans'] ) );
		$this->assertSame( $gone->id(), $first['last_id'] );
		$this->assertFalse( $first['done'] );

		$rest = $this->scanner->scan( $first['last_id'], null, 100 );

		$this->assertSame( array( $about_gone ), array_map( static fn( $s ) => $s->id(), $rest['orphans'] ), 'A statement about a statement that is gone is an orphan; a type that cannot check is never reported.' );
		$this->assertNotContains( $mode->id(), array_map( static fn( $s ) => $s->id(), $rest['orphans'] ) );
		$this->assertTrue( $rest['done'] );
		$this->assertNotContains( $ok->id(), array_map( static fn( $s ) => $s->id(), $rest['orphans'] ) );
		$this->assertNotContains( $other->id(), array_map( static fn( $s ) => $s->id(), $rest['orphans'] ) );

		$empty = $this->scanner->scan( 9999 );

		$this->assertSame( array(), $empty['orphans'] );
		$this->assertSame( 9999, $empty['last_id'] );
		$this->assertTrue( $empty['done'] );
	}
}
