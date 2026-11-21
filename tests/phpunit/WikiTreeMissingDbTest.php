<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the page that does not exist (slice 504, hierarchical model), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Missing\CreateAction;
use Otherguise\Wiki\Missing\MissingPage;
use Otherguise\Wiki\Module as WikiModule;
use Otherguise\Wiki\Tree\Keys;
use Otherguise\Wiki\Tree\Pages;
use Otherguise\Wiki\Tree\Router;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-node-store.php';

/**
 * The statements are real (MariaDB), the posts are an array.
 *
 * @covers \Otherguise\Wiki\Missing\MissingPage
 * @covers \Otherguise\Wiki\Missing\CreateAction
 */
class WikiTreeMissingDbTest extends Otherguise_Test_Database_Case {

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
	 * The router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * The ids of the pages made by setUp: bio, par, rue, op, opp, anso.
	 *
	 * @var array<string, int>
	 */
	private $id = array();

	/**
	 * Whether the user may create.
	 *
	 * @var bool
	 */
	private $may = true;

	/**
	 * What the form posts.
	 *
	 * @var array<string, string>
	 */
	private $posted = array();

	/**
	 * What the action did: redirects and stops.
	 *
	 * @var array<int, array{string, mixed}>
	 */
	private $done = array();

	/**
	 * Builds the tree: Biography (1) > Paris (2) > Rue (3); Opinion (4) > Paris (5); Ansò (6) under Biography.
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
		$this->router = new Router( $this->keys, fn() => '/', fn() => null, static fn( $path ) => 'https://example.test' . $path );

		$bio = $this->pages->create( 0, 'Biography', '', 'publish' );
		$par = $this->pages->create( $bio, 'Paris', '', 'publish' );
		$rue = $this->pages->create( $par, 'Rue', '', 'publish' );
		$op  = $this->pages->create( 0, 'Opinion', '', 'publish' );
		$opp = $this->pages->create( $op, 'Paris', '', 'publish' );
		$ans = $this->pages->create( $bio, 'Ansò', '', 'publish' );

		$this->id = array(
			'bio'  => $bio,
			'par'  => $par,
			'rue'  => $rue,
			'op'   => $op,
			'opp'  => $opp,
			'anso' => $ans,
		);
	}

	/**
	 * The page that does not exist, with the doubles of the test.
	 *
	 * @return MissingPage
	 */
	private function missing(): MissingPage {
		return new MissingPage(
			$this->keys,
			$this->router,
			static fn() => true,
			fn() => $this->may,
			static fn() => true,
			static fn( $action ) => 'nonce:' . $action,
			static fn() => 'https://example.test/wp-admin/admin-post.php'
		);
	}

	/**
	 * The action, with the doubles of the test.
	 *
	 * @return CreateAction
	 */
	private function action(): CreateAction {
		return new CreateAction(
			$this->pages,
			$this->missing(),
			fn( $name ) => $this->posted[ $name ] ?? null,
			static fn( $nonce, $action ) => 'nonce:' . $action === $nonce,
			function ( $url ) {
				$this->done[] = array( 'redirect', $url );
			},
			function ( $message, $status ) {
				$this->done[] = array( 'stop', $status );
			},
			static fn( $id ) => 'editor/' . $id,
			static fn( $id ) => 'page/' . $id
		);
	}

	/**
	 * What the form would post for a chain.
	 *
	 * @param int      $parent  The parent.
	 * @param string[] $titles  The titles.
	 * @param bool     $confirm Whether the box is ticked.
	 * @return void
	 */
	private function post( $parent, array $titles, $confirm = false ): void {
		$this->posted = array(
			'wiki_parent' => (string) $parent,
			'wiki_titles' => implode( '/', $titles ),
			'_wpnonce'    => 'nonce:' . MissingPage::nonce_action( $parent, $titles ),
		);

		if ( $confirm ) {
			$this->posted['wiki_confirm'] = '1';
		}
	}

