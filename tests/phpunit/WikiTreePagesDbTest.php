<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the pages of the tree (slice 502, hierarchical model): the service, the checks at saving, the command.
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Cli\TreeCommand;
use Otherguise\Wiki\Module as WikiModule;
use Otherguise\Wiki\NameException;
use Otherguise\Wiki\Page\Saving as FirstModelSaving;
use Otherguise\Wiki\Tree\Keys;
use Otherguise\Wiki\Tree\Pages;
use Otherguise\Wiki\Tree\Rules;
use Otherguise\Wiki\Tree\Saving;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-node-store.php';

/**
 * The statements are real (MariaDB), the posts are an array.
 *
 * @covers \Otherguise\Wiki\Tree\Pages
 * @covers \Otherguise\Wiki\Tree\Saving
 * @covers \Otherguise\Wiki\Tree\Nodes
 * @covers \Otherguise\Wiki\Tree\Keys::check
 * @covers \Otherguise\Wiki\Cli\TreeCommand
 */
class WikiTreePagesDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The posts.
	 *
	 * @var Otherguise_Test_Node_Store
	 */
	private $store;

	/**
	 * The index.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * The pages.
	 *
	 * @var Pages
	 */
	private $pages;

	/**
	 * Builds a site with the predicates and no page.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		otherguise_test_wp_objects( array() );

		$wiki       = new WikiModule();
		$site       = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $wiki ) {
					$wiki->register_predicates( $registry );
				},
			)
		);
		$statements = $site->statements;
		$this->keys = new Keys(
			static function () use ( $statements ) {
				return $statements;
			},
			static fn( $title ) => 'feed' === $title
		);
		$this->store = new Otherguise_Test_Node_Store();
		$this->pages = new Pages( $this->keys, $this->store );
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
	 * A page is made under its parent, with its key, and the content is kept as it is.
	 *
	 * @return void
	 */
	public function test_creating_a_page(): void {
		$bio = $this->pages->create( 0, 'Biography', '', 'publish' );
		$one = $this->pages->create( $bio, 'sant_llorenç  de Morunys', '<p>Text</p>', 'publish' );

		$this->assertSame( 'sant llorenç de Morunys', $this->store->pages[ $one ]['title'], 'The title is the title of the key, the case kept.' );
		$this->assertSame( '<p>Text</p>', $this->store->pages[ $one ]['content'], 'Kept as it is.' );
		$this->assertSame( $bio, $this->store->pages[ $one ]['parent'] );
		$this->assertSame( 'publish', $this->store->pages[ $one ]['status'] );
		$this->assertSame( array( 'Biography', 'sant llorenç de Morunys' ), $this->keys->path_of( $one ) );
		$this->assertSame( 'draft', $this->store->pages[ $this->pages->create( $bio, 'Paris' ) ]['status'], 'A draft by default.' );
	}

	/**
	 * The same title under another parent is another page; the same key is refused and nothing is left behind.
	 *
	 * @return void
	 */
	public function test_a_taken_key_is_refused_and_nothing_is_left(): void {
		$bio   = $this->pages->create( 0, 'Biography' );
		$op    = $this->pages->create( 0, 'Opinion' );
		$first = $this->pages->create( $bio, 'Paris' );

		$this->assertNotSame( $first, $this->pages->create( $op, 'Paris' ), 'Under another parent it is accepted.' );
		$this->assertNotSame( $first, $this->pages->create( $bio, 'paris' ), 'The case tells two titles apart.' );

		$count   = count( $this->store->pages );
		$refusal = $this->refusal( fn() => $this->pages->create( $bio, 'Paris' ) );

		$this->assertSame( NameException::TAKEN, $refusal->error_code() );
		$this->assertSame( $first, $refusal->holder() );
		$this->assertCount( $count, $this->store->pages, 'No page is left behind.' );
		$this->assertSame( array(), $this->store->deleted, 'It was refused before the page was made.' );
	}

	/**
	 * What is refused before anything is made: an unknown parent, a bad title, a number or a route at the first level, a bad status.
	 *
	 * @return void
	 */
	public function test_the_other_refusals_make_nothing(): void {
		$bio = $this->pages->create( 0, 'Biography' );

		$this->assertSame( NameException::UNKNOWN_PARENT, $this->refusal( fn() => $this->pages->create( 999, 'Paris' ) )->error_code() );
		$this->assertSame( NameException::SLASH, $this->refusal( fn() => $this->pages->create( $bio, 'a/b' ) )->error_code() );
		$this->assertSame( NameException::DIGITS, $this->refusal( fn() => $this->pages->create( 0, '2026' ) )->error_code() );
		$this->assertSame( NameException::RESERVED, $this->refusal( fn() => $this->pages->create( 0, 'feed' ) )->error_code() );
		$this->assertSame( NameException::EMPTY_TITLE, $this->refusal( fn() => $this->pages->create( $bio, ' ' ) )->error_code() );
		$this->assertCount( 1, $this->store->pages );

		$this->pages->create( $bio, '2026' );
		$this->assertCount( 2, $this->store->pages, 'A number below the first level is a title.' );

		$this->expectException( InvalidArgumentException::class );

		try {
			$this->pages->create( $bio, 'Paris', '', 'trash' );
		} finally {
			$this->assertCount( 2, $this->store->pages );
		}
	}

	/**
	 * A page whose key cannot be claimed after it was made is deleted again.
	 *
	 * @return void
	 */
	public function test_a_page_that_cannot_be_claimed_is_deleted(): void {
		$store = new class() extends Otherguise_Test_Node_Store {
			/**
			 * What to do once the page is inserted: the race.
			 *
			 * @var callable|null
			 */
			public $after_insert = null;

			/**
			 * Inserts, then lets the race happen.
			 *
			 * @param array<string, mixed> $post Arguments.
			 * @return int
			 */
			public function insert( array $post ) {
				$id = parent::insert( $post );

				if ( null !== $this->after_insert ) {
					( $this->after_insert )();
				}

				return $id;
			}
		};
		$store->add( 7, 'Elsewhere', 0 );
		$keys                = $this->keys;
		$store->after_insert = static function () use ( $keys ) {
			$keys->claim( 7, 0, 'Race' );
		};
		$pages               = new Pages( $this->keys, $store );

		$refusal = $this->refusal( fn() => $pages->create( 0, 'Race' ) );

		$this->assertSame( NameException::TAKEN, $refusal->error_code() );
		$this->assertSame( array( 100 ), $store->deleted, 'The page that was made is deleted again.' );
		$this->assertSame( 7, $this->keys->resolve( 0, 'Race' )['post'] );
	}

	/**
	 * A saved page gets its key from its parent and its title; a new title is a rename and a new parent a move; what has no title, is in the
	 * trash or is an auto-draft is left alone.
	 *
	 * @return void
	 */
	public function test_the_claim_after_a_save(): void {
		$this->store->add( 1, 'Biography', 0 );
		$this->store->add( 2, 'Paris', 1 );
		$this->store->add( 3, '', 1, 'draft' );
		$this->store->add( 4, 'Rome', 1, 'trash' );
		$this->store->add( 5, 'Nice', 1, 'auto-draft' );
		$this->store->add( 6, 'Opinion', 0 );

		$this->assertSame( '0/Biography', $this->pages->sync( 1 )['key'] );
		$this->assertSame( '1/Paris', $this->pages->sync( 2 )['key'] );
		$this->assertNull( $this->pages->sync( 3 ), 'No title.' );
		$this->assertNull( $this->pages->sync( 4 ), 'In the trash.' );
		$this->assertNull( $this->pages->sync( 5 ), 'An auto-draft.' );
		$this->assertNull( $this->pages->sync( 99 ), 'Not a page.' );
		$this->pages->sync( 6 );
		$this->assertNull( $this->pages->sync( 2 )['former'], 'Twice is the same.' );

		$this->store->pages[2]['title'] = 'Lutèce';
		$rename                         = $this->pages->sync( 2 );

		$this->assertSame( '1/Paris', $rename['former'], 'A new title is a rename.' );

		$this->store->pages[2]['parent'] = 6;
		$move                            = $this->pages->sync( 2 );

		$this->assertSame( '1/Lutèce', $move['former'], 'A new parent is a move.' );
		$this->assertSame( array( '1/Lutèce', '1/Paris' ), $this->keys->aliases_of( 2 ) );
		$this->assertSame( array( 'Opinion', 'Lutèce' ), $this->keys->path_of( 2 ) );
	}

	/**
	 * The check before a save: free, the page's own key, taken, refused, a route.
	 *
	 * @return void
	 */
	public function test_the_check_before_a_save(): void {
		$this->store->add( 1, 'Biography', 0 );
		$this->store->add( 2, 'Paris', 1 );
		$this->pages->sync( 1 );
		$this->pages->sync( 2 );

		$this->assertNull( $this->pages->check( 0, 1, 'Lyon' ) );
		$this->assertNull( $this->pages->check( 2, 1, 'Paris' ), 'The key the page has.' );
		$this->assertNull( $this->pages->check( 0, 1, 'paris' ), 'The case tells two titles apart.' );
		$this->assertSame( NameException::TAKEN, $this->pages->check( 3, 1, 'Paris' )->error_code() );
		$this->assertSame( NameException::TAKEN, $this->pages->check( 0, 1, 'Paris' )->error_code() );
		$this->assertSame( NameException::SLASH, $this->pages->check( 0, 1, 'a/b' )->error_code() );
		$this->assertSame( NameException::DIGITS, $this->pages->check( 0, 0, '2026' )->error_code() );
		$this->assertSame( NameException::RESERVED, $this->pages->check( 0, 0, 'feed' )->error_code() );
		$this->assertSame( NameException::BAD_PARENT, $this->pages->check( 2, 2, 'Paris' )->error_code() );
		$this->assertSame(
			array(
				'post' => 2,
				'exact' => true,
				'deepest' => 2,
				'rest' => array(),
			),
			$this->pages->find( array( 'Biography', 'Paris' ) )
		);
	}

	/**
	 * Builds a request and a prepared post, as the REST controller hands them to the filter.
	 *
	 * @param array<string, mixed> $post The fields of the post.
	 * @return array{object, ArrayObject}
	 */
	private function rest( array $post ) {
		return array( (object) $post, new ArrayObject( array() ) );
	}

	/**
	 * The filter of REST: a taken key is a 409 that says which, a title that the rules refuse a 400; a page with no title, or a page that
	 * keeps its own key, goes through; the parent and the title are those sent or those stored.
	 *
	 * @return void
	 */
	public function test_the_check_of_the_rest_filter(): void {
		$saving = new Saving( fn() => $this->pages );

		$this->store->add( 1, 'Biography', 0 );
		$this->store->add( 2, 'Paris', 1 );
		$this->store->add( 3, 'Lyon', 1, 'draft' );
		$this->store->add( 4, 'Opinion', 0 );
		$this->pages->sync( 1 );
		$this->pages->sync( 2 );
		$this->pages->sync( 3 );
		$this->pages->sync( 4 );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 0,
				'post_title' => 'Rome',
				'post_parent' => 1,
			)
		);
		$this->assertSame( $post, $saving->check_rest( $post, $request ), 'A free key.' );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 0,
				'post_title' => 'Paris',
				'post_parent' => 1,
			)
		);
		$error                  = $saving->check_rest( $post, $request );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'wiki_name_taken', $error->get_error_code() );
		$this->assertSame( 'A page of this title exists under this parent: Paris', $error->get_error_message() );
		$this->assertSame( 409, $error->get_error_data()['status'] );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 0,
				'post_title' => 'Paris',
				'post_parent' => 4,
			)
		);
		$this->assertSame( $post, $saving->check_rest( $post, $request ), 'The same title under another parent.' );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 0,
				'post_title' => 'a/b',
				'post_parent' => 1,
			)
		);
		$this->assertSame( array( 'wiki_name_slash', 400 ), array( $saving->check_rest( $post, $request )->get_error_code(), $saving->check_rest( $post, $request )->get_error_data()['status'] ) );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 0,
				'post_title' => '2026',
			)
		);
		$this->assertSame( 'wiki_name_digits', $saving->check_rest( $post, $request )->get_error_code(), 'No parent sent: the first level.' );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 0,
				'post_title' => '  ',
				'post_parent' => 1,
			)
		);
		$this->assertSame( $post, $saving->check_rest( $post, $request ), 'No title yet.' );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 3,
				'post_title' => 'Paris',
			)
		);
		$this->assertSame( 'wiki_name_taken', $saving->check_rest( $post, $request )->get_error_code(), 'A rename to a taken title, the parent from the page.' );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 2,
				'post_title' => 'Paris',
			)
		);
		$this->assertSame( $post, $saving->check_rest( $post, $request ), 'The key the page has.' );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 3,
				'post_parent' => 4,
			)
		);
		$this->assertSame( $post, $saving->check_rest( $post, $request ), 'A move: the title from the page.' );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 2,
				'post_parent' => 2,
			)
		);
		$this->assertSame( 'wiki_name_bad_parent', $saving->check_rest( $post, $request )->get_error_code() );

		$this->assertSame( 'x', $saving->check_rest( 'x', $request ), 'Not a post: left alone.' );
	}

	/**
	 * After a save: the key is claimed; a page that cannot have it goes back to draft with a notice, a draft stays a draft; nothing for what
	 * is not a page.
	 *
	 * @return void
	 */
	public function test_the_claim_after_a_save_through_the_hooks(): void {
		$saving = new Saving( fn() => $this->pages );

		$this->store->add( 1, 'Paris', 0 );
		$saving->after_insert( 1 );
		$this->assertSame( '0/Paris', $this->keys->current_of( 1 )['key'] );

		$this->store->add( 2, 'Paris', 0 );
		$saving->after_rest( new WP_Post( 2, 'page', 'Paris' ) );

		$this->assertSame( 'draft', $this->store->pages[2]['status'], 'Put back to draft.' );
		$this->assertSame( 'Another page has this name.', get_transient( FirstModelSaving::NOTICE . '1' ), 'The notice of the first model shows it.' );
		$this->assertNull( $this->keys->current_of( 2 ) );

		$this->store->add( 3, 'Rome', 0, 'draft' );
		$this->store->add( 4, 'Rome', 0, 'draft' );
		$saving->after_insert( 3 );
		$saving->after_insert( 4 );
		$this->assertSame( 'draft', $this->store->pages[4]['status'], 'A draft stays a draft.' );

		$saving->after_insert( 0 );
		$saving->after_insert( 999 );
		$saving->after_rest( 'not a post' );
		$this->assertSame( 1, $this->keys->resolve( 0, 'Paris' )['post'] );
	}

	/**
	 * The command makes a page under a parent given by its id or by the path of its titles, from a file, and says why when it cannot.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$lines = array();
		$fails = array();
		$files = array( '/tmp/page.html' => '<p>From a file</p>' );
		$bio   = $this->pages->create( 0, 'Biography', '', 'publish' );
		$book  = $this->pages->create( $bio, '1958-1968', '', 'publish' );

		$command = new TreeCommand(
			$this->pages,
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $line ) use ( &$lines ) {
				$lines[] = 'OK ' . $line;
			},
			static function ( $line ) use ( &$fails ) {
				$fails[] = $line;
			},
			static fn( $path ) => $files[ $path ] ?? false
		);

		$command->create(
			array(),
			array(
				'path' => 'Biography/1958-1968',
				'title' => 'Chapitre_un',
				'file' => '/tmp/page.html',
				'status' => 'publish',
			)
		);
		$command->create(
			array(),
			array(
				'parent' => (string) $bio,
				'title' => 'Paris',
			)
		);
		$command->create( array(), array( 'title' => 'Opinion' ) );

		$this->assertSame(
			array( 'OK Page 102 created: Biography/1958-1968/Chapitre un', 'OK Page 103 created: Biography/Paris', 'OK Page 104 created: Opinion' ),
			$lines
		);
		$this->assertSame( '<p>From a file</p>', $this->store->pages[102]['content'] );
		$this->assertSame( $book, $this->store->pages[102]['parent'] );
		$this->assertSame( 'publish', $this->store->pages[102]['status'] );

		$command->create(
			array(),
			array(
				'parent' => (string) $bio,
				'title' => 'paris',
			)
		);
		$command->create(
			array(),
			array(
				'parent' => (string) $bio,
				'title' => 'Paris',
			)
		);
		$command->create(
			array(),
			array(
				'path' => 'Biography/Nobody',
				'title' => 'x',
			)
		);
		$command->create(
			array(),
			array(
				'parent' => '999',
				'title' => 'x',
			)
		);
		$command->create(
			array(),
			array(
				'title' => 'x',
				'file' => '/tmp/missing.html',
			)
		);
		$command->create(
			array(),
			array(
				'title' => 'x',
				'status' => 'trash',
			)
		);
		$command->create(
			array(),
			array(
				'parent' => (string) $bio,
				'title' => 'a/b',
			)
		);

		$this->assertSame(
			array(
				'Another page has this name. (wiki_name_taken, page 103)',
				'The parent does not exist: Biography/Nobody (the last page that exists is ' . $bio . ')',
				'There is no such parent page. (wiki_name_unknown_parent)',
				'The file cannot be read: /tmp/missing.html',
				'Unknown status.',
				'A title has no slash: it separates the levels. (wiki_name_slash)',
			),
			$fails,
			'Each refusal says why; nothing is created.'
		);
		$this->assertArrayHasKey( 105, $this->store->pages, 'paris (lower case) was made.' );
		$this->assertCount( 6, $this->store->pages );
	}

	/**
	 * The rebuild makes the keys again from the posts and keeps the aliases; the pages that cannot have their key are listed.
	 *
	 * @return void
	 */
	public function test_the_rebuild_command(): void {
		$lines = array();

		$this->store->add( 1, 'Biography', 0 );
		$this->store->add( 2, 'Paris', 1 );
		$this->store->add( 3, 'Paris', 1 );
		$this->store->add( 4, '2026', 0 );
		$this->pages->sync( 1 );
		$this->pages->sync( 2 );

		$command = new TreeCommand(
			$this->pages,
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $line ) use ( &$lines ) {
				$lines[] = 'OK ' . $line;
			}
		);

		$command->rebuild( array(), array() );

		$this->assertSame(
			array(
				'Page 3 refused: wiki_name_taken, the key is held by page 2',
				'Page 4 refused: wiki_name_digits',
				'OK 2 key(s) made, 2 page(s) refused.',
			),
			$lines
		);
	}

	/**
	 * A new page starts with the template of its branch: set when the page is created, or at its first key when it comes from the editor;
	 * a page that has a template keeps it, and a template chosen later is not taken back.
	 *
	 * @return void
	 */
	public function test_a_new_page_starts_with_the_template_of_its_branch(): void {
		$stored = array();
		$rules  = new Rules(
			static function ( $id ) use ( &$stored ) {
				return $stored[ $id ] ?? '';
			},
			fn( $id ) => $this->store->pages[ $id ]['parent'] ?? 0
		);
		$pages  = new Pages( $this->keys, $this->store, $rules );
		$bio    = $pages->create( 0, 'Biography', '', 'publish' );

		$this->assertSame( '', $this->store->meta( $bio, '_wp_page_template' ), 'A first-level page has no branch above it.' );

		$stored[ $bio ] = array( 'template' => 'page-bio' );
		$one            = $pages->create( $bio, 'Paris', '', 'publish' );
		$deep           = $pages->create( $one, 'Rue', '', 'publish' );

		$this->assertSame( 'page-bio', $this->store->meta( $one, '_wp_page_template' ), 'Made by the service.' );
		$this->assertSame( 'page-bio', $this->store->meta( $deep, '_wp_page_template' ), 'The rule is inherited at any depth.' );

		$this->store->add( 50, 'Rome', $bio, 'publish' );
		$pages->sync( 50 );

		$this->assertSame( 'page-bio', $this->store->meta( 50, '_wp_page_template' ), 'Saved from the editor: at the first key.' );

		$this->store->add( 51, 'Nice', $bio, 'publish' );
		$this->store->set_meta( 51, '_wp_page_template', 'page-wide' );
		$pages->sync( 51 );

		$this->assertSame( 'page-wide', $this->store->meta( 51, '_wp_page_template' ), 'A template that the page has is kept.' );

		$this->store->set_meta( 50, '_wp_page_template', 'default' );
		$pages->sync( 50 );

		$this->assertSame( 'default', $this->store->meta( 50, '_wp_page_template' ), 'A later choice is not taken back: it is only said at the first key.' );
	}
}
