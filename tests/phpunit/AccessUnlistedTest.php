<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the unlisted state (slice 402).
 *
 * @package Otherguise
 */

use Otherguise\Access\Cli\LinksCommand;
use Otherguise\Access\Link\Links;
use Otherguise\Access\Rest\UnlistedRoutes;
use Otherguise\Access\Unlisted\Filters;
use Otherguise\Access\Unlisted\Unlisted;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: the posts, the queries and the capabilities are doubles.
 *
 * @covers \Otherguise\Access\Unlisted\Unlisted
 * @covers \Otherguise\Access\Unlisted\Filters
 * @covers \Otherguise\Access\Rest\UnlistedRoutes
 * @covers \Otherguise\Access\Cli\LinksCommand
 */
class AccessUnlistedTest extends TestCase {

	/**
	 * The metas.
	 *
	 * @var Otherguise_Test_Access_Meta
	 */
	private $meta;

	/**
	 * The state.
	 *
	 * @var Unlisted
	 */
	private $unlisted;

	/**
	 * Posts by id.
	 *
	 * @var array<int, object>
	 */
	private $posts = array();

	/**
	 * What the command printed.
	 *
	 * @var string[]
	 */
	private $out = array();

	/**
	 * Builds the state and three posts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		$this->out      = array();
		$this->meta     = new Otherguise_Test_Access_Meta();
		$links          = new Links( $this->meta, null, null, static fn( $type ) => in_array( $type, array( 'post', 'page' ), true ) );
		$this->unlisted = new Unlisted( $links, $this->meta );
		$this->posts    = array(
			12 => (object) array(
				'ID'          => 12,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Cover',
			),
			13 => (object) array(
				'ID'          => 13,
				'post_type'   => 'post',
				'post_status' => 'trash',
				'post_title'  => 'In the trash',
			),
			14 => (object) array(
				'ID'          => 14,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_title'  => 'A file',
			),
		);
	}

	/**
	 * Setting and removing the state, the list of ids that follows, the rule of which posts can be unlisted.
	 *
	 * @return void
	 */
	public function test_the_state(): void {
		$this->assertFalse( $this->unlisted->is_unlisted( 12 ) );
		$this->assertSame( array(), $this->unlisted->ids() );

		$this->unlisted->set( 12, true );
		$this->unlisted->set( 7, true );

		$this->assertTrue( $this->unlisted->is_unlisted( 12 ) );
		$this->assertSame( array( 7, 12 ), $this->unlisted->ids(), 'The list is read again after a change.' );

		$this->unlisted->set( 12, false );
		$this->assertFalse( $this->unlisted->is_unlisted( 12 ) );
		$this->assertSame( array( 7 ), $this->unlisted->ids() );

		$this->assertTrue( $this->unlisted->eligible( $this->posts[12] ) );
		$this->assertFalse( $this->unlisted->eligible( $this->posts[13] ), 'Trash.' );
		$this->assertFalse( $this->unlisted->eligible( $this->posts[14] ), 'Attachment.' );
		$this->assertFalse( $this->unlisted->eligible( null ) );
	}

	/**
	 * Builds a query double that holds variables.
	 *
	 * @param array $vars Variables.
	 * @return object
	 */
	private function query( array $vars = array() ) {
		return new class( $vars ) {
			/**
			 * Variables.
			 *
			 * @var array
			 */
			public $vars;

			/**
			 * Builds the query.
			 *
			 * @param array $vars Variables.
			 */
			public function __construct( array $vars ) {
				$this->vars = $vars;
			}

			/**
			 * Reads a variable.
			 *
			 * @param string $name Name.
			 * @return mixed
			 */
			public function get( $name ) {
				return $this->vars[ $name ] ?? '';
			}

			/**
			 * Sets a variable.
			 *
			 * @param string $name  Name.
			 * @param mixed  $value Value.
			 * @return void
			 */
			public function set( $name, $value ) {
				$this->vars[ $name ] = $value;
			}
		};
	}

	/**
	 * A list query leaves the unlisted posts out, and keeps what was excluded already.
	 *
	 * @return void
	 */
	public function test_a_list_leaves_them_out(): void {
		$this->unlisted->set( 12, true );
		$this->unlisted->set( 15, true );

		$filters = new Filters( $this->unlisted, static fn() => false );
		$query   = $this->query( array( 'post__not_in' => array( 3, 12 ) ) );

		$filters->pre_get_posts( $query );

		$this->assertSame( array( 3, 12, 15 ), $query->vars['post__not_in'] );

		$other = $this->query();

		$filters->pre_get_posts( $other );
		$this->assertSame( array( 12, 15 ), $other->vars['post__not_in'] );
	}

