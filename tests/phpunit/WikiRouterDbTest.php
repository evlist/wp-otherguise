<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the routing of the addresses (slice 503), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Address\Router;
use Otherguise\Wiki\Module as WikiModule;
use Otherguise\Wiki\Names;
use Otherguise\Wiki\Space\Spaces;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';

/**
 * The statements are real (MariaDB); WordPress is a double.
 *
 * @covers \Otherguise\Wiki\Address\Router
 */
class WikiRouterDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The names.
	 *
	 * @var Names
	 */
	private $names;

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
	 * Whether the site has pretty permalinks.
	 *
	 * @var bool
	 */
	private $pretty = true;

	/**
	 * Builds a site with three spaces and three pages: Biography:Paris (with the alias Biography:Lutèce), Opinion:Paris and
	 * Biography:Sant Llorenç de Morunys.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		otherguise_test_wp_objects( array( new WP_Post( 1 ), new WP_Post( 2 ), new WP_Post( 3 ), new WP_Post( 4 ) ) );
		update_option(
			Spaces::OPTION,
			array(
				array( 'name' => 'Biography' ),
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

		$this->names->claim( 1, 'Biography', 'Lutèce' );
		$this->names->claim( 1, 'Biography', 'Paris' );
		$this->names->claim( 2, 'Opinion', 'Paris' );
		$this->names->claim( 3, 'Biography', 'Sant Llorenç de Morunys' );
	}

	/**
	 * The router, with the request and the redirects of the test.
	 *
	 * @return Router
	 */
	private function router(): Router {
		return new Router(
			$this->names,
			fn() => $this->uri,
			function ( $url, $status ) {
				$this->redirects[] = array( $url, $status );
			},
			static fn( $path ) => 'https://example.test' . $path,
			fn() => $this->pretty
		);
	}

	/**
	 * The `WP` object after the rule matched.
	 *
	 * @param string $space Space, decoded.
	 * @param string $title Title, decoded.
	 * @return stdClass
	 */
	private function wp( $space, $title ) {
		return (object) array(
			'query_vars' => array(
				'wiki_space' => $space,
				'wiki_title' => $title,
			),
		);
	}

	/**
	 * A current name at its canonical address makes the main query ask for the page.
	 *
	 * @return void
	 */
	public function test_a_current_name_loads_the_page(): void {
		$this->uri = '/Biography:Paris';
		$wp        = $this->wp( 'Biography', 'Paris' );
		$router    = $this->router();

		$router->parse_request( $wp );

		$this->assertSame(
			array(
				'post_type' => 'wiki_page',
				'p' => 1,
			),
			$wp->query_vars
		);
		$this->assertSame( array(), $this->redirects );
		$this->assertFalse( $router->redirect_canonical( 'https://example.test/x/', 'y' ), 'The redirect of WordPress is turned off.' );

		$this->uri = '/Biography:Sant_Lloren%C3%A7_de_Morunys';
		$wp        = $this->wp( 'Biography', 'Sant Llorenç de Morunys' );
		$router->parse_request( $wp );

		$this->assertSame( 3, $wp->query_vars['p'], 'An accent, in its canonical form.' );
		$this->assertSame( array(), $this->redirects );
	}

	/**
	 * The same title in another space is another page.
	 *
	 * @return void
	 */
	public function test_the_same_title_in_another_space(): void {
		$this->uri = '/Opinion:Paris';
		$wp        = $this->wp( 'Opinion', 'Paris' );

		$this->router()->parse_request( $wp );

		$this->assertSame( 2, $wp->query_vars['p'] );
	}

	/**
	 * Everything that is not the canonical form of the address is a 301 to it, with the query string kept: a space name in another case, a
	 * first letter in lower case, a space, a trailing slash, hexadecimal digits in lower case.
	 *
	 * @return void
	 */
	public function test_a_form_that_is_not_canonical_is_redirected(): void {
		$cases = array(
			array( '/biography:Paris', 'biography', 'Paris', 'https://example.test/Biography:Paris' ),
			array( '/Biography:paris', 'Biography', 'paris', 'https://example.test/Biography:Paris' ),
			array( '/Biography:Paris/', 'Biography', 'Paris', 'https://example.test/Biography:Paris' ),
			array( '/biography:Paris?mode=print', 'biography', 'Paris', 'https://example.test/Biography:Paris?mode=print' ),
			array( '/Biography:Sant%20Lloren%C3%A7_de_Morunys', 'Biography', 'Sant Llorenç de Morunys', 'https://example.test/Biography:Sant_Lloren%C3%A7_de_Morunys' ),
			array( '/Biography:Sant_Lloren%c3%a7_de_Morunys/', 'Biography', 'Sant Llorenç de Morunys', 'https://example.test/Biography:Sant_Lloren%C3%A7_de_Morunys' ),
		);

		foreach ( $cases as $case ) {
			$this->redirects = array();
			$this->uri       = $case[0];
			$wp              = $this->wp( $case[1], $case[2] );

			$this->router()->parse_request( $wp );

			$this->assertSame( array( array( $case[3], 301 ) ), $this->redirects, $case[0] );
			$this->assertArrayNotHasKey( 'p', $wp->query_vars, 'Nothing is loaded: the redirect comes first.' );
		}
	}

	/**
	 * An alias is a 301 to the current address of the page, in whatever form it was asked.
	 *
	 * @return void
	 */
	public function test_an_alias_is_redirected_to_the_current_address(): void {
		$this->uri = '/Biography:Lut%C3%A8ce?x=1';

		$this->router()->parse_request( $this->wp( 'Biography', 'Lutèce' ) );

		$this->assertSame( array( array( 'https://example.test/Biography:Paris?x=1', 301 ) ), $this->redirects );
	}

	/**
	 * A name that nobody has is a 404 that keeps what was asked; an unknown space is an ordinary 404 that keeps nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_name_and_an_unknown_space(): void {
		$this->uri = '/biography:nobody';
		$wp        = $this->wp( 'biography', 'nobody_here' );
		$router    = $this->router();

		$router->parse_request( $wp );

		$this->assertSame(
			array(
				'error' => '404',
				'wiki_space' => 'Biography',
				'wiki_title' => 'Nobody here',
			),
			$wp->query_vars,
			'The space as registered and the title as the rules make it.'
		);
		$this->assertSame( array(), $this->redirects, 'No redirect for a name that does not exist.' );
		$this->assertFalse( $router->redirect_canonical( 'x', 'y' ), 'The request is ours.' );

		$wp = $this->wp( 'Bad', 'Paris' );
		$router->parse_request( $wp );

		$this->assertSame( array( 'error' => '404' ), $wp->query_vars );
		$this->assertSame( 'x', $router->redirect_canonical( 'x', 'y' ), 'Not ours: the redirect of WordPress is left alone.' );

		$wp = $this->wp( 'Biography', 'a|b' );
		$router->parse_request( $wp );

		$this->assertSame( 'a|b', $wp->query_vars['wiki_title'], 'A title that the rules refuse is kept as it was asked.' );
	}

	/**
	 * A request that did not match the rule is left alone.
	 *
	 * @return void
	 */
	public function test_another_request_is_left_alone(): void {
		$wp = (object) array( 'query_vars' => array( 'name' => 'about' ) );

		$this->router()->parse_request( $wp );
		$this->router()->parse_request( 'not an object' );

		$this->assertSame( array( 'name' => 'about' ), $wp->query_vars );
		$this->assertSame( 'x', $this->router()->redirect_canonical( 'x', 'y' ) );
		$this->assertSame( array(), $this->redirects );
	}

	/**
	 * The address of a page: its current name; the plain address for a page with no name, another type, or a site with no pretty permalinks.
	 *
	 * @return void
	 */
	public function test_the_permalink(): void {
		$router = $this->router();

		$this->assertSame( 'https://example.test/Biography:Paris', $router->post_link( 'plain', new WP_Post( 1, 'wiki_page' ) ) );
		$this->assertSame( 'https://example.test/Biography:Sant_Lloren%C3%A7_de_Morunys', $router->post_link( 'plain', new WP_Post( 3, 'wiki_page' ) ) );
		$this->assertSame( 'plain', $router->post_link( 'plain', new WP_Post( 4, 'wiki_page' ) ), 'A page that has no name.' );
		$this->assertSame( 'plain', $router->post_link( 'plain', new WP_Post( 1, 'post' ) ), 'Another type.' );
		$this->assertSame( 'plain', $router->post_link( 'plain', 'not a post' ) );

		$this->pretty = false;

		$this->assertSame( 'plain', $this->router()->post_link( 'plain', new WP_Post( 1, 'wiki_page' ) ), 'No pretty permalinks: the rule is not used.' );
	}

	/**
	 * The rule, its query variables and its flush (once).
	 *
	 * @return void
	 */
	public function test_the_rule_and_its_flush(): void {
		$router = $this->router();

		$router->add_rule();

		$rule = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'add_rewrite_rule' === $call[0] ) )[0];

		$this->assertSame( '^([^/:]+):(.+?)/?$', $rule[1] );
		$this->assertSame( 'index.php?wiki_space=$matches[1]&wiki_title=$matches[2]', $rule[2] );
		$this->assertSame( 'top', $rule[3] );
		$this->assertSame( array( 'p', 'wiki_space', 'wiki_title' ), $router->query_vars( array( 'p' ) ) );
		$this->assertSame( array( 'wiki_space', 'wiki_title' ), $router->query_vars( 'broken' ) );

		$router->maybe_flush();
		$router->maybe_flush();

		$this->assertCount( 1, array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'flush_rewrite_rules' === $call[0] ), 'Flushed once.' );
		$this->assertSame( Router::VERSION, get_option( Router::OPTION ) );

		update_option( Router::OPTION, 'older' );
		$router->maybe_flush();

		$this->assertCount( 2, array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'flush_rewrite_rules' === $call[0] ), 'Flushed again when the version changes.' );
	}

	/**
	 * The rules are flushed again when the ones that WordPress keeps do not hold the rule of the module, whatever the version, but not
	 * twice in a minute, and never when there are no rules (plain permalinks).
	 *
	 * @return void
	 */
	public function test_the_rules_are_flushed_when_the_rule_is_missing(): void {
		$flushes = static fn() => count( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'flush_rewrite_rules' === $call[0] ) );
		$router  = $this->router();

		update_option( Router::OPTION, Router::VERSION );
		update_option( 'rewrite_rules', '' );
		$router->maybe_flush();
		$this->assertSame( 0, $flushes(), 'Plain permalinks keep no rule: nothing to flush.' );

		update_option( 'rewrite_rules', array( '^about/?$' => 'index.php?pagename=about' ) );
		$router->maybe_flush();
		$this->assertSame( 1, $flushes(), 'Pretty permalinks saved without the rule.' );

		$router->maybe_flush();
		$this->assertSame( 1, $flushes(), 'Not again at once.' );

		delete_transient( 'wiki_rules_flushed' );
		update_option(
			'rewrite_rules',
			array(
				Router::PATTERN => 'index.php?x=1',
				'^about/?$' => 'index.php?pagename=about',
			)
		);
		$router->maybe_flush();
		$this->assertSame( 1, $flushes(), 'The rule is there: nothing to do.' );
	}

	/**
	 * The values that WordPress hands over are still percent-encoded when the server gives the raw path (nginx; the built-in server of PHP
	 * gives `PATH_INFO`, decoded): the space and the title are decoded, whichever form arrives.
	 *
	 * @return void
	 */
	public function test_values_that_are_still_encoded_are_decoded(): void {
		$cases = array(
			array( 'Opinion', 'Paris', 2 ),
			array( 'Biography', 'Sant_Lloren%C3%A7_de_Morunys', 3 ),
			array( 'Biography', 'Sant Llorenç de Morunys', 3 ),
			array( 'Biography', 'Sant%20Lloren%c3%a7%20de%20Morunys', 3 ),
		);

		foreach ( $cases as $case ) {
			$this->redirects = array();
			$this->uri       = '/' . $case[0] . ':' . $case[1];
			$wp              = $this->wp( $case[0], $case[1] );

			$this->router()->parse_request( $wp );

			$loaded = isset( $wp->query_vars['p'] ) ? $wp->query_vars['p'] : ( array() === $this->redirects ? 'nothing' : 'redirect' );

			$this->assertTrue( in_array( $loaded, array( $case[2], 'redirect' ), true ), $case[1] . ' gave ' . $loaded );
			$this->assertArrayNotHasKey( 'error', $wp->query_vars, $case[1] );
		}

		update_option(
			Spaces::OPTION,
			array(
				array( 'name' => 'Encyclopédie' ),
				array( 'name' => 'Biography' ),
				array( 'name' => 'Opinion' ),
			)
		);
		$this->names->claim( 4, 'Encyclopédie', 'Accueil' );

		$this->redirects = array();
		$this->uri       = '/Encyclop%C3%A9die:Accueil';
		$wp        = $this->wp( 'Encyclop%C3%A9die', 'Accueil' );

		$this->router()->parse_request( $wp );

		$this->assertSame(
			array(
				'post_type' => 'wiki_page',
				'p' => 4,
			),
			$wp->query_vars,
			'A space with an accent, as nginx hands it over.'
		);
		$this->assertSame( array(), $this->redirects );

		$wp = $this->wp( 'Encyclopédie', 'Accueil' );
		$this->router()->parse_request( $wp );
		$this->assertSame( 4, $wp->query_vars['p'], 'And already decoded.' );

		$this->redirects = array();
		$this->uri       = '/encyclop%C3%A9die:Accueil';
		$this->router()->parse_request( $this->wp( 'encyclop%C3%A9die', 'Accueil' ) );
		$this->assertSame( array( array( 'https://example.test/Encyclop%C3%A9die:Accueil', 301 ) ), $this->redirects, 'The space in another case is a 301.' );
	}
}
