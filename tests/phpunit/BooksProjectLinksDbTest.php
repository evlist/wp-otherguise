<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the relations of the book projects, with the real statements of the Triples module (slice 230).
 *
 * @package Otherguise
 */

use Otherguise\Books\Module as BooksModule;
use Otherguise\Books\Project\ProjectLinks;
use Otherguise\Books\Project\Projects;
use Otherguise\Books\Section\Sections;
use Otherguise\Triples\Entity\Ref;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-section-posts.php';

/**
 * A book (100) holds a part (101) that holds days 1 and 2; the day 3 is only in the book; the projects 300 and 301 are on the book and the
 * part, 302 is on the day 1; 400 and 401 are printer profiles.
 *
 * @covers \Otherguise\Books\Project\ProjectLinks
 * @covers \Otherguise\Books\Project\Projects
 * @covers \Otherguise\Books\Section\Sections
 * @covers \Otherguise\Books\Module
 */
class BooksProjectLinksDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The statements.
	 *
	 * @var \Otherguise\Triples\Statements
	 */
	private $statements;

	/**
	 * The relations.
	 *
	 * @var ProjectLinks
	 */
	private $links;

	/**
	 * The sections.
	 *
	 * @var Sections
	 */
	private $sections;

	/**
	 * Builds the site and the sections.
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

		foreach ( array( 1, 2, 3 ) as $id ) {
			$posts->add( $id, 'Day ' . $id, '2026-05-1' . $id . ' 10:00:00' );
		}

		foreach ( array( 300, 301, 302 ) as $id ) {
			$posts->add( $id, 'Project ' . $id, '2026-05-01 08:00:00', 'publish', 'page' );
		}

		foreach ( array( 400, 401 ) as $id ) {
			$posts->add( $id, 'Profile ' . $id, '2026-05-01 08:00:00', 'publish', 'page' );
		}

		$this->statements = $site->statements;
		$statements       = $this->statements;
		$this->sections   = new Sections(
			static function () use ( $statements ) {
				return $statements;
			},
			$posts
		);
		$this->links      = new ProjectLinks(
			static function () use ( $statements ) {
				return $statements;
			},
			fn( $id ) => $this->sections->ancestors( $id )
		);

		$this->sections->add( 100, array( 101, 3 ) );
		$this->sections->add( 101, array( 1, 2 ) );
	}

	/**
	 * The root and the profile are one relation each: setting replaces, 0 takes away.
	 *
	 * @return void
	 */
	public function test_the_relations_of_a_project(): void {
		$this->assertSame( 0, $this->links->root( 300 ) );
		$this->assertSame( 0, $this->links->profile( 300 ) );

		$this->assertTrue( $this->links->set_root( 300, 100 ) );
		$this->assertTrue( $this->links->set_profile( 300, 400 ) );
		$this->assertSame( 100, $this->links->root( 300 ) );
		$this->assertSame( 400, $this->links->profile( 300 ) );

		$this->assertTrue( $this->links->set_root( 300, 101 ) );
		$this->assertSame( 101, $this->links->root( 300 ), 'The root is replaced, not added.' );
		$this->assertCount( 1, $this->statements->objects_of( Ref::post( 300 ), 'books/root' ) );

		$this->assertTrue( $this->links->set_profile( 300, 0 ) );
		$this->assertSame( 0, $this->links->profile( 300 ), '0 takes the relation away.' );
		$this->assertTrue( $this->links->set_profile( 300, 0 ), 'And can be done again.' );
		$this->assertSame( 101, $this->links->root( 300 ), 'The root is not touched.' );
	}

	/**
	 * The projects of a section come from the index, and a post is covered by the projects of the sections above it.
	 *
	 * @return void
	 */
	public function test_the_projects_that_cover_a_post(): void {
		$this->links->set_root( 301, 101 );
		$this->links->set_root( 300, 100 );
		$this->links->set_root( 302, 1 );

		$this->assertSame( array( 300 ), $this->links->projects_of( 100 ) );
		$this->assertSame( array( 301 ), $this->links->projects_of( 101 ) );
		$this->assertSame( array( 300, 301, 302 ), $this->links->covering( 1 ), 'The day 1: its own project, the part and the book.' );
		$this->assertSame( array( 300, 301 ), $this->links->covering( 2 ), 'The day 2: the part and the book.' );
		$this->assertSame( array( 300 ), $this->links->covering( 3 ), 'The day 3 is only in the book.' );
		$this->assertSame( array( 300 ), $this->links->covering( 100 ), 'A section is covered by its own project.' );
		$this->assertSame( array( 300, 301 ), $this->links->covering( 101 ) );
		$this->assertSame( array(), $this->links->covering( 400 ), 'A post that is in no section.' );
	}

	/**
	 * The service answers with the real relations: the choice among several projects, and the first one by default.
	 *
	 * @return void
	 */
	public function test_the_choice_among_projects(): void {
		$this->links->set_root( 300, 100 );
		$this->links->set_profile( 300, 400 );
		$this->links->set_root( 301, 101 );
		$this->links->set_profile( 301, 401 );
		$this->links->set_root( 302, 101 );

		$projects = new Projects(
			static fn() => array(
				array(
					'id'    => 302,
					'title' => 'No profile',
					'meta'  => array(),
				),
				array(
					'id'    => 301,
					'title' => 'Part',
					'meta'  => array( 'folios' => '0' ),
				),
				array(
					'id'    => 300,
					'title' => 'Book',
					'meta'  => array( 'author' => 'Eric' ),
				),
			),
			static fn( $id ) => in_array( $id, array( 400, 401 ), true ) ? array( 'width_mm' => 400 === $id ? 148 : 210 ) : null,
			$this->links
		);

		$this->assertSame( array( 300, 301 ), array_keys( $projects->covering( 1 ) ), 'A project without a profile does not cover.' );
		$this->assertSame( 300, $projects->for_post( 1 )['project']['id'], 'The lowest id.' );
		$this->assertSame( 301, $projects->for_post( 1, 301 )['project']['id'], 'The one asked for.' );
		$this->assertSame( 300, $projects->for_post( 3, 301 )['project']['id'], 'The one asked for does not cover the day 3.' );
		$this->assertSame( 210.0, $projects->for_post( 2, 301 )['profile']['width_mm'] );
		$this->assertStringNotContainsString( 'counter(page)', $projects->for_post( 2, 301 )['css'], 'Its folios are off.' );
		$this->assertSame( 'Eric', $projects->get( 300 )['author'] );
		$this->assertSame( 100, $projects->get( 300 )['root'] );
		$this->assertSame( 401, $projects->get( 301 )['profile'] );
		$this->assertNull( $projects->for_post( 400 ) );
	}

	/**
	 * Deleting a post takes its relations away (the clean-up of the Triples module).
	 *
	 * @return void
	 */
	public function test_the_relations_follow_the_posts(): void {
		$this->links->set_root( 300, 100 );
		$this->links->set_profile( 300, 400 );
		$this->statements->forget( Ref::post( 300 ) );

		$this->assertSame( 0, $this->links->root( 300 ), 'The project is gone, its relations too.' );
		$this->assertSame( array(), $this->links->projects_of( 100 ) );

		$this->links->set_root( 301, 100 );
		$this->statements->forget( Ref::post( 100 ) );
		$this->assertSame( 0, $this->links->root( 301 ), 'The section is gone: the project has no root, not a dangling one.' );
	}
}