	/**
	 * A lookup of one post, the administration, the editor in REST and the explicit request are left alone.
	 *
	 * @return void
	 */
	public function test_what_is_left_alone(): void {
		$this->unlisted->set( 12, true );

		$filters = new Filters( $this->unlisted, static fn() => false );

		foreach ( array(
			array( 'p' => 12 ),
			array( 'page_id' => 12 ),
			array( 'attachment_id' => 12 ),
			array( 'name' => 'cover' ),
			array( 'pagename' => 'cover' ),
			array( 'attachment' => 'file' ),
			array( 'post__in' => array( 12, 4 ) ),
			array( Filters::INCLUDE => '1' ),
		) as $vars ) {
			$query = $this->query( $vars );
			$filters->pre_get_posts( $query );
			$this->assertArrayNotHasKey( 'post__not_in', $query->vars, wp_json_encode( $vars ) );
		}

		$query = $this->query();
		( new Filters( $this->unlisted, static fn() => true ) )->pre_get_posts( $query );
		$this->assertArrayNotHasKey( 'post__not_in', $query->vars, 'The administration and the editor see everything.' );

		$none = new Filters( new Unlisted( new Links( $this->meta ), new Otherguise_Test_Access_Meta() ), static fn() => false );
		$query = $this->query();
		$none->pre_get_posts( $query );
		$this->assertArrayNotHasKey( 'post__not_in', $query->vars, 'Nothing is unlisted: the query is not touched.' );

		$filters->pre_get_posts( 'not a query' );
		$this->assertTrue( true, 'A value that is not a query is ignored.' );
	}

	/**
	 * The sitemaps, the previous and next links.
	 *
	 * @return void
	 */
	public function test_sitemaps_and_adjacent_links(): void {
		$this->unlisted->set( 12, true );

		$filters = new Filters( $this->unlisted, static fn() => false );

		$this->assertSame( array( 'post__not_in' => array( 12 ) ), $filters->sitemap_arguments( array() ) );
		$this->assertSame( array( 'post__not_in' => array( 5, 12 ) ), $filters->sitemap_arguments( array( 'post__not_in' => array( 5 ) ) ) );
		$this->assertSame( 'x', $filters->sitemap_arguments( 'x' ) );

		$this->assertSame( "WHERE p.post_date < 'x' AND p.ID NOT IN (12)", $filters->adjacent_where( "WHERE p.post_date < 'x'" ) );
		$this->assertSame( 'WHERE 1', ( new Filters( new Unlisted( new Links( $this->meta ), new Otherguise_Test_Access_Meta() ), static fn() => false ) )->adjacent_where( 'WHERE 1' ) );
		$this->assertSame( 7, $filters->adjacent_where( 7 ) );
	}

	/**
	 * The mark in the list of the administration and the robots of the page.
	 *
	 * @return void
	 */
	public function test_marks(): void {
		$this->unlisted->set( 12, true );

		$filters = new Filters( $this->unlisted, static fn() => false, static fn() => 0 );

		$this->assertSame( array( 'access_unlisted' => 'Unlisted' ), $filters->post_states( array(), $this->posts[12] ) );
		$this->assertSame( array(), $filters->post_states( array(), (object) array( 'ID' => 99 ) ) );
		$this->assertSame( 'x', $filters->post_states( 'x', $this->posts[12] ) );

		$on = new Filters( $this->unlisted, static fn() => false, static fn() => 12 );

		$this->assertSame(
			array(
				'a' => true,
				'noindex' => true,
			),
			$on->robots( array( 'a' => true ) )
		);
		$this->assertSame( array( 'a' => true ), $filters->robots( array( 'a' => true ) ), 'Not a single post.' );
		$this->assertSame( array( 'a' => true ), ( new Filters( $this->unlisted, static fn() => false, static fn() => 99 ) )->robots( array( 'a' => true ) ), 'A listed post.' );
		$this->assertSame( 'x', $on->robots( 'x' ) );
	}

	/**
	 * Builds the route with the user allowed on `$allowed`.
	 *
	 * @param int[] $allowed Posts the user may edit.
	 * @return UnlistedRoutes
	 */
	private function routes( array $allowed = array( 12, 13 ) ): UnlistedRoutes {
		return new UnlistedRoutes( $this->unlisted, static fn( $id ) => in_array( $id, $allowed, true ), fn( $id ) => $this->posts[ $id ] ?? null );
	}

	/**
	 * Builds a request double.
	 *
	 * @param array $params Parameters.
	 * @return object
	 */
	private function request( array $params ) {
		return new class( $params ) {
			/**
			 * Parameters.
			 *
			 * @var array
			 */
			private $params;

			/**
			 * Builds the request.
			 *
			 * @param array $params Parameters.
			 */
			public function __construct( array $params ) {
				$this->params = $params;
			}

			/**
			 * Reads a parameter.
			 *
			 * @param string $name Name.
			 * @return mixed
			 */
			public function get_param( $name ) {
				return $this->params[ $name ] ?? null;
			}
		};
	}

	/**
	 * The route is registered under the namespace of the links, with the capability checked on the post.
	 *
	 * @return void
	 */
	public function test_the_route(): void {
		$this->routes()->register();

		$registered = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_rest_route' === $call[0] ) );

		$this->assertSame( 'access/v1', $registered[0][1] );
		$this->assertSame( '/posts/(?P<id>[0-9]+)/unlisted', $registered[0][2] );
		$this->assertTrue( $this->routes()->can( $this->request( array( 'id' => 12 ) ) ) );
		$this->assertFalse( $this->routes( array( 13 ) )->can( $this->request( array( 'id' => 12 ) ) ) );
		$this->assertFalse( $this->routes()->can( $this->request( array( 'id' => 0 ) ) ) );
	}

