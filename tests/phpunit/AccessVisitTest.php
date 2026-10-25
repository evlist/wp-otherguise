<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the private links (slice 400): the request that carries a token.
 *
 * @package Otherguise
 */

use Otherguise\Access\Link\Links;
use Otherguise\Access\Link\Visit;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-access-meta.php';

/**
 * No WordPress: the posts, the query and the headers are doubles.
 *
 * @covers \Otherguise\Access\Link\Visit
 */
class AccessVisitTest extends TestCase {

	/**
	 * The links.
	 *
	 * @var Links
	 */
	private $links;

	/**
	 * Posts by id.
	 *
	 * @var array<int, object>
	 */
	private $posts = array();

	/**
	 * What was sent: headers, calls to the cache functions.
	 *
	 * @var array
	 */
	private $sent = array();

	/**
	 * Builds the links and three posts: a draft, a published page and a post in the trash.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		$this->sent  = array();
		$this->links = new Links( new Otherguise_Test_Access_Meta(), null, null, static fn( $type ) => in_array( $type, array( 'post', 'page' ), true ) );
		$this->posts = array(
			12 => (object) array(
				'ID'          => 12,
				'post_type'   => 'post',
				'post_status' => 'draft',
			),
			13 => (object) array(
				'ID'          => 13,
				'post_type'   => 'page',
				'post_status' => 'private',
			),
			14 => (object) array(
				'ID'          => 14,
				'post_type'   => 'post',
				'post_status' => 'trash',
			),
		);
	}

	/**
	 * Builds the handler with doubles.
	 *
	 * @return Visit
	 */
	private function visit(): Visit {
		return new Visit(
			$this->links,
			fn( $id ) => $this->posts[ $id ] ?? null,
			array(
				'header'   => function ( $line ) {
					$this->sent[] = $line;
				},
				'no_cache' => function () {
					$this->sent[] = 'no_cache';
				},
				'define'   => function () {
					$this->sent[] = 'define';
				},
			)
		);
	}

	/**
	 * Builds a `WP` double with the query variables of a request.
	 *
	 * @param array $vars Query variables.
	 * @return object
	 */
	private function wp( array $vars ) {
		return (object) array( 'query_vars' => $vars );
	}

	/**
	 * Builds a query double.
	 *
	 * @param bool $main Whether it is the main query.
	 * @return object
	 */
	private function query( $main = true ) {
		return new class( $main ) {
			/**
			 * Whether it is the main query.
			 *
			 * @var bool
			 */
			private $main;

			/**
			 * Posts found.
			 *
			 * @var int
			 */
			public $found_posts = 0;

			/**
			 * Pages of results.
			 *
			 * @var int
			 */
			public $max_num_pages = 0;

			/**
			 * Builds the query.
			 *
			 * @param bool $main Whether it is the main query.
			 */
			public function __construct( $main ) {
				$this->main = $main;
			}

			/**
			 * Whether it is the main query.
			 *
			 * @return bool
			 */
			public function is_main_query() {
				return $this->main;
			}
		};
	}

	/**
	 * The argument is a query variable of WordPress.
	 *
	 * @return void
	 */
	public function test_the_argument_is_a_query_variable(): void {
		$this->assertSame( array( 'p', 'shared' ), $this->visit()->query_vars( array( 'p' ) ) );
		$this->assertSame( 'text', $this->visit()->query_vars( 'text' ) );
	}

	/**
	 * A right token turns the request into the request of its post, with the status of the post.
	 *
	 * @return void
	 */
	public function test_a_token_gives_the_query_of_its_post(): void {
		$visit = $this->visit();
		$draft = $this->links->create( 12 );
		$page  = $this->links->create( 13 );
		$wp    = $this->wp( array( 'shared' => $draft ) );

		$visit->parse_request( $wp );

		$this->assertSame(
			array(
				'p'           => 12,
				'post_type'   => 'post',
				'post_status' => 'draft',
			),
			$wp->query_vars
		);
		$this->assertSame( 12, $visit->post_id() );

		$wp = $this->wp( array( 'shared' => $page ) );

		$visit->parse_request( $wp );

		$this->assertSame(
			array(
				'page_id'     => 13,
				'post_status' => 'private',
			),
			$wp->query_vars,
			'A page is asked by `page_id`.'
		);
		$this->assertSame( 13, $visit->post_id() );
	}

	/**
	 * An unknown, malformed, empty or revoked token is a 404, never the home page; the post of a token that is in the trash too.
	 *
	 * @return void
	 */
	public function test_a_token_that_opens_nothing_is_a_404(): void {
		$visit   = $this->visit();
		$revoked = $this->links->create( 12 );

		$this->links->revoke( 12 );

		$trash = $this->links->create( 14 );

		foreach ( array( 'nope', '', $revoked, $trash, str_repeat( 'a', 22 ), array( 'x' ) ) as $token ) {
			$wp = $this->wp( array( 'shared' => $token ) );

			$visit->parse_request( $wp );

			$this->assertSame( array( 'error' => '404' ), $wp->query_vars, 'Not found: ' . wp_json_encode( $token ) );
			$this->assertSame( 0, $visit->post_id() );
		}
	}