	/**
	 * One title missing below an existing page: the plain offer, with the same title elsewhere.
	 *
	 * @return void
	 */
	public function test_one_missing_title_offers_the_page(): void {
		$view = $this->missing()->view(
			array(
				'deepest' => $this->id['bio'],
				'rest'    => array( 'Paris2' ),
			)
		);

		$this->assertSame( '/Biography/Paris2', $view['path'] );
		$this->assertTrue( $view['may_create'] );
		$this->assertFalse( $view['chain'] );

		$html = $this->missing()->render(
			array(
				'deepest' => $this->id['bio'],
				'rest'    => array( 'Paris2' ),
			)
		);

		$this->assertStringContainsString( 'Create this page', $html );
		$this->assertStringContainsString( 'name="wiki_parent" value="' . $this->id['bio'] . '"', $html );
		$this->assertStringContainsString( 'nonce:wiki_create:' . $this->id['bio'] . ':Paris2', $html );
		$this->assertStringNotContainsString( 'wiki_confirm', $html );
	}

	/**
	 * Approaching paths: an accent or a case that differs at any level, and the same last title elsewhere.
	 *
	 * @return void
	 */
	public function test_approaching_paths_and_angles(): void {
		$view = $this->missing()->view(
			array(
				'deepest' => 0,
				'rest'    => array( 'biography', 'paris' ),
			)
		);

		$this->assertSame( array( '/Biography/Paris' ), array_column( $view['approaching'], 'name' ) );
		$this->assertSame( 'https://example.test/Biography/Paris', $view['approaching'][0]['url'] );

		$view = $this->missing()->view(
			array(
				'deepest' => 0,
				'rest'    => array( 'Nowhere', 'Paris' ),
			)
		);

		$this->assertSame( array( '/Biography/Paris', '/Opinion/Paris' ), array_column( $view['angles'], 'name' ) );

		$view = $this->missing()->view(
			array(
				'deepest' => $this->id['bio'],
				'rest'    => array( 'Anso' ),
			)
		);

		$this->assertSame( array( '/Biography/Ansò' ), array_column( $view['approaching'], 'name' ) );
	}

	/**
	 * Several missing titles: the chain is listed and the form asks for a confirmation.
	 *
	 * @return void
	 */
	public function test_a_chain_asks_for_confirmation(): void {
		$html = $this->missing()->render(
			array(
				'deepest' => $this->id['bio'],
				'rest'    => array( 'Nobody', 'Deeper' ),
			)
		);

		$this->assertStringContainsString( 'Create these pages', $html );
		$this->assertStringContainsString( '<li>Nobody</li><li>Deeper</li>', $html );
		$this->assertStringContainsString( 'name="wiki_confirm"', $html );
		$this->assertStringContainsString( 'value="Nobody/Deeper"', $html );
	}

	/**
	 * Nobody who may not create is offered a form; a title that is refused is said, with no form; a path that is too deep too.
	 *
	 * @return void
	 */
	public function test_no_form_without_the_right_or_for_a_refused_title(): void {
		$this->may = false;
		$html      = $this->missing()->render(
			array(
				'deepest' => $this->id['bio'],
				'rest'    => array( 'Paris2' ),
			)
		);

		$this->assertStringNotContainsString( '<form', $html );

		$this->may = true;
		$html      = $this->missing()->render(
			array(
				'deepest' => 0,
				'rest'    => array( 'feed' ),
			)
		);

		$this->assertStringContainsString( 'wiki-missing__refusal', $html );
		$this->assertStringNotContainsString( '<form', $html );

		$view = $this->missing()->view(
			array(
				'deepest' => 0,
				'rest'    => array_fill( 0, 11, 'x' ),
			)
		);

		$this->assertNotSame( '', $view['refusal'] );
		$this->assertSame( '', $this->missing()->render( null ) );
	}