	/**
	 * Reading and changing the state through the route; a post that cannot be unlisted is refused.
	 *
	 * @return void
	 */
	public function test_the_route_reads_and_changes_the_state(): void {
		$routes = $this->routes();

		$this->assertSame(
			array(
				'id' => 12,
				'unlisted' => false,
				'eligible' => true,
			),
			$routes->state( $this->request( array( 'id' => 12 ) ) )
		);
		$this->assertSame(
			array(
				'id' => 12,
				'unlisted' => true,
				'eligible' => true,
			),
			$routes->change(
				$this->request(
					array(
						'id' => 12,
						'unlisted' => true,
					)
				)
			)
		);
		$this->assertTrue( $this->unlisted->is_unlisted( 12 ) );
		$this->assertSame(
			array(
				'id' => 12,
				'unlisted' => false,
				'eligible' => true,
			),
			$routes->change(
				$this->request(
					array(
						'id' => 12,
						'unlisted' => false,
					)
				)
			)
		);

		$trash = $routes->change(
			$this->request(
				array(
					'id' => 13,
					'unlisted' => true,
				)
			)
		);

		$this->assertSame( 'access_not_eligible', $trash->get_error_code() );
		$this->assertSame( 400, $trash->get_error_data()['status'] );
		$this->assertFalse( $this->unlisted->is_unlisted( 13 ) );

		$this->assertSame( 'access_not_found', $routes->state( $this->request( array( 'id' => 99 ) ) )->get_error_code() );
		$this->assertSame(
			'access_not_found',
			$routes->change(
				$this->request(
					array(
						'id' => 99,
						'unlisted' => true,
					)
				)
			)->get_error_code()
		);
	}

	/**
	 * Builds the command with doubles.
	 *
	 * @return LinksCommand
	 */
	private function command(): LinksCommand {
		return new LinksCommand(
			new Links( $this->meta ),
			fn( $id ) => $this->posts[ $id ] ?? null,
			function ( $line ) {
				$this->out[] = $line;
			},
			function ( $line ) {
				$this->out[] = 'Success: ' . $line;
			},
			static function ( $message ) {
				throw new RuntimeException( esc_html( $message ) );
			},
			null,
			$this->unlisted
		);
	}

	/**
	 * The command hides a post, shows it again, can be run twice, and has a dry run and a list.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$command = $this->command();

		$command->unlisted( array( '12' ), array( 'dry-run' => true ) );
		$this->assertFalse( $this->unlisted->is_unlisted( 12 ) );
		$this->assertStringContainsString( 'Would hide post 12', $this->out[0] );

		$command->unlisted( array( '12' ), array() );
		$command->unlisted( array( '12' ), array() );
		$this->assertTrue( $this->unlisted->is_unlisted( 12 ) );
		$this->assertSame( 'Success: Post 12 is unlisted.', $this->out[1] );

		$command->unlisted_list( array(), array() );
		$this->assertSame( '12  publish  Cover', $this->out[3] );

		$command->unlisted_list( array(), array( 'format' => 'json' ) );
		$this->assertSame( '[{"id":12,"status":"publish","title":"Cover"}]', $this->out[4] );

		$command->unlisted(
			array( '12' ),
			array(
				'off' => true,
				'dry-run' => true,
			)
		);
		$this->assertTrue( $this->unlisted->is_unlisted( 12 ), 'A dry run changes nothing.' );

		$command->unlisted( array( '12' ), array( 'off' => true ) );
		$this->assertFalse( $this->unlisted->is_unlisted( 12 ) );

		$command->unlisted_list( array(), array() );
		$this->assertSame( 'No unlisted post.', end( $this->out ) );
	}

	/**
	 * A missing post and a post that cannot be unlisted are refused; one that cannot be unlisted can still be listed again.
	 *
	 * @return void
	 */
	public function test_the_command_refuses(): void {
		$command = $this->command();

		foreach ( array( '99', '13', '14' ) as $id ) {
			try {
				$command->unlisted( array( $id ), array() );
				$this->fail( 'Refused: ' . $id );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'post', $error->getMessage() );
			}
		}

		$this->unlisted->set( 13, true );
		$command->unlisted( array( '13' ), array( 'off' => true ) );
		$this->assertFalse( $this->unlisted->is_unlisted( 13 ) );
	}

	/**
	 * The public functions answer false without the module.
	 *
	 * @return void
	 */
	public function test_the_public_functions_without_the_module(): void {
		require_once dirname( __DIR__, 2 ) . '/plugin/modules/access/functions.php';

		$this->assertFalse( access_is_unlisted( 12 ) );
		$this->assertFalse( access_is_unlisted( (object) array( 'ID' => 12 ) ) );
		$this->assertFalse( access_unlisted_path() );
	}
}