	/**
	 * The token opens one thing: with anything else in the request (a feed, a search, a REST route, a post given by its address) it counts
	 * for nothing and the request is what it was.
	 *
	 * @return void
	 */
	public function test_the_token_counts_for_nothing_with_anything_else_in_the_request(): void {
		$visit = $this->visit();
		$token = $this->links->create( 12 );

		foreach ( array(
			'feed' => 'rss2',
			's' => 'text',
			'rest_route' => '/wp/v2/posts',
			'p' => '13',
			'name' => 'a-post',
			'paged' => '2',
		) as $name => $value ) {
			$vars = array(
				'shared' => $token,
				$name    => $value,
			);
			$wp   = $this->wp( $vars );

			$visit->parse_request( $wp );

			$this->assertSame( $vars, $wp->query_vars, 'Left alone: ' . $name );
			$this->assertSame( 0, $visit->post_id() );
		}

		$wp = $this->wp( array( 'p' => '12' ) );

		$visit->parse_request( $wp );

		$this->assertSame( array( 'p' => '12' ), $wp->query_vars, 'No token: nothing changes.' );

		$wp = (object) array();

		$visit->parse_request( $wp );
		$visit->parse_request( null );

		$this->assertSame( 0, $visit->post_id() );
	}

	/**
	 * What was decided for a request does not stay for the next.
	 *
	 * @return void
	 */
	public function test_nothing_stays_from_one_request_to_another(): void {
		$visit = $this->visit();

		$visit->parse_request( $this->wp( array( 'shared' => $this->links->create( 12 ) ) ) );
		$this->assertSame( 12, $visit->post_id() );

		$visit->parse_request( $this->wp( array( 'p' => '1' ) ) );
		$this->assertSame( 0, $visit->post_id() );
	}

	/**
	 * The post is given to the main query only.
	 *
	 * @return void
	 */
	public function test_the_post_is_given_to_the_main_query_only(): void {
		$visit = $this->visit();

		$this->assertNull( $visit->posts_pre_query( null, $this->query() ), 'No token: the value it receives.' );

		$visit->parse_request( $this->wp( array( 'shared' => $this->links->create( 12 ) ) ) );

		$main  = $this->query();
		$given = $visit->posts_pre_query( null, $main );

		$this->assertSame( array( $this->posts[12] ), $given );
		$this->assertSame( 1, $main->found_posts );
		$this->assertSame( 1, $main->max_num_pages );

		$other = $this->query( false );

		$this->assertSame( array( 'another' ), $visit->posts_pre_query( array( 'another' ), $other ), 'Another query is left alone.' );
		$this->assertSame( 0, $other->found_posts );
		$this->assertSame( array( 'x' ), $visit->posts_pre_query( array( 'x' ), (object) array() ), 'Not a query.' );

		unset( $this->posts[12] );

		$this->assertNull( $visit->posts_pre_query( null, $this->query() ), 'A post that is gone meanwhile: nothing is given.' );
	}

	/**
	 * The canonical redirection would lose the token.
	 *
	 * @return void
	 */
	public function test_no_canonical_redirection_with_a_token(): void {
		$visit = $this->visit();

		$this->assertSame( 'https://example.test/a-post/', $visit->redirect_canonical( 'https://example.test/a-post/' ) );

		$visit->parse_request( $this->wp( array( 'shared' => $this->links->create( 12 ) ) ) );

		$this->assertFalse( $visit->redirect_canonical( 'https://example.test/a-post/' ) );
	}

	/**
	 * The answer is not cached, not indexed and keeps its address to itself; nothing is marked without a token.
	 *
	 * @return void
	 */
	public function test_the_answer_is_marked(): void {
		$visit = $this->visit();

		$visit->send_headers();

		$this->assertSame( array(), $this->sent );
		$this->assertSame( array( 'index' => true ), $visit->robots( array( 'index' => true ) ) );

		$visit->parse_request( $this->wp( array( 'shared' => $this->links->create( 12 ) ) ) );
		$visit->send_headers();

		$this->assertSame( array( 'define', 'no_cache', 'X-Robots-Tag: noindex, nofollow', 'Referrer-Policy: no-referrer' ), $this->sent );
		$this->assertSame(
			array(
				'noindex'  => true,
				'nofollow' => true,
			),
			$visit->robots(
				array(
					'max-image-preview' => 'large',
					'noindex'           => false,
				)
			),
			'The page asks not to be indexed (and drops the preview of images).'
		);
		$this->assertSame( 'text', $visit->robots( 'text' ) );
	}
}
