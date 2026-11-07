<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the routing of the tree (slice 503), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Module as WikiModule;
use Otherguise\Wiki\Tree\Keys;
use Otherguise\Wiki\Tree\Router;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';

/**
 * The statements are real (MariaDB); WordPress is a double.
 *
 * @covers \Otherguise\Wiki\Tree\Router
 */
class WikiTreeRouterDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The index.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * The address asked, with its query string.
	 *
	 * @var string
	 */
	private $uri = '/';

	/**
	 * The redirects made: address and status.
	 *
	 * @var array<int, array{string, int}>
	 */
	private $redirects = array();

	/**
	 * The slugs that WordPress finds: path => id.
	 *
	 * @var array<string, int>
	 */
	private $slugs = array();

	/**
	 * Whether the user may create pages.
	 *
	 * @var bool
	 */
	private $may = false;

	/**
	 * The slugs that WordPress finds as a post or a page, whatever the type: name => true.
	 *
	 * @var array<string, bool>
	 */
	private $found = array();

	/**
	 * The static front page.
	 *
	 * @var int
	 */
	private $front = 0;

	/**
	 * Builds the tree: Biography (1) > Paris (2, once Lutèce) > Rue (6); Opinion (3) > Paris (4); Accueil (5).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$pages = array();

		for ( $id = 1; $id <= 7; $id++ ) {
			$pages[] = new WP_Post( $id );
		}

		otherguise_test_wp_objects( $pages );

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
			}
		);

		$this->keys->claim( 1, 0, 'Biography' );
		$this->keys->claim( 2, 1, 'Lutèce' );
		$this->keys->claim( 2, 1, 'Paris' );
		$this->keys->claim( 3, 0, 'Opinion' );
		$this->keys->claim( 4, 3, 'Paris' );
		$this->keys->claim( 5, 0, 'Accueil' );
		$this->keys->claim( 6, 2, 'Rue (Saint-Just): la tour' );
		$this->slugs = array(
			'biography'       => 1,
			'biography/paris' => 2,
			'about'           => 7,
		);
	}

	/**
	 * The router, with the request and the redirects of the test.
	 *
	 * @param bool $pretty Whether the site has pretty permalinks.
	 * @return Router
	 */
	private function router( $pretty = true ): Router {
		return new Router(
			$this->keys,
			fn() => $this->uri,
			function ( $url, $status ) {
				$this->redirects[] = array( $url, $status );
			},
			static fn( $path ) => 'https://example.test' . $path,
			fn() => $pretty,
			fn( $path ) => $this->slugs[ $path ] ?? 0,
			fn() => $this->front,
			fn() => $this->may,
			fn( $vars ) => isset( $vars['name'] ) && isset( $this->found[ $vars['name'] ] )
		);
	}

	/**
	 * The `WP` object as WordPress leaves it.
	 *
	 * @param string               $request The path without the slashes at the ends.
	 * @param array<string, mixed> $vars    The query variables.
	 * @return stdClass
	 */
	private function wp( $request, array $vars ) {
		return (object) array(
			'request'    => $request,
			'query_vars' => $vars,
		);
	}

	/**
	 * A page at its exact address is served as it is, at any depth, with no redirect.
	 *
	 * @return void
	 */
	public function test_a_page_is_served_at_its_address(): void {
		$this->uri = '/Biography/Paris';
		$wp        = $this->wp( 'Biography/Paris', array( 'attachment' => 'Paris' ) );
		$router    = $this->router();

		$router->parse_request( $wp );

		$this->assertSame( array( 'page_id' => 2 ), $wp->query_vars );
		$this->assertSame( array(), $this->redirects );
		$this->assertFalse( $router->redirect_canonical( 'https://example.test/Biography/Paris/', 'x' ) );

		$this->uri = '/Opinion/Paris';
		$wp        = $this->wp( 'Opinion/Paris', array( 'pagename' => 'Opinion/Paris' ) );
		$router->parse_request( $wp );
		$this->assertSame( array( 'page_id' => 4 ), $wp->query_vars );

		$this->uri = '/Biography/Paris/Rue_(Saint-Just):_la_tour';
		$wp        = $this->wp( 'Biography/Paris/Rue_(Saint-Just):_la_tour', array() + array( 'error' => '404' ) );
		$router->parse_request( $wp );
		$this->assertSame( array( 'page_id' => 6 ), $wp->query_vars );
	}

	/**
	 * The preview of a draft is its address with `?preview=true`: it is resolved like the address, the variable is kept for WordPress, and the
	 * old routes of the previews (`p` or `page_id` with `preview`) are still left alone.
	 *
	 * @return void
	 */
	public function test_a_preview_is_resolved_like_the_address(): void {
		$this->uri = '/Biography/Paris?preview=true';
		$wp        = $this->wp(
			'Biography/Paris',
			array(
				'attachment' => 'Paris',
				'preview'    => 'true',
			)
		);

		$this->router()->parse_request( $wp );

		$this->assertSame(
			array(
				'preview' => 'true',
				'page_id' => 2,
			),
			$wp->query_vars
		);
		$this->assertSame( array(), $this->redirects );

		$wp = $this->wp(
			'',
			array(
				'page_id' => '2',
				'preview' => 'true',
			)
		);
		$this->router()->parse_request( $wp );
		$this->assertSame(
			array(
				'page_id' => '2',
				'preview' => 'true',
			),
			$wp->query_vars
		);
	}

	/**
	 * Another query variable of the request is kept; a percent-encoded request (nginx) is decoded.
	 *
	 * @return void
	 */
	public function test_encoded_requests_and_other_variables(): void {
		$this->keys->claim( 7, 1, 'Ansò' );

		$this->uri = '/Biography/Ans%C3%B2?mode=print';
		$wp        = $this->wp( 'Biography/Ans%C3%B2', array( 'attachment' => 'x' ) );
		$this->router()->parse_request( $wp );

		$this->assertSame( array( 'page_id' => 7 ), $wp->query_vars );
		$this->assertSame( array(), $this->redirects );
	}

	/**
	 * An alias, a trailing slash and a lower-case encoding are a 301 to the canonical address, with the query string kept.
	 *
	 * @return void
	 */
	public function test_other_forms_are_redirected(): void {
		$this->uri = '/Biography/Lut%C3%A8ce?mode=print';
		$wp        = $this->wp( 'Biography/Lut%C3%A8ce', array( 'attachment' => 'x' ) );
		$this->router()->parse_request( $wp );

		$this->assertSame( array( array( 'https://example.test/Biography/Paris?mode=print', 301 ) ), $this->redirects );

		$this->redirects = array();
		$this->uri       = '/Biography/Paris/';
		$this->router()->parse_request( $this->wp( 'Biography/Paris', array( 'attachment' => 'x' ) ) );
		$this->assertSame( array( array( 'https://example.test/Biography/Paris', 301 ) ), $this->redirects );

		$this->redirects = array();
		$this->keys->claim( 7, 1, 'Ansò' );
		$this->uri = '/Biography/Ans%c3%b2';
		$this->router()->parse_request( $this->wp( 'Biography/Ans%c3%b2', array( 'attachment' => 'x' ) ) );
		$this->assertSame( array( array( 'https://example.test/Biography/Ans%C3%B2', 301 ) ), $this->redirects );
	}

	/**
	 * Case is not folded: `paris` is not `Paris`; the path stops where it stops, and WordPress is not asked for the slug.
	 *
	 * @return void
	 */
	public function test_a_wrong_case_is_a_404_that_knows_where_it_stopped(): void {
		$this->uri = '/Biography/paris';
		$wp        = $this->wp( 'Biography/paris', array( 'attachment' => 'paris' ) );
		$router    = $this->router();

		$router->parse_request( $wp );

		$this->assertSame( array( 'error' => '404' ), $wp->query_vars );
		$this->assertSame(
			array(
				'deepest' => 1,
				'rest'    => array( 'paris' ),
			),
			$router->missing()
		);
		$this->assertFalse( $router->redirect_canonical( 'guess', 'x' ) );
	}

	/**
	 * There is no slug door: the address of the slugs of a page of the tree is a 404, while a page that is not in the tree is left to
	 * WordPress.
	 *
	 * @return void
	 */
	public function test_no_slug_door(): void {
		$router = $this->router();
		$wp     = $this->wp( 'biography/paris', array( 'pagename' => 'biography/paris' ) );

		$router->parse_request( $wp );

		$this->assertSame( array( 'error' => '404' ), $wp->query_vars );
		$this->assertSame( 0, $router->missing()['deepest'] );

		$wp = $this->wp( 'about', array( 'pagename' => 'about' ) );
		$router->parse_request( $wp );

		$this->assertSame( array( 'pagename' => 'about' ), $wp->query_vars );
		$this->assertNull( $router->missing() );
		$this->assertSame( 'guess', $router->redirect_canonical( 'guess', 'x' ) );
	}

	/**
	 * The specific routes of WordPress, the front page and what does not look like a page are never touched.
	 *
	 * @return void
	 */
	public function test_other_routes_are_left_alone(): void {
		$router = $this->router();

		foreach ( array(
			array( 'feed', array( 'feed' => 'feed' ) ),
			array(
				'Biography/Paris/feed',
				array(
					'pagename' => 'Biography/Paris',
					'feed' => 'feed',
				),
			),
			array( 'search/Paris', array( 's' => 'Paris' ) ),
			array( '2026', array( 'year' => '2026' ) ),
			array( 'category/x', array( 'category_name' => 'x' ) ),
			array( '', array() ),
		) as $case ) {
			$wp = $this->wp( $case[0], $case[1] );
			$router->parse_request( $wp );
			$this->assertSame( $case[1], $wp->query_vars, $case[0] );
		}

		$router->parse_request( 'not an object' );
		$this->assertSame( array(), $this->redirects );
	}

	/**
	 * An unknown path that does not enter the tree is left to WordPress.
	 *
	 * @return void
	 */
	public function test_an_unknown_path_is_left_to_wordpress(): void {
		$router = $this->router();
		$wp     = $this->wp( 'hello-world', array( 'name' => 'hello-world' ) );

		$router->parse_request( $wp );

		$this->assertSame( array( 'name' => 'hello-world' ), $wp->query_vars );

		$wp = $this->wp( 'biography/whatever', array( 'attachment' => 'whatever' ) );
		$router->parse_request( $wp );

		$this->assertSame( array( 'error' => '404' ), $wp->query_vars );
		$this->assertSame( 0, $router->missing()['deepest'] );

		$wp = $this->wp( 'Nowhere/Paris', array( 'error' => '404' ) );
		$router->parse_request( $wp );

		$this->assertSame( array( 'error' => '404' ), $wp->query_vars );
		$this->assertNull( $router->missing() );
	}

	/**
	 * The guess of WordPress for a 404 never lands on a page of the tree (no slug door by the guess).
	 *
	 * @return void
	 */
	public function test_the_guess_of_a_404_skips_the_tree(): void {
		$router = $this->router();

		$this->assertFalse( $router->redirect_canonical( 'https://example.test/Biography/Paris', 'https://example.test/Nowhere/Paris' ) );
		$this->assertSame( 'https://example.test/hello-world/', $router->redirect_canonical( 'https://example.test/hello-world/', 'https://example.test/hello-world' ) );
		$this->assertFalse( $router->redirect_canonical( false, 'x' ) );
	}

	/**
	 * A user who may create pages gets the offer for a path whose first title nothing has, unless WordPress finds something there or the
	 * path looks like a file; a visitor keeps the plain 404.
	 *
	 * @return void
	 */
	public function test_a_user_who_may_create_gets_the_offer_for_an_unknown_first_title(): void {
		$router = $this->router();
		$wp     = $this->wp( 'Encyclopédie/XML', array( 'attachment' => 'XML' ) );

		$router->parse_request( $wp );
		$this->assertSame( array( 'attachment' => 'XML' ), $wp->query_vars );
		$this->assertNull( $router->missing() );

		$this->may = true;
		$wp        = $this->wp( 'Encyclopédie/XML', array( 'attachment' => 'XML' ) );

		$router->parse_request( $wp );
		$this->assertSame( array( 'error' => '404' ), $wp->query_vars );
		$this->assertSame(
			array(
				'deepest' => 0,
				'rest'    => array( 'Encyclopédie', 'XML' ),
			),
			$router->missing()
		);
		$this->assertFalse( $router->redirect_canonical( 'guess', 'x' ) );

		// Three levels: no variable but the error.
		$wp = $this->wp( 'Encyclopédie/XML/Schema', array( 'error' => '404' ) );
		$router->parse_request( $wp );
		$this->assertSame( 3, count( $router->missing()['rest'] ) );

		// A post of the blog is left alone, a file too.
		$this->found = array( 'hello-world' => true );
		$wp          = $this->wp( 'hello-world', array( 'name' => 'hello-world' ) );
		$router->parse_request( $wp );
		$this->assertSame( array( 'name' => 'hello-world' ), $wp->query_vars );
		$this->assertNull( $router->missing() );

		$wp = $this->wp( 'apple-touch-icon.png', array( 'name' => 'apple-touch-icon.png' ) );
		$router->parse_request( $wp );
		$this->assertSame( array( 'name' => 'apple-touch-icon.png' ), $wp->query_vars );
	}

	/**
	 * The permalink is the address; the front page and a page with no key keep what WordPress made; plain permalinks change nothing.
	 *
	 * @return void
	 */
	public function test_the_permalink(): void {
		$router = $this->router();

		$this->assertSame( 'https://example.test/Biography/Paris', $router->page_link( 'https://old/?page_id=2', 2 ) );
		$this->assertSame( 'https://example.test/Biography/Paris/Rue_(Saint-Just):_la_tour', $router->page_link( 'x', 6 ) );
		$this->assertSame( 'x', $router->page_link( 'x', 99 ) );
		$this->assertSame( 'x', $router->page_link( 'x', 0 ) );
		$this->assertSame( 'x', $this->router( false )->page_link( 'x', 2 ) );

		$this->front = 5;
		$this->assertSame( 'https://old/', $router->page_link( 'https://old/', 5 ) );
	}

	/**
	 * The own address of the front page is served as any other.
	 *
	 * @return void
	 */
	public function test_the_front_page_is_served_at_its_own_address(): void {
		$this->front = 5;
		$this->uri   = '/Accueil';
		$wp          = $this->wp( 'Accueil', array( 'name' => 'Accueil' ) );

		$this->router()->parse_request( $wp );

		$this->assertSame( array( 'page_id' => 5 ), $wp->query_vars );
		$this->assertSame( array(), $this->redirects );
	}
}
