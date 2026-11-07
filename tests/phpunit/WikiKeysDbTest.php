<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the index of the tree (slice 501, hierarchical model) on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Service\InvalidStatementException;
use Otherguise\Wiki\Module as WikiModule;
use Otherguise\Wiki\NameException;
use Otherguise\Wiki\Tree\Keys;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';

/**
 * The statements are real (MariaDB), the posts are the stubs of WordPress.
 *
 * @covers \Otherguise\Wiki\Tree\Keys
 * @covers \Otherguise\Wiki\Module::register_predicates
 */
class WikiKeysDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The service.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * The statements.
	 *
	 * @var \Otherguise\Triples\Statements
	 */
	private $statements;

	/**
	 * Builds a site with the predicates of the tree and twelve pages.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$pages = array();

		for ( $id = 1; $id <= 12; $id++ ) {
			$pages[] = new WP_Post( $id );
		}

		otherguise_test_wp_objects( $pages );

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
		$this->keys       = new Keys(
			static function () use ( $statements ) {
				return $statements;
			},
			static fn( $title ) => in_array( $title, array( 'feed', 'wp-admin' ), true )
		);
	}

	/**
	 * The refusal of a call, or null.
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
	 * A tree: Biography (1) with 1958-1968 (2) with Chapitre premier (3); Opinion (4); Paris under Biography (5) and under Opinion (6).
	 *
	 * @return void
	 */
	private function tree(): void {
		$this->keys->claim( 1, 0, 'Biography' );
		$this->keys->claim( 2, 1, '1958-1968' );
		$this->keys->claim( 3, 2, 'Chapitre premier' );
		$this->keys->claim( 4, 0, 'Opinion' );
		$this->keys->claim( 5, 1, 'Paris' );
		$this->keys->claim( 6, 4, 'Paris' );
	}

	/**
	 * A title is given under a parent: the key, the title and the folded key are kept, and the page is found by its path.
	 *
	 * @return void
	 */
	public function test_a_title_is_given_under_a_parent(): void {
		$done = $this->keys->claim( 5, 1, 'Sant_Llorenç  de Morunys' );

		$this->assertSame(
			array(
				'key' => '1/Sant Llorenç de Morunys',
				'parent' => 1,
				'title' => 'Sant Llorenç de Morunys',
				'former' => null,
			),
			$done
		);
		$this->assertSame(
			array(
				'parent' => 1,
				'title' => 'Sant Llorenç de Morunys',
				'key' => '1/Sant Llorenç de Morunys',
			),
			$this->keys->current_of( 5 )
		);
		$this->assertSame(
			array(
				'post' => 5,
				'current' => true,
			),
			$this->keys->resolve( 1, 'Sant Llorenç de Morunys' )
		);
		$this->assertSame(
			array(
				'post' => 5,
				'current' => true,
			),
			$this->keys->resolve( 1, 'Sant_Llorenç_de_Morunys' ),
			'The underscore is a space.'
		);
		$this->assertNull( $this->keys->resolve( 1, 'sant llorenç de morunys' ), 'Case-sensitive.' );
		$this->assertNull( $this->keys->resolve( 2, 'Sant Llorenç de Morunys' ), 'Under another parent.' );
		$this->assertNull( $this->keys->resolve( 1, 'bad|title' ), 'A title that is not a name resolves to nothing.' );

		$this->assertSame( '1/sant llorenc de morunys', $this->statements->objects_of( Ref::post( 5 ), 'wiki/folded-key' )[0]->object()->key() );
		$this->assertSame( 'Sant Llorenç de Morunys', $this->statements->objects_of( Ref::post( 5 ), 'wiki/title' )[0]->object()->key() );
	}

	/**
	 * Paris and paris are two pages, and so are two titles that differ by an accent: the index is exact.
	 *
	 * @return void
	 */
	public function test_the_titles_are_case_and_accent_sensitive(): void {
		$this->keys->claim( 1, 0, 'Biography' );
		$this->keys->claim( 2, 1, 'Paris' );
		$this->keys->claim( 3, 1, 'paris' );
		$this->keys->claim( 4, 1, 'Ansò' );
		$this->keys->claim( 5, 1, 'Anso' );

		$this->assertSame( 2, $this->keys->resolve( 1, 'Paris' )['post'] );
		$this->assertSame( 3, $this->keys->resolve( 1, 'paris' )['post'] );
		$this->assertSame( 4, $this->keys->resolve( 1, 'Ansò' )['post'] );
		$this->assertSame( 5, $this->keys->resolve( 1, 'Anso' )['post'] );
	}

	/**
	 * The same title under another parent is another page; the same key is refused, with the page that holds it, and nothing is written.
	 *
	 * @return void
	 */
	public function test_a_taken_key_is_refused(): void {
		$this->tree();

		$refusal = $this->refusal( fn() => $this->keys->claim( 7, 1, 'Paris' ) );

		$this->assertSame( NameException::TAKEN, $refusal->error_code() );
		$this->assertSame( 5, $refusal->holder() );
		$this->assertTrue( $refusal->holder_has_it_as_current() );
		$this->assertNull( $this->keys->current_of( 7 ) );
		$this->assertSame( array(), $this->statements->objects_of( Ref::post( 7 ) ) );
		$this->assertSame( '2/Paris', $this->keys->claim( 7, 2, 'Paris' )['key'], 'Under another parent it is free.' );
	}

	/**
	 * A rename keeps the old key as an alias that is held; a key that comes back is no longer an alias.
	 *
	 * @return void
	 */
	public function test_a_rename_leaves_an_alias_that_is_held(): void {
		$this->tree();

		$done = $this->keys->claim( 5, 1, 'Paris (ville)' );

		$this->assertSame( '1/Paris', $done['former'] );
		$this->assertSame( array( '1/Paris' ), $this->keys->aliases_of( 5 ) );
		$this->assertSame(
			array(
				'post' => 5,
				'current' => false,
			),
			$this->keys->resolve( 1, 'Paris' )
		);
		$this->assertSame( 'Paris (ville)', $this->keys->current_of( 5 )['title'], 'One current key.' );

		$refusal = $this->refusal( fn() => $this->keys->claim( 7, 1, 'Paris' ) );

		$this->assertSame( 5, $refusal->holder() );
		$this->assertFalse( $refusal->holder_has_it_as_current(), 'Held as an alias.' );

		$back = $this->keys->claim( 5, 1, 'Paris' );

		$this->assertSame( '1/Paris (ville)', $back['former'] );
		$this->assertSame( array( '1/Paris (ville)' ), $this->keys->aliases_of( 5 ) );
		$this->assertSame(
			array(
				'post' => 5,
				'current' => true,
			),
			$this->keys->resolve( 1, 'Paris' )
		);
	}

	/**
	 * A path is walked one level at a time; the titles are given as typed.
	 *
	 * @return void
	 */
	public function test_a_path_is_walked(): void {
		$this->tree();

		$this->assertSame(
			array(
				'post' => 3,
				'exact' => true,
				'deepest' => 3,
				'rest' => array(),
			),
			$this->keys->resolve_path( array( 'Biography', '1958-1968', 'Chapitre_premier' ) )
		);
		$this->assertSame(
			array(
				'post' => 6,
				'exact' => true,
				'deepest' => 6,
				'rest' => array(),
			),
			$this->keys->resolve_path( array( 'Opinion', 'Paris' ) ),
			'The same title under another branch.'
		);
		$this->assertSame(
			array(
				'post' => 1,
				'exact' => true,
				'deepest' => 1,
				'rest' => array(),
			),
			$this->keys->resolve_path( array( 'Biography' ) )
		);
	}

	/**
	 * A path that stops: the last page that exists and the titles that remain, for the 404.
	 *
	 * @return void
	 */
	public function test_a_path_that_stops(): void {
		$this->tree();

		$this->assertSame(
			array(
				'post' => null,
				'exact' => true,
				'deepest' => 1,
				'rest' => array( 'Nobody' ),
			),
			$this->keys->resolve_path( array( 'Biography', 'Nobody' ) )
		);
		$this->assertSame(
			array(
				'post' => null,
				'exact' => true,
				'deepest' => 2,
				'rest' => array( 'Nobody', 'Deeper' ),
			),
			$this->keys->resolve_path( array( 'Biography', '1958-1968', 'Nobody', 'Deeper' ) )
		);
		$this->assertSame(
			array(
				'post' => null,
				'exact' => true,
				'deepest' => 0,
				'rest' => array( 'Nobody', 'Paris' ),
			),
			$this->keys->resolve_path( array( 'Nobody', 'Paris' ) )
		);
		$this->assertSame(
			array(
				'post' => null,
				'exact' => true,
				'deepest' => 0,
				'rest' => array(),
			),
			$this->keys->resolve_path( array() )
		);
		$this->assertSame(
			array(
				'post' => null,
				'exact' => true,
				'deepest' => 1,
				'rest' => array( 'bad|title' ),
			),
			$this->keys->resolve_path( array( 'Biography', 'bad|title' ) ),
			'A title that is not a name stops the path.'
		);
		$this->assertSame(
			array(
				'post' => null,
				'exact' => true,
				'deepest' => 0,
				'rest' => array( 'biography' ),
			),
			$this->keys->resolve_path( array( 'biography' ) ),
			'Case-sensitive.'
		);
	}

	/**
	 * A move changes only the key of the page: the descendants follow, and the old path of a descendant keeps resolving through the alias of
	 * its ancestor, flagged as not exact so that the address is redirected.
	 *
	 * @return void
	 */
	public function test_a_move_carries_the_descendants(): void {
		$this->tree();

		$this->assertSame( array( 'Biography', '1958-1968', 'Chapitre premier' ), $this->keys->path_of( 3 ) );

		$done = $this->keys->claim( 2, 4, '1958-1968' );

		$this->assertSame( '1/1958-1968', $done['former'] );
		$this->assertSame( array( 'Opinion', '1958-1968', 'Chapitre premier' ), $this->keys->path_of( 3 ), 'The descendant follows with no change of its own.' );
		$this->assertSame(
			array(
				'post' => 3,
				'exact' => true,
				'deepest' => 3,
				'rest' => array(),
			),
			$this->keys->resolve_path( array( 'Opinion', '1958-1968', 'Chapitre premier' ) )
		);
		$this->assertSame(
			array(
				'post' => 3,
				'exact' => false,
				'deepest' => 3,
				'rest' => array(),
			),
			$this->keys->resolve_path( array( 'Biography', '1958-1968', 'Chapitre premier' ) ),
			'The old path still resolves, not exact.'
		);
		$this->assertSame( array( '1/1958-1968' ), $this->keys->aliases_of( 2 ) );
		$this->assertSame( array(), $this->keys->aliases_of( 3 ), 'The descendant has no alias.' );
	}

	/**
	 * The path of a page is read from the keys; a page without a key, or under a parent without one, has none.
	 *
	 * @return void
	 */
	public function test_the_path_of_a_page(): void {
		$this->assertNull( $this->keys->path_of( 3 ) );

		$this->keys->claim( 3, 2, 'Chapitre' );

		$this->assertNull( $this->keys->path_of( 3 ), 'The parent has no key yet.' );

		$this->keys->claim( 2, 1, 'Livre' );
		$this->keys->claim( 1, 0, 'Biography' );

		$this->assertSame( array( 'Biography', 'Livre', 'Chapitre' ), $this->keys->path_of( 3 ) );
		$this->assertSame( array( 'Biography' ), $this->keys->path_of( 1 ) );
	}

	/**
	 * What is refused: a page that is its own parent, a number at the first level, a title that a route serves first, a bad title.
	 *
	 * @return void
	 */
	public function test_the_other_refusals(): void {
		$this->assertSame( NameException::BAD_PARENT, $this->refusal( fn() => $this->keys->claim( 3, 3, 'Paris' ) )->error_code() );
		$this->assertSame( NameException::DIGITS, $this->refusal( fn() => $this->keys->claim( 3, 0, '2026' ) )->error_code() );
		$this->assertSame( NameException::RESERVED, $this->refusal( fn() => $this->keys->claim( 3, 0, 'feed' ) )->error_code() );
		$this->assertSame( NameException::RESERVED, $this->refusal( fn() => $this->keys->claim( 3, 0, 'wp-admin' ) )->error_code() );
		$this->assertSame( NameException::SLASH, $this->refusal( fn() => $this->keys->claim( 3, 1, 'a/b' ) )->error_code() );
		$this->assertSame( NameException::EMPTY_TITLE, $this->refusal( fn() => $this->keys->claim( 3, 1, '  ' ) )->error_code() );
		$this->assertSame( NameException::TOO_LONG, $this->refusal( fn() => $this->keys->claim( 3, 1, str_repeat( 'a', 200 ) ) )->error_code() );
		$this->assertSame( array(), $this->statements->match(), 'Nothing was written.' );

		$this->assertSame( '1/feed', $this->keys->claim( 3, 1, 'feed' )['key'], 'A route only concerns the first level.' );
		$this->assertSame( '1/2026', $this->keys->claim( 4, 1, '2026' )['key'], 'And so does a number.' );
	}

	/**
	 * Without the test of the routes the first level only refuses what the rules refuse.
	 *
	 * @return void
	 */
	public function test_without_a_test_of_the_routes(): void {
		$statements = $this->statements;
		$plain      = new Keys(
			static function () use ( $statements ) {
				return $statements;
			}
		);

		$this->assertSame( '0/feed', $plain->claim( 1, 0, 'feed' )['key'] );
	}

	/**
	 * The siblings of a similar folded key, except the page that has exactly the key.
	 *
	 * @return void
	 */
	public function test_the_suggestions(): void {
		$this->keys->claim( 1, 0, 'Biography' );
		$this->keys->claim( 2, 1, 'Élisabeth' );
		$this->keys->claim( 3, 1, 'Elisabeth' );
		$this->keys->claim( 4, 1, 'ELISABETH' );
		$this->keys->claim( 5, 0, 'Elisabeth' );

		$this->assertSame(
			array( 'ELISABETH', 'Elisabeth', 'Élisabeth' ),
			array_column( $this->keys->similar( 1, 'élisabèth' ), 'title' ),
			'The same folded key under the same parent; the page of the first level is not one.'
		);
		$this->assertSame( array( 'ELISABETH', 'Élisabeth' ), array_column( $this->keys->similar( 1, 'Elisabeth' ), 'title' ), 'The page that has exactly the key is left out.' );
		$this->assertSame( array(), $this->keys->similar( 1, 'Nobody' ) );
		$this->assertSame( array(), $this->keys->similar( 1, 'a|b' ) );
	}

	/**
	 * The same title anywhere in the tree (the angles), except the page shown, bounded.
	 *
	 * @return void
	 */
	public function test_the_same_title_elsewhere(): void {
		$this->tree();
		$this->keys->claim( 7, 3, 'Paris' );
		$this->keys->claim( 8, 0, 'Paris' );

		$angles = $this->keys->elsewhere( 'Paris', 5 );

		$this->assertSame( array( 6, 7, 8 ), array_column( $angles, 'post' ), 'At any level, by page.' );
		$this->assertSame( array( 4, 3, 0 ), array_column( $angles, 'parent' ) );
		$this->assertSame( array( 5, 6, 7, 8 ), array_column( $this->keys->elsewhere( 'Paris' ), 'post' ), 'No page left out.' );
		$this->assertSame( array( 5 ), array_column( $this->keys->elsewhere( 'Paris', 0, 1 ), 'post' ), 'Bounded.' );
		$this->assertSame( array(), $this->keys->elsewhere( 'paris' ), 'The title is exact.' );
		$this->assertSame( array(), $this->keys->elsewhere( 'a|b' ) );
	}

	/**
	 * An alias is added for a page that had the key; refused when another page holds it, nothing when the page has it already.
	 *
	 * @return void
	 */
	public function test_adding_an_alias(): void {
		$this->tree();

		$this->assertTrue( $this->keys->add_alias( 5, 0, 'Lutèce' ) );
		$this->assertFalse( $this->keys->add_alias( 5, 0, 'Lutèce' ), 'Already an alias of the page.' );
		$this->assertFalse( $this->keys->add_alias( 5, 1, 'Paris' ), 'Its own current key.' );
		$this->assertSame( array( '0/Lutèce' ), $this->keys->aliases_of( 5 ) );
		$this->assertSame(
			array(
				'post' => 5,
				'current' => false,
			),
			$this->keys->resolve( 0, 'Lutèce' )
		);

		$this->assertSame( NameException::TAKEN, $this->refusal( fn() => $this->keys->add_alias( 6, 0, 'Lutèce' ) )->error_code() );
		$this->assertSame( NameException::TAKEN, $this->refusal( fn() => $this->keys->add_alias( 6, 1, 'Paris' ) )->error_code() );
		$this->assertSame( NameException::DIGITS, $this->refusal( fn() => $this->keys->add_alias( 6, 0, '2026' ) )->error_code() );
	}

	/**
	 * The limits of the predicates are the ones of Triples.
	 *
	 * @return void
	 */
	public function test_the_predicates(): void {
		$this->statements->create( Ref::post( 1 ), 'wiki/key', new Literal( 'string', '0/A' ) );

		foreach (
			array(
				array( Ref::post( 1 ), 'wiki/key', '0/B' ),
				array( Ref::post( 2 ), 'wiki/key', '0/A' ),
				array( Ref::post( 1 ), 'wiki/key', 12 ),
				array( Ref::post( 1 ), 'wiki/title', 'a' ),
			) as $index => $case
		) {
			$refused = false;

			if ( 3 === $index ) {
				$this->statements->create( Ref::post( 1 ), 'wiki/title', 'a' );
			}

			try {
				$this->statements->create( $case[0], $case[1], 3 === $index ? 'b' : $case[2] );
			} catch ( InvalidStatementException $refusal ) {
				$refused = true;
			}

			$this->assertTrue( $refused, (string) $index );
		}

		$this->statements->create( Ref::post( 1 ), 'wiki/folded-key', '0/a' );
		$this->statements->create( Ref::post( 2 ), 'wiki/folded-key', '0/a' );
		$this->statements->create( Ref::post( 1 ), 'wiki/former-key', '0/C' );
		$this->statements->create( Ref::post( 1 ), 'wiki/former-key', '0/D' );
		$this->statements->create( Ref::post( 2 ), 'wiki/title', 'same' );
		$this->statements->create( Ref::post( 3 ), 'wiki/title', 'same' );

		$this->assertCount( 2, $this->statements->subjects_of( '0/a', 'wiki/folded-key' ), 'Many pages share a folded key.' );
		$this->assertCount( 2, $this->statements->subjects_of( 'same', 'wiki/title' ), 'Many pages share a title.' );
	}

	/**
	 * A page that is deleted frees its keys, its aliases and its title; a key of exactly 191 bytes fits.
	 *
	 * @return void
	 */
	public function test_a_deleted_page_frees_its_keys(): void {
		$this->tree();
		$this->keys->claim( 5, 1, 'Paris (ville)' );

		$this->statements->forget( Ref::post( 5 ) );

		$this->assertNull( $this->keys->resolve( 1, 'Paris' ), 'The alias is gone with the page.' );
		$this->assertNull( $this->keys->resolve( 1, 'Paris (ville)' ) );
		$this->assertSame( array(), $this->keys->elsewhere( 'Paris (ville)' ) );
		$this->assertSame( '1/Paris', $this->keys->claim( 7, 1, 'Paris' )['key'], 'The key is free again.' );

		$room = 191 - strlen( '2/' );

		$this->assertSame( 191, strlen( $this->keys->claim( 8, 2, str_repeat( 'a', $room ) )['key'] ) );
	}

	/**
	 * The rebuild makes the current keys again from a list of pages and keeps the aliases; a page that cannot have its key is reported.
	 *
	 * @return void
	 */
	public function test_the_rebuild(): void {
		$this->tree();
		$this->keys->claim( 5, 1, 'Lutèce' );
		$this->keys->claim( 9, 0, 'Stale' );

		$report = $this->keys->rebuild(
			array(
				array(
					'post' => 1,
					'parent' => 0,
					'title' => 'Biography',
				),
				array(
					'post' => 5,
					'parent' => 1,
					'title' => 'Lutèce',
				),
				array(
					'post' => 10,
					'parent' => 1,
					'title' => 'Paris',
				),
				array(
					'post' => 11,
					'parent' => 0,
					'title' => '2026',
				),
				array(
					'post' => 12,
					'parent' => 1,
					'title' => 'a/b',
				),
			)
		);

		$this->assertSame( 2, $report['done'] );
		$this->assertSame(
			array(
				array(
					'post' => 10,
					'code' => NameException::TAKEN,
					'holder' => 5,
				),
				array(
					'post' => 11,
					'code' => NameException::DIGITS,
					'holder' => 0,
				),
				array(
					'post' => 12,
					'code' => NameException::SLASH,
					'holder' => 0,
				),
			),
			$report['refused'],
			'Paris is an alias of page 5: kept, so page 10 cannot have it.'
		);
		$this->assertNull( $this->keys->current_of( 9 ), 'The key that no list gives is gone.' );
		$this->assertNull( $this->keys->resolve( 0, 'Stale' ) );
		$this->assertSame( array( '1/Paris' ), $this->keys->aliases_of( 5 ), 'The aliases are kept.' );
		$this->assertSame( 5, $this->keys->resolve( 1, 'Lutèce' )['post'] );
	}
}
