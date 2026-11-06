<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the names of the pages (slice 501) on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Service\InvalidStatementException;
use Otherguise\Wiki\Module as WikiModule;
use Otherguise\Wiki\NameException;
use Otherguise\Wiki\Names;
use Otherguise\Wiki\Space\Spaces;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';

/**
 * The statements are real (MariaDB), the posts are the stubs of WordPress.
 *
 * @covers \Otherguise\Wiki\Names
 * @covers \Otherguise\Wiki\Module::register_predicates
 */
class WikiNamesDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The service.
	 *
	 * @var Names
	 */
	private $names;

	/**
	 * The statements.
	 *
	 * @var \Otherguise\Triples\Statements
	 */
	private $statements;

	/**
	 * Builds a site with the predicates of the names, three spaces and ten pages.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$pages = array();

		for ( $id = 1; $id <= 10; $id++ ) {
			$pages[] = new WP_Post( $id );
		}

		otherguise_test_wp_objects( $pages );
		update_option(
			Spaces::OPTION,
			array(
				array( 'name' => 'Biography' ),
				array( 'name' => 'Encyclopédie' ),
				array( 'name' => 'Opinion' ),
			)
		);

		$wiki             = new WikiModule();
		$site             = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $wiki ) {
					$wiki->register_predicates( $registry );
				},
			)
		);
		$this->statements = $site->statements;
		$statements       = $this->statements;
		$this->names      = new Names(
			static function () use ( $statements ) {
				return $statements;
			}
		);
	}

	/**
	 * The code of the refusal of a call, or null.
	 *
	 * @param callable $call Call.
	 * @return NameException|null
	 */
	private function refusal( $call ) {
		try {
			$call();
		} catch ( NameException $refusal ) {
			return $refusal;
		}

		return null;
	}

	/**
	 * A free name is given: the name, the space and the folded name are kept, and the page is found by its name.
	 *
	 * @return void
	 */
	public function test_a_free_name_is_given(): void {
		$done = $this->names->claim( 1, 'biography', 'maison_de mes  grands-parents' );

		$this->assertSame( 'Biography:Maison de mes grands-parents', $done['name'], 'The space as registered, the title as a name.' );
		$this->assertNull( $done['former'] );
		$this->assertSame( 'Biography', $this->names->current_of( 1 )['space'] );
		$this->assertSame( 'Maison de mes grands-parents', $this->names->current_of( 1 )['title'] );
		$this->assertSame(
			array(
				'post' => 1,
				'current' => true,
			),
			$this->names->resolve( 'Biography', 'Maison de mes grands-parents' )
		);
		$this->assertSame(
			array(
				'post' => 1,
				'current' => true,
			),
			$this->names->resolve( 'BIOGRAPHY', 'maison de mes grands-parents' ),
			'The same name.'
		);
		$this->assertNull( $this->names->resolve( 'Biography', 'Paris' ) );
		$this->assertNull( $this->names->resolve( 'Biography', 'bad|title' ), 'A title that is not a name resolves to nothing.' );
		$this->assertNull( $this->names->resolve( 'Nowhere', 'Paris' ) );
		$this->assertSame( array( 1 ), $this->names->pages_of( 'Biography' ) );
		$this->assertSame( array(), $this->names->pages_of( 'Opinion' ) );
		$this->assertSame( array(), $this->names->pages_of( 'Nowhere' ) );

		$this->assertSame( 'Biography', $this->statements->objects_of( Ref::post( 1 ), 'wiki/space' )[0]->object()->key() );
		$this->assertSame( 'biography:maison de mes grands-parents', $this->statements->objects_of( Ref::post( 1 ), 'wiki/folded-name' )[0]->object()->key() );
	}

	/**
	 * Claiming the name a page already has changes nothing.
	 *
	 * @return void
	 */
	public function test_claiming_twice_changes_nothing(): void {
		$this->names->claim( 1, 'Biography', 'Paris' );
		$again = $this->names->claim( 1, 'Biography', 'Paris' );

		$this->assertNull( $again['former'] );
		$this->assertSame( array(), $this->names->aliases_of( 1 ) );
		$this->assertCount( 3, $this->statements->objects_of( Ref::post( 1 ) ), 'Name, space, folded name, and nothing more.' );
	}

	/**
	 * A name that another page has as a current name is refused, with that page, and nothing is written.
	 *
	 * @return void
	 */
	public function test_a_name_held_as_a_current_name_is_refused(): void {
		$this->names->claim( 1, 'Biography', 'Paris' );

		$refusal = $this->refusal( fn() => $this->names->claim( 2, 'biography', 'paris' ) );

		$this->assertSame( NameException::TAKEN, $refusal->error_code() );
		$this->assertSame( 1, $refusal->holder() );
		$this->assertTrue( $refusal->holder_has_it_as_current() );
		$this->assertNull( $this->names->current_of( 2 ), 'Nothing was written for the page that was refused.' );
		$this->assertSame( array(), $this->statements->objects_of( Ref::post( 2 ) ) );
	}

	/**
	 * A rename keeps the former name as an alias; the alias is held: another page cannot take it, and it resolves to the page.
	 *
	 * @return void
	 */
	public function test_a_rename_leaves_an_alias_that_is_held(): void {
		$this->names->claim( 1, 'Biography', 'Paris' );
		$done = $this->names->claim( 1, 'Biography', 'Paris (ville)' );

		$this->assertSame( 'Biography:Paris', $done['former'] );
		$this->assertSame( array( 'Biography:Paris' ), $this->names->aliases_of( 1 ) );
		$this->assertSame(
			array(
				'post' => 1,
				'current' => false,
			),
			$this->names->resolve( 'Biography', 'Paris' ),
			'The alias resolves, not as the current name.'
		);
		$this->assertSame(
			array(
				'post' => 1,
				'current' => true,
			),
			$this->names->resolve( 'Biography', 'Paris (ville)' )
		);
		$this->assertSame( 'Paris (ville)', $this->names->current_of( 1 )['title'], 'One current name.' );

		$refusal = $this->refusal( fn() => $this->names->claim( 2, 'Biography', 'Paris' ) );

		$this->assertSame( NameException::TAKEN, $refusal->error_code() );
		$this->assertSame( 1, $refusal->holder() );
		$this->assertFalse( $refusal->holder_has_it_as_current(), 'Held as an alias.' );
	}

	/**
	 * A name that the page had as an alias comes back as its current name, and is no longer an alias.
	 *
	 * @return void
	 */
	public function test_a_name_that_comes_back(): void {
		$this->names->claim( 1, 'Biography', 'Paris' );
		$this->names->claim( 1, 'Biography', 'Lutèce' );
		$done = $this->names->claim( 1, 'Biography', 'Paris' );

		$this->assertSame( 'Biography:Lutèce', $done['former'] );
		$this->assertSame( array( 'Biography:Lutèce' ), $this->names->aliases_of( 1 ), 'Paris is the current name again, Lutèce an alias.' );
		$this->assertSame(
			array(
				'post' => 1,
				'current' => true,
			),
			$this->names->resolve( 'Biography', 'Paris' )
		);
	}

	/**
	 * A page can change space; the former name in the former space is an alias.
	 *
	 * @return void
	 */
	public function test_a_move_to_another_space(): void {
		$this->names->claim( 1, 'Biography', 'Paris' );
		$this->names->claim( 1, 'Encyclopédie', 'Paris' );

		$this->assertSame( array( 1 ), $this->names->pages_of( 'Encyclopédie' ) );
		$this->assertSame( array(), $this->names->pages_of( 'Biography' ) );
		$this->assertSame( array( 'Biography:Paris' ), $this->names->aliases_of( 1 ) );
	}

	/**
	 * What is refused: an unknown space, a title that is not a name, a name that is too long.
	 *
	 * @return void
	 */
	public function test_the_other_refusals(): void {
		$this->assertSame( NameException::UNKNOWN_SPACE, $this->refusal( fn() => $this->names->claim( 1, 'Nowhere', 'Paris' ) )->error_code() );
		$this->assertSame( NameException::EMPTY_TITLE, $this->refusal( fn() => $this->names->claim( 1, 'Biography', '  ' ) )->error_code() );
		$this->assertSame( NameException::FORBIDDEN, $this->refusal( fn() => $this->names->claim( 1, 'Biography', 'a|b' ) )->error_code() );
		$this->assertSame( NameException::TOO_LONG, $this->refusal( fn() => $this->names->claim( 1, 'Biography', str_repeat( 'a', 200 ) ) )->error_code() );
		$this->assertSame( array(), $this->statements->match() );
	}

	/**
	 * A name of exactly 191 bytes fits; two titles that differ by an accent are two names, even in a database with an accent-insensitive
	 * collation (the literal is stored in a binary column).
	 *
	 * @return void
	 */
	public function test_the_limit_and_the_accents(): void {
		$long = str_repeat( 'é', (int) floor( ( 191 - strlen( 'Biography:' ) ) / 2 ) );

		$this->assertSame( 190, strlen( $this->names->claim( 1, 'Biography', $long )['name'] ) );

		$this->names->claim( 2, 'Biography', 'Cité' );
		$this->names->claim( 3, 'Biography', 'Cite' );

		$this->assertSame( 2, $this->names->resolve( 'Biography', 'Cité' )['post'] );
		$this->assertSame( 3, $this->names->resolve( 'Biography', 'Cite' )['post'] );
		$this->assertSame( 2, $this->names->resolve( 'Biography', 'cité' )['post'], 'The first letter in capitals: the same name as Cité.' );
		$this->assertSame( NameException::TAKEN, $this->refusal( fn() => $this->names->claim( 4, 'Biography', 'cité' ) )->error_code() );
	}

	/**
	 * The suggestions: the pages whose folded name is the same, except the one that has exactly the name.
	 *
	 * @return void
	 */
	public function test_the_suggestions(): void {
		$this->names->claim( 1, 'Biography', 'Élisabeth' );
		$this->names->claim( 2, 'Biography', 'Elisabeth' );
		$this->names->claim( 3, 'Biography', 'ELISABETH' );
		$this->names->claim( 4, 'Opinion', 'Elisabeth' );

		$this->assertSame(
			array( 'Biography:ELISABETH', 'Biography:Elisabeth', 'Biography:Élisabeth' ),
			array_column( $this->names->similar( 'Biography', 'élisabèth' ), 'name' ),
			'The same folded name in the same space; the page of the other space is not one.'
		);
		$this->assertSame(
			array( 'Biography:ELISABETH', 'Biography:Élisabeth' ),
			array_column( $this->names->similar( 'Biography', 'Elisabeth' ), 'name' ),
			'The page that has exactly the name is not a suggestion.'
		);
		$this->assertSame( array(), $this->names->similar( 'Biography', 'Nobody' ) );
		$this->assertSame( array(), $this->names->similar( 'Nowhere', 'Elisabeth' ) );
	}

	/**
	 * The same title in the other spaces (the angles), by space, as current names or as aliases.
	 *
	 * @return void
	 */
	public function test_the_other_angles(): void {
		$this->names->claim( 1, 'Biography', 'Paris' );
		$this->names->claim( 2, 'Encyclopédie', 'Paris' );
		$this->names->claim( 3, 'Opinion', 'Lutèce' );
		$this->names->claim( 3, 'Opinion', 'Capitale' );
		$this->names->add_alias( 3, 'Opinion', 'Paris' );

		$angles = $this->names->elsewhere( 'Paris', 'Encyclopédie' );

		$this->assertSame( array( 'Biography:Paris', 'Opinion:Paris' ), array_column( $angles, 'name' ) );
		$this->assertSame( array( 1, 3 ), array_column( $angles, 'post' ) );
		$this->assertSame( array( true, false ), array_column( $angles, 'current' ) );
		$this->assertCount( 3, $this->names->elsewhere( 'Paris' ), 'No space left out.' );
		$this->assertSame( array(), $this->names->elsewhere( 'Nobody', 'Biography' ) );
	}

	/**
	 * An alias is added for a page that had the name, or that an import brings; refused when another page holds it, nothing when the page
	 * has it already.
	 *
	 * @return void
	 */
	public function test_adding_an_alias(): void {
		$this->names->claim( 1, 'Biography', 'Paris' );
		$this->names->claim( 2, 'Biography', 'Lyon' );

		$this->assertTrue( $this->names->add_alias( 1, 'Biography', 'Lutèce' ) );
		$this->assertFalse( $this->names->add_alias( 1, 'Biography', 'Lutèce' ), 'Already an alias of the page.' );
		$this->assertFalse( $this->names->add_alias( 1, 'Biography', 'Paris' ), 'Its own current name.' );
		$this->assertSame( array( 'Biography:Lutèce' ), $this->names->aliases_of( 1 ) );

		$this->assertSame( NameException::TAKEN, $this->refusal( fn() => $this->names->add_alias( 2, 'Biography', 'Paris' ) )->error_code() );
		$this->assertSame( NameException::TAKEN, $this->refusal( fn() => $this->names->add_alias( 2, 'Biography', 'Lutèce' ) )->error_code() );
		$this->assertSame( NameException::UNKNOWN_SPACE, $this->refusal( fn() => $this->names->add_alias( 2, 'Nowhere', 'Lutèce' ) )->error_code() );
	}

	/**
	 * The limits of the predicates are the ones of Triples: one current name and one space per page, one page per name, and no other kind
	 * of subject or object.
	 *
	 * @return void
	 */
	public function test_the_predicates(): void {
		$name = new \Otherguise\Triples\Entity\Literal( 'string', 'Biography:A' );
		$this->statements->create( Ref::post( 1 ), 'wiki/name', $name );

		foreach (
			array(
				array( Ref::post( 1 ), 'wiki/name', 'Biography:B' ),
				array( Ref::post( 2 ), 'wiki/name', 'Biography:A' ),
				array( Ref::post( 1 ), 'wiki/name', 12 ),
			) as $case
		) {
			$refused = false;

			try {
				$this->statements->create( $case[0], $case[1], $case[2] );
			} catch ( InvalidStatementException $refusal ) {
				$refused = true;
			}

			$this->assertTrue( $refused, wp_json_encode( $case[2] ) );
		}

		// Many pages may share a folded name, many aliases a page.
		$this->statements->create( Ref::post( 1 ), 'wiki/folded-name', 'x:y' );
		$this->statements->create( Ref::post( 2 ), 'wiki/folded-name', 'x:y' );
		$this->statements->create( Ref::post( 1 ), 'wiki/former-name', 'Biography:C' );
		$this->statements->create( Ref::post( 1 ), 'wiki/former-name', 'Biography:D' );

		$this->assertCount( 2, $this->statements->subjects_of( 'x:y', 'wiki/folded-name' ) );
	}

	/**
	 * A page that is deleted loses its names (the cleanup of Triples), and the name is free again.
	 *
	 * @return void
	 */
	public function test_a_deleted_page_frees_its_names(): void {
		$this->names->claim( 1, 'Biography', 'Paris' );
		$this->names->claim( 1, 'Biography', 'Lutèce' );

		$this->statements->forget( Ref::post( 1 ) );

		$this->assertNull( $this->names->resolve( 'Biography', 'Paris' ), 'The alias is gone with the page.' );
		$this->assertNull( $this->names->resolve( 'Biography', 'Lutèce' ) );
		$this->assertSame( array(), $this->names->pages_of( 'Biography' ) );
		$this->assertSame( 'Biography:Paris', $this->names->claim( 2, 'Biography', 'Paris' )['name'] );
	}

	/**
	 * The rebuild makes the current names again from a list of pages and keeps the aliases; a page that cannot have its name is reported.
	 *
	 * @return void
	 */
	public function test_the_rebuild(): void {
		$this->names->claim( 1, 'Biography', 'Paris' );
		$this->names->claim( 1, 'Biography', 'Lutèce' );
		$this->names->claim( 2, 'Opinion', 'Stale' );

		$report = $this->names->rebuild(
			array(
				array(
					'post' => 1,
					'space' => 'Biography',
					'title' => 'Lutèce',
				),
				array(
					'post' => 3,
					'space' => 'Encyclopédie',
					'title' => 'Rome',
				),
				array(
					'post' => 4,
					'space' => 'Biography',
					'title' => 'Paris',
				),
				array(
					'post' => 5,
					'space' => 'Nowhere',
					'title' => 'Lost',
				),
				array(
					'post' => 6,
					'space' => 'Biography',
					'title' => 'bad|title',
				),
			)
		);

		$this->assertSame( 2, $report['done'] );
		$this->assertSame(
			array(
				array(
					'post' => 4,
					'code' => NameException::TAKEN,
					'holder' => 1,
				),
				array(
					'post' => 5,
					'code' => NameException::UNKNOWN_SPACE,
					'holder' => 0,
				),
				array(
					'post' => 6,
					'code' => NameException::FORBIDDEN,
					'holder' => 0,
				),
			),
			$report['refused'],
			'Paris is an alias of page 1: kept, so page 4 cannot have it.'
		);
		$this->assertNull( $this->names->current_of( 2 ), 'The name that no list gives is gone.' );
		$this->assertNull( $this->names->resolve( 'Opinion', 'Stale' ) );
		$this->assertSame( 'Lutèce', $this->names->current_of( 1 )['title'] );
		$this->assertSame( array( 'Biography:Paris' ), $this->names->aliases_of( 1 ), 'The aliases are kept.' );
		$this->assertSame( array( 3 ), $this->names->pages_of( 'Encyclopédie' ) );
	}
}
