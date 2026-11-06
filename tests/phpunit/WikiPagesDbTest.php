<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the wiki pages (slice 502): the service, the checks before a save, the claim after it, the command.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\Ref;
use Otherguise\Wiki\Cli\PagesCommand;
use Otherguise\Wiki\Module as WikiModule;
use Otherguise\Wiki\NameException;
use Otherguise\Wiki\Names;
use Otherguise\Wiki\Page\PageStore;
use Otherguise\Wiki\Page\Pages;
use Otherguise\Wiki\Page\PostType;
use Otherguise\Wiki\Page\Saving;
use Otherguise\Wiki\Space\Spaces;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-page-store.php';

/**
 * The statements are real (MariaDB), the posts are an array.
 *
 * @covers \Otherguise\Wiki\Page\Pages
 * @covers \Otherguise\Wiki\Page\Saving
 * @covers \Otherguise\Wiki\Page\PostType
 * @covers \Otherguise\Wiki\Cli\PagesCommand
 */
class WikiPagesDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The posts.
	 *
	 * @var Otherguise_Test_Page_Store
	 */
	private $store;

	/**
	 * The pages.
	 *
	 * @var Pages
	 */
	private $pages;

	/**
	 * The names.
	 *
	 * @var Names
	 */
	private $names;

	/**
	 * Builds a site with the predicates of the names, three spaces (one has a template) and no page.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		otherguise_test_wp_objects( array() );
		update_option(
			Spaces::OPTION,
			array(
				array(
					'name' => 'Biography',
					'template' => 'single-wiki',
				),
				array( 'name' => 'Encyclopédie' ),
				array( 'name' => 'Opinion' ),
			)
		);

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
		$this->names = new Names(
			static function () use ( $statements ) {
				return $statements;
			}
		);
		$this->store = new Otherguise_Test_Page_Store();
		$this->pages = new Pages( $this->names, $this->store );
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
	 * A page is made with its name, its space and the template of its space, in the status asked.
	 *
	 * @return void
	 */
	public function test_creating_a_page(): void {
		$id = $this->pages->create( 'biography', 'maison_de mes grands-parents', '<p>Text</p>', 'publish' );

		$this->assertSame( 'Maison de mes grands-parents', $this->store->pages[ $id ]['title'], 'The title of the post is the title of the name.' );
		$this->assertSame( '<p>Text</p>', $this->store->pages[ $id ]['content'], 'Kept as it is.' );
		$this->assertSame( 'publish', $this->store->pages[ $id ]['status'] );
		$this->assertSame( 'Biography', $this->store->meta( $id, PageStore::SPACE_META ), 'The space as registered.' );
		$this->assertSame( 'single-wiki', $this->store->meta( $id, PageStore::TEMPLATE_META ), 'The template of the space.' );
		$this->assertSame( 'Biography:Maison de mes grands-parents', $this->names->current_of( $id )['qualified'] );
		$this->assertSame( 'Biography', $this->pages->space_of( $id ) );
		$this->assertSame( 'draft', $this->store->pages[ $this->pages->create( 'Opinion', 'Paris' ) ]['status'], 'A draft by default.' );
		$this->assertSame( '', $this->store->meta( $this->pages->create( 'Encyclopédie', 'Rome' ), PageStore::TEMPLATE_META ), 'A space with no template gives none.' );
	}

	/**
	 * The same title in another space is another page; the same name is refused and nothing is left behind.
	 *
	 * @return void
	 */
	public function test_a_taken_name_is_refused_and_nothing_is_left(): void {
		$first = $this->pages->create( 'Biography', 'Paris' );
		$other = $this->pages->create( 'Opinion', 'Paris' );

		$this->assertNotSame( $first, $other, 'The same title in another space is accepted.' );

		$this->assertNull( $this->refusal( fn() => $this->pages->create( 'Biography', 'PARIS' ) ), 'Only the first letter is made a capital: PARIS is another name.' );

		$count   = count( $this->store->pages );
		$refusal = $this->refusal( fn() => $this->pages->create( 'biography', 'paris' ) );

		$this->assertSame( NameException::TAKEN, $refusal->error_code() );
		$this->assertSame( $first, $refusal->holder() );
		$this->assertSame( $count, count( $this->store->pages ), 'No page is left behind.' );
		$this->assertSame( array(), $this->store->deleted, 'It was refused before the page was made.' );
	}

	/**
	 * What is refused before anything is made: an unknown space, a bad title, a bad status.
	 *
	 * @return void
	 */
	public function test_the_other_refusals_make_nothing(): void {
		$this->assertSame( NameException::UNKNOWN_SPACE, $this->refusal( fn() => $this->pages->create( 'Nowhere', 'Paris' ) )->error_code() );
		$this->assertSame( NameException::FORBIDDEN, $this->refusal( fn() => $this->pages->create( 'Biography', 'a|b' ) )->error_code() );

		$this->expectException( InvalidArgumentException::class );

		try {
			$this->pages->create( 'Biography', 'Paris', '', 'trash' );
		} finally {
			$this->assertSame( array(), $this->store->pages );
		}
	}

	/**
	 * A page whose name cannot be claimed after it was made is deleted again.
	 *
	 * @return void
	 */
	public function test_a_page_that_cannot_be_claimed_is_deleted(): void {
		// Another page holds the name as an alias that `resolve()` cannot see before the insert: made by a race, simulated here.
		$store   = new class() extends Otherguise_Test_Page_Store {
			/**
			 * The names to hold once the page is inserted.
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
		$other   = $store->add( 7, 'Elsewhere', 'Biography' ) ?? 7;
		$pages   = new Pages( $this->names, $store );
		$names   = $this->names;
		$store->after_insert = static function () use ( $names, $other ) {
			$names->claim( $other, 'Biography', 'Paris' );
		};

		$refusal = $this->refusal( fn() => $pages->create( 'Biography', 'Paris' ) );

		$this->assertSame( NameException::TAKEN, $refusal->error_code() );
		$this->assertSame( array( 100 ), $store->deleted, 'The page that was made is deleted again.' );
		$this->assertSame( $other, $this->names->resolve( 'Biography', 'Paris' )['post'] );
	}

	/**
	 * A saved page gets its name from its title and its space; a new title is a rename with an alias; what has no title, no space or is
	 * in the trash is left alone.
	 *
	 * @return void
	 */
	public function test_the_claim_after_a_save(): void {
		$this->store->add( 1, 'Paris', 'Biography' );
		$this->store->add( 2, '', 'Biography', 'draft' );
		$this->store->add( 3, 'Lyon', '', 'draft' );
		$this->store->add( 4, 'Rome', 'Biography', 'trash' );
		$this->store->add( 5, 'Nice', 'Biography', 'auto-draft' );
		$this->store->add( 6, 'Nantes', 'Biography', 'draft' );

		$this->assertSame( 'Biography:Paris', $this->pages->sync( 1 )['name'] );
		$this->assertNull( $this->pages->sync( 2 ), 'No title.' );
		$this->assertNull( $this->pages->sync( 3 ), 'No space.' );
		$this->assertNull( $this->pages->sync( 4 ), 'In the trash.' );
		$this->assertNull( $this->pages->sync( 5 ), 'An auto-draft.' );
		$this->assertNull( $this->pages->sync( 99 ), 'Not a page of the wiki.' );
		$this->assertSame( 'Biography:Nantes', $this->pages->sync( 6 )['name'], 'A draft has its name too.' );
		$this->assertNull( $this->pages->sync( 1 )['former'], 'Twice is the same.' );

		$this->store->pages[1]['title'] = 'Lutèce';
		$done                           = $this->pages->sync( 1 );

		$this->assertSame( 'Biography:Paris', $done['former'], 'A new title is a rename.' );
		$this->assertSame( array( 'Biography:Paris' ), $this->names->aliases_of( 1 ) );
		$this->assertSame( 'single-wiki', $this->store->meta( 1, PageStore::TEMPLATE_META ), 'The page starts with the template of its space.' );

		$this->store->pages[6]['title'] = 'Lutèce';

		$this->assertSame( NameException::TAKEN, $this->refusal( fn() => $this->pages->sync( 6 ) )->error_code() );
	}

	/**
	 * A page that already has a template keeps it.
	 *
	 * @return void
	 */
	public function test_an_existing_template_is_kept(): void {
		$this->store->add( 1, 'Paris', 'Biography' );
		$this->store->set_meta( 1, PageStore::TEMPLATE_META, 'my-template' );

		$this->pages->sync( 1 );

		$this->assertSame( 'my-template', $this->store->meta( 1, PageStore::TEMPLATE_META ) );
	}

	/**
	 * The check that the editor is told of before a save: free, the page's own name, taken, refused, unknown space.
	 *
	 * @return void
	 */
	public function test_the_check_before_a_save(): void {
		$this->store->add( 1, 'Paris', 'Biography' );
		$this->pages->sync( 1 );

		$this->assertNull( $this->pages->check( 0, 'Biography', 'Lyon' ) );
		$this->assertNull( $this->pages->check( 1, 'Biography', 'paris' ), 'The name the page has.' );
		$this->assertSame( NameException::TAKEN, $this->pages->check( 2, 'biography', 'Paris' )->error_code() );
		$this->assertSame( NameException::TAKEN, $this->pages->check( 0, 'Biography', 'Paris' )->error_code() );
		$this->assertSame( NameException::FORBIDDEN, $this->pages->check( 0, 'Biography', 'a#b' )->error_code() );
		$this->assertSame( NameException::UNKNOWN_SPACE, $this->pages->check( 0, 'Nowhere', 'Paris' )->error_code() );
		$this->assertSame( '', $this->pages->canonical_space( 'Nowhere' ) );
		$this->assertSame( 'Opinion', $this->pages->canonical_space( 'OPINION' ) );
	}

	/**
	 * Builds a request and a prepared post, as the REST controller hands them to the filter.
	 *
	 * @param array<string, mixed> $post The fields of the post.
	 * @param array<string, mixed> $meta The metas of the request.
	 * @return array{object, ArrayObject}
	 */
	private function rest( array $post, array $meta = array() ) {
		return array( (object) $post, new ArrayObject( array( 'meta' => $meta ) ) );
	}

	/**
	 * The filter of REST: a name that is taken is a 409 that says which, a bad title a 400, a page published with no space a 400, a change of
	 * space a 400; everything else goes through.
	 *
	 * @return void
	 */
	public function test_the_check_of_the_rest_filter(): void {
		$saving = new Saving( fn() => $this->pages );

		$this->store->add( 1, 'Paris', 'Biography' );
		$this->store->add( 2, 'Lyon', 'Biography', 'draft' );
		$this->pages->sync( 1 );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 0,
				'post_title' => 'Rome',
				'post_status' => 'publish',
			),
			array( 'wiki_space' => 'biography' )
		);
		$this->assertSame( $post, $saving->check_rest( $post, $request ), 'A free name.' );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 0,
				'post_title' => 'paris',
				'post_status' => 'publish',
			),
			array( 'wiki_space' => 'Biography' )
		);
		$error                  = $saving->check_rest( $post, $request );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'wiki_name_taken', $error->get_error_code() );
		$this->assertSame( 'A page of this name exists in this space: paris', $error->get_error_message() );
		$this->assertSame( 409, $error->get_error_data()['status'] );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 0,
				'post_title' => 'a|b',
				'post_status' => 'draft',
			),
			array( 'wiki_space' => 'Biography' )
		);
		$this->assertSame( 400, $saving->check_rest( $post, $request )->get_error_data()['status'] );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 0,
				'post_title' => 'Rome',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'wiki_no_space', $saving->check_rest( $post, $request )->get_error_code(), 'Published with no space.' );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 0,
				'post_title' => 'Rome',
				'post_status' => 'draft',
			)
		);
		$this->assertSame( $post, $saving->check_rest( $post, $request ), 'A draft with no space goes through.' );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 2,
				'post_title' => 'Paris',
			)
		);
		$this->assertSame( 'wiki_name_taken', $saving->check_rest( $post, $request )->get_error_code(), 'A rename to a taken name, the space from the page.' );

		list( $post, $request ) = $this->rest(
			array(
				'ID' => 1,
				'post_title' => 'Paris',
			)
		);
		$this->assertSame( $post, $saving->check_rest( $post, $request ), 'The name the page has.' );

		list( $post, $request ) = $this->rest( array( 'ID' => 1 ), array( 'wiki_space' => 'Opinion' ) );
		$this->assertSame( 'wiki_space_locked', $saving->check_rest( $post, $request )->get_error_code() );

		list( $post, $request ) = $this->rest( array( 'ID' => 1 ), array( 'wiki_space' => 'BIOGRAPHY' ) );
		$this->assertSame( $post, $saving->check_rest( $post, $request ), 'The same space in another case is the same space.' );

		$this->assertSame( 'x', $saving->check_rest( 'x', $request ), 'Not a post: left alone.' );
	}

	/**
	 * After a save: the name is claimed; a page that cannot have it goes back to draft with a notice, a draft stays a draft; nothing for the
	 * posts that are not pages.
	 *
	 * @return void
	 */
	public function test_the_claim_after_a_save_through_the_hooks(): void {
		$saving = new Saving( fn() => $this->pages );

		$this->store->add( 1, 'Paris', 'Biography' );
		$saving->after_insert( 1 );
		$this->assertSame( 'Biography:Paris', $this->names->current_of( 1 )['qualified'] );

		$this->store->add( 2, 'Paris', 'Biography' );
		$saving->after_rest( new WP_Post( 2, PageStore::TYPE, 'Paris' ) );

		$this->assertSame( 'draft', $this->store->pages[2]['status'], 'Put back to draft.' );
		$this->assertSame( 'Another page has this name.', get_transient( Saving::NOTICE . '1' ) );
		$this->assertNull( $this->names->current_of( 2 ) );

		ob_start();
		$saving->notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Another page has this name.', $html );
		$this->assertFalse( get_transient( Saving::NOTICE . '1' ), 'Shown once.' );

		ob_start();
		$saving->notice();
		$this->assertSame( '', ob_get_clean() );

		$saving->after_insert( 0 );
		$saving->after_insert( 999 );
		$saving->after_rest( 'not a post' );
		$this->assertSame( array( 1 ), $this->names->pages_of( 'Biography' ) );
	}

	/**
	 * The type and its meta are registered as the slice says: no rewrite, the REST flag, the capabilities of the pages, the supports of the
	 * Footnotes block; the meta cannot be changed once set.
	 *
	 * @return void
	 */
	public function test_the_type_and_the_meta(): void {
		$type = new PostType();

		$type->register();

		$calls = $GLOBALS['otherguise_test_calls'];
		$post  = array_values( array_filter( $calls, static fn( $call ) => 'register_post_type' === $call[0] ) )[0];
		$meta  = array_values( array_filter( $calls, static fn( $call ) => 'register_post_meta' === $call[0] ) )[0];

		$this->assertSame( 'wiki_page', $post[1] );
		$this->assertFalse( $post[2]['rewrite'] );
		$this->assertTrue( $post[2]['show_in_rest'] );
		$this->assertTrue( $post[2]['public'] );
		$this->assertFalse( $post[2]['hierarchical'] );
		$this->assertFalse( $post[2]['has_archive'] );
		$this->assertSame( 'page', $post[2]['capability_type'] );
		$this->assertSame( array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ), $post[2]['supports'] );
		$this->assertSame( array( 'wiki_page', 'wiki_space' ), array( $meta[1], $meta[2] ) );
		$this->assertTrue( $meta[3]['show_in_rest'] );
		$this->assertSame( array( $type, 'sanitize_space' ), $meta[3]['sanitize_callback'] );

		$GLOBALS['otherguise_test_filters']['wiki_capability_type'] = 'post';
		$GLOBALS['otherguise_test_calls']                           = array();
		$type->register();
		$this->assertSame( 'post', $GLOBALS['otherguise_test_calls'][0][2]['capability_type'] );

		$this->assertSame( 'Biography', $type->sanitize_space( 'biography' ) );
		$this->assertSame( '', $type->sanitize_space( 'Nowhere' ) );
		$this->assertSame( '', $type->sanitize_space( null ) );
	}

	/**
	 * The space is set once: a change or a removal is refused, the same value is accepted, another meta is left alone.
	 *
	 * @return void
	 */
	public function test_the_space_cannot_be_changed(): void {
		$type = new PostType();

		$this->assertNull( $type->lock_space( null, 5, 'wiki_space', 'Biography' ), 'No space yet: the first choice goes through.' );
		$this->assertNull( $type->lock_space( null, 5, 'other_meta', 'x' ) );
		$this->assertTrue( $type->lock_space( true, 5, 'wiki_space', 'x' ), 'What another filter decided stays.' );

		update_post_meta( 5, 'wiki_space', 'Biography' );

		$this->assertFalse( $type->lock_space( null, 5, 'wiki_space', 'Opinion' ), 'Changed: refused.' );
		$this->assertFalse( $type->lock_space( null, 5, 'wiki_space', '' ), 'Removed: refused.' );
		$this->assertNull( $type->lock_space( null, 5, 'wiki_space', 'biography' ), 'The same space.' );
		$this->assertNull( $type->lock_space( null, 5, 'other_meta', 'x' ) );
	}

	/**
	 * The command makes a page from a file, and says why when it cannot.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$lines = array();
		$log   = static function ( $line ) use ( &$lines ) {
			$lines[] = $line;
		};
		$files = array( '/tmp/page.html' => '<p>From a file</p>' );
		$fails = array();

		$command = new PagesCommand(
			$this->pages,
			$log,
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
				'space' => 'biography',
				'title' => 'Paris',
				'file' => '/tmp/page.html',
				'status' => 'publish',
			)
		);

		$this->assertSame( array( 'OK Page 100 created: Biography:Paris' ), $lines );
		$this->assertSame( '<p>From a file</p>', $this->store->pages[100]['content'] );
		$this->assertSame( 'publish', $this->store->pages[100]['status'] );

		$command->create(
			array(),
			array(
				'space' => 'Biography',
				'title' => 'paris',
			)
		);
		$command->create(
			array(),
			array(
				'space' => 'Nowhere',
				'title' => 'Rome',
			)
		);
		$command->create(
			array(),
			array(
				'space' => 'Biography',
				'title' => 'Rome',
				'file' => '/tmp/missing.html',
			)
		);
		$command->create(
			array(),
			array(
				'space' => 'Biography',
				'title' => 'Rome',
				'status' => 'trash',
			)
		);
		$command->create( array(), array() );

		$this->assertSame(
			array(
				'Another page has this name. (wiki_name_taken, page 100)',
				'There is no such space. (wiki_name_unknown_space)',
				'The file cannot be read: /tmp/missing.html',
				'Unknown status.',
				'There is no such space. (wiki_name_unknown_space)',
			),
			array_slice( $fails, 0, 5 ),
			'Each refusal says why; nothing is created.'
		);
		$this->assertCount( 1, $this->store->pages );
	}
}