	/**
	 * One title is created as a draft below the parent and the user lands in its editor.
	 *
	 * @return void
	 */
	public function test_the_action_creates_one_draft(): void {
		$this->post( $this->id['bio'], array( 'Nouvelle' ) );
		$this->action()->handle();

		$found = $this->keys->resolve( $this->id['bio'], 'Nouvelle' );

		$this->assertNotNull( $found );
		$this->assertSame( array( array( 'redirect', 'editor/' . $found['post'] ) ), $this->done );
		$this->assertSame( 'draft', $this->store->get( $found['post'] )['status'] );
	}

	/**
	 * A chain is created only with the confirmation, one under the other.
	 *
	 * @return void
	 */
	public function test_a_chain_is_created_with_the_confirmation_only(): void {
		$this->post( $this->id['bio'], array( 'Nobody', 'Deeper' ) );
		$this->action()->handle();

		$this->assertSame( array( array( 'stop', 400 ) ), $this->done );
		$this->assertNull( $this->keys->resolve( $this->id['bio'], 'Nobody' ) );

		$this->done = array();
		$this->post( $this->id['bio'], array( 'Nobody', 'Deeper' ), true );
		$this->action()->handle();

		$first  = $this->keys->resolve( $this->id['bio'], 'Nobody' );
		$second = $this->keys->resolve( $first['post'], 'Deeper' );

		$this->assertNotNull( $second );
		$this->assertSame( array( array( 'redirect', 'editor/' . $second['post'] ) ), $this->done );
	}

	/**
	 * A title taken in the meantime: the chain goes on under its holder; the last one taken sends the user to it.
	 *
	 * @return void
	 */
	public function test_a_taken_title_goes_on_under_its_holder(): void {
		$this->post( 0, array( 'Biography', 'Nouveau' ) );
		$this->posted['wiki_confirm'] = '1';
		$this->action()->handle();

		$this->assertNotNull( $this->keys->resolve( $this->id['bio'], 'Nouveau' ) );

		$this->done = array();
		$this->post( 0, array( 'Biography', 'Paris' ), true );
		$this->action()->handle();

		$this->assertSame( array( array( 'redirect', 'page/' . $this->id['par'] ) ), $this->done );
	}

	/**
	 * The server decides: a bad nonce, no right, a refused title, nothing to create, too many titles.
	 *
	 * @return void
	 */
	public function test_the_action_refuses(): void {
		$this->post( $this->id['bio'], array( 'Nouvelle' ) );
		$this->posted['_wpnonce'] = 'wrong';
		$this->action()->handle();
		$this->assertSame( array( array( 'stop', 403 ) ), $this->done );

		$this->done = array();
		$this->may  = false;
		$this->post( $this->id['bio'], array( 'Nouvelle' ) );
		$this->action()->handle();
		$this->assertSame( array( array( 'stop', 403 ) ), $this->done );

		$this->done = array();
		$this->may  = true;
		$this->post( 0, array( 'feed' ) );
		$this->action()->handle();
		$this->assertSame( array( array( 'stop', 400 ) ), $this->done );

		$this->done = array();
		$this->post( $this->id['bio'], array() );
		$this->action()->handle();
		$this->assertSame( array( array( 'stop', 400 ) ), $this->done );

		$this->done = array();
		$this->post( $this->id['bio'], array_fill( 0, 11, 'x' ), true );
		$this->action()->handle();
		$this->assertSame( array( array( 'stop', 400 ) ), $this->done );

		$this->done = array();
		$this->post( 999, array( 'Orphan' ) );
		$this->action()->handle();
		$this->assertSame( array( array( 'stop', 400 ) ), $this->done );
	}

	/**
	 * What the router remembers is what the page shows: the 404 of the request only.
	 *
	 * @return void
	 */
	public function test_asked_comes_from_the_router(): void {
		$this->assertNull( $this->missing()->asked() );

		$wp = (object) array(
			'request'    => 'Biography/Nowhere',
			'query_vars' => array( 'attachment' => 'Nowhere' ),
		);

		$this->router->parse_request( $wp );

		$this->assertSame(
			array(
				'deepest' => $this->id['bio'],
				'rest'    => array( 'Nowhere' ),
			),
			$this->missing()->asked()
		);
	}
}
