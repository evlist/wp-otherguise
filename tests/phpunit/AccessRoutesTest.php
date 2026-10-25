<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the private links (slice 400): the REST routes and the command.
 *
 * @package Otherguise
 */

use Otherguise\Access\Cli\LinksCommand;
use Otherguise\Access\Link\Links;
use Otherguise\Access\Rest\LinkRoutes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-access-meta.php';

/**
 * No WordPress: the posts, the capabilities and the output are doubles.
 *
 * @covers \Otherguise\Access\Rest\LinkRoutes
 * @covers \Otherguise\Access\Cli\LinksCommand
 */
class AccessRoutesTest extends TestCase {

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
	 * What the command printed.
	 *
	 * @var string[]
	 */
	private $out = array();

	/**
	 * Builds the links and three posts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		$this->out   = array();
		$this->links = new Links( new Otherguise_Test_Access_Meta(), null, null, static fn( $type ) => in_array( $type, array( 'post', 'page' ), true ) );
		$this->posts = array(
			12 => (object) array(
				'ID'           => 12,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'Draft',
			),
			13 => (object) array(
				'ID'           => 13,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Page',
			),
			14 => (object) array(
				'ID'           => 14,
				'post_type'    => 'post',
				'post_status'  => 'trash',
				'post_title'   => 'In the trash',
			),
		);
	}

	/**
	 * Builds the routes; the user may edit the posts of `$allowed`.
	 *
	 * @param int[] $allowed Posts the user may edit.
	 * @return LinkRoutes
	 */
	private function routes( array $allowed = array( 12, 13, 14 ) ): LinkRoutes {
		return new LinkRoutes( $this->links, static fn( $id ) => in_array( $id, $allowed, true ), fn( $id ) => $this->posts[ $id ] ?? null );
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
	 * The route: registered with its three methods, each one checking the capability on the post.
	 *
	 * @return void
	 */
	public function test_the_route_is_registered(): void {
		$routes = $this->routes( array( 12 ) );

		$routes->register();

		$registered = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_rest_route' === $call[0] ) );

		$this->assertCount( 1, $registered );
		$this->assertSame( 'access/v1', $registered[0][1] );
		$this->assertSame( '/posts/(?P<id>[0-9]+)', $registered[0][2] );
		$this->assertSame( array( 'GET', 'POST', 'DELETE' ), array_column( $registered[0][3], 'methods' ) );

		foreach ( $registered[0][3] as $definition ) {
			$this->assertSame( array( $routes, 'can' ), $definition['permission_callback'] );
			$this->assertTrue( $definition['args']['id']['required'] );
		}

		$this->assertArrayHasKey( 'regenerate', $registered[0][3][1]['args'] );
		$this->assertTrue( $routes->can( $this->request( array( 'id' => '12' ) ) ) );
		$this->assertFalse( $routes->can( $this->request( array( 'id' => '13' ) ) ), 'A post the user may not edit.' );
		$this->assertFalse( $routes->can( $this->request( array( 'id' => '0' ) ) ) );
		$this->assertFalse( $routes->can( $this->request( array() ) ) );
	}

	/**
	 * The state, the creation (twice: the same), the replacement and the revocation, as the panel asks for them.
	 *
	 * @return void
	 */
	public function test_the_state_the_creation_and_the_revocation(): void {
		$routes = $this->routes();

		$this->assertSame(
			array(
				'id'      => 12,
				'active'  => false,
				'url'     => '',
				'created' => '',
			),
			$routes->state( $this->request( array( 'id' => 12 ) ) )
		);

		$made = $routes->create( $this->request( array( 'id' => 12 ) ) );

		$this->assertTrue( $made['active'] );
		$this->assertSame( 'https://example.test/?shared=' . $this->links->token( 12 ), $made['url'] );
		$this->assertNotSame( '', $made['created'] );

		$again = $routes->create(
			$this->request(
				array(
					'id' => 12,
					'regenerate' => false,
				)
			)
		);

		$this->assertSame( $made['url'], $again['url'], 'Run twice: the same link.' );

		$replaced = $routes->create(
			$this->request(
				array(
					'id' => 12,
					'regenerate' => true,
				)
			)
		);

		$this->assertTrue( $replaced['active'] );
		$this->assertNotSame( $made['url'], $replaced['url'], 'Replaced.' );
		$this->assertSame( $replaced, $routes->state( $this->request( array( 'id' => 12 ) ) ) );

		$revoked = $routes->revoke( $this->request( array( 'id' => 12 ) ) );

		$this->assertFalse( $revoked['active'] );
		$this->assertSame( '', $revoked['url'] );
		$this->assertSame( '', $this->links->token( 12 ) );
		$this->assertFalse( $routes->revoke( $this->request( array( 'id' => 12 ) ) )['active'], 'Run twice.' );
	}

	/**
	 * A post that does not exist, and a post that cannot have a link.
	 *
	 * @return void
	 */
	public function test_the_refusals(): void {
		$routes = $this->routes();

		foreach ( array( 'state', 'create', 'revoke' ) as $method ) {
			$missing = $routes->$method( $this->request( array( 'id' => 99 ) ) );

			$this->assertInstanceOf( WP_Error::class, $missing, $method );
			$this->assertSame( 'access_not_found', $missing->get_error_code() );
			$this->assertSame( 404, $missing->get_error_data()['status'] );
		}

		$trash = $routes->create( $this->request( array( 'id' => 14 ) ) );

		$this->assertSame( 'access_not_eligible', $trash->get_error_code() );
		$this->assertSame( 400, $trash->get_error_data()['status'] );
		$this->assertSame( '', $this->links->token( 14 ), 'Nothing was made.' );
	}

	/**
	 * Builds the command with doubles; an error stops the command by an exception, as `WP_CLI::error` stops the process.
	 *
	 * @return LinksCommand
	 */
	private function command(): LinksCommand {
		return new LinksCommand(
			$this->links,
			fn( $id ) => $this->posts[ $id ] ?? null,
			function ( $line ) {
				$this->out[] = $line;
			},
			function ( $line ) {
				$this->out[] = 'Success: ' . $line;
			},
			static function ( $message ) {
				throw new RuntimeException( esc_html( $message ) );
			}
		);
	}

	/**
	 * Runs a command and returns the error it stopped with, or null.
	 *
	 * @param callable $run What to run.
	 * @return string|null
	 */
	private function error_of( $run ) {
		try {
			$run();
		} catch ( RuntimeException $error ) {
			return $error->getMessage();
		}

		return null;
	}

	/**
	 * The command makes links (twice: the same) without printing the token, unless it is asked to.
	 *
	 * @return void
	 */
	public function test_creating_by_the_command(): void {
		$command = $this->command();

		$command->create( array( '12' ), array() );
		$token = $this->links->token( 12 );

		$this->assertSame( array( 'Success: Link made for post 12.' ), $this->out );
		$this->assertStringNotContainsString( $token, implode( ' ', $this->out ), 'The token is not printed.' );

		$this->out = array();
		$command->create( array( '12' ), array( 'show-token' => true ) );

		$this->assertSame( array( 'https://example.test/?shared=' . $token, 'Success: Post 12 had a link already.' ), $this->out );
		$this->assertSame( $token, $this->links->token( 12 ), 'Run twice: the same link.' );

		$this->assertSame( 'This post does not exist.', $this->error_of( fn() => $command->create( array( '99' ), array() ) ) );
		$this->assertSame( 'This post cannot have a link: it is in the trash or it is not a page of the site.', $this->error_of( fn() => $command->create( array( '14' ), array() ) ) );
		$this->assertSame( 'The post is given by its id.', $this->error_of( fn() => $command->create( array( 'x' ), array() ) ) );
		$this->assertSame( 'The post is given by its id.', $this->error_of( fn() => $command->create( array(), array() ) ) );
	}

	/**
	 * The command revokes (twice, with a dry run).
	 *
	 * @return void
	 */
	public function test_revoking_by_the_command(): void {
		$command = $this->command();

		$this->links->create( 12 );
		$command->revoke( array( '12' ), array( 'dry-run' => true ) );

		$this->assertSame( array( 'Would revoke the link of post 12.' ), $this->out );
		$this->assertNotSame( '', $this->links->token( 12 ), 'A dry run changes nothing.' );

		$this->out = array();
		$command->revoke( array( '12' ), array() );
		$command->revoke( array( '12' ), array() );
		$command->revoke( array( '12' ), array( 'dry-run' => true ) );

		$this->assertSame( array( 'Success: Link of post 12 revoked.', 'Success: Post 12 had no link.', 'Post 12 has no link.' ), $this->out );
		$this->assertSame( '', $this->links->token( 12 ) );
		$this->assertSame( 'The post is given by its id.', $this->error_of( fn() => $command->revoke( array( '0' ), array() ) ) );
	}

	/**
	 * The list, without the token, with the address on request, and as JSON.
	 *
	 * @return void
	 */
	public function test_listing_by_the_command(): void {
		$command = $this->command();

		$command->list( array(), array() );

		$this->assertSame( array(), $this->out, 'No link: nothing printed.' );

		$this->links->create( 13 );
		$this->links->create( 12 );

		$created = $this->links->created( 12 );

		$command->list( array(), array() );

		$this->assertSame( array( "12\tdraft\tDraft\t" . $created, "13\tpublish\tPage\t" . $created ), $this->out );
		$this->assertStringNotContainsString( $this->links->token( 12 ), implode( ' ', $this->out ) );

		$this->out = array();
		$command->list( array(), array( 'show-token' => true ) );

		$this->assertStringEndsWith( "\thttps://example.test/?shared=" . $this->links->token( 12 ), $this->out[0] );

		$this->out = array();
		$command->list( array(), array( 'format' => 'json' ) );

		$rows = json_decode( $this->out[0], true );

		$this->assertSame( array( 12, 13 ), array_column( $rows, 'id' ) );
		$this->assertArrayNotHasKey( 'url', $rows[0] );
		$this->assertSame( '--format: table or json.', $this->error_of( fn() => $command->list( array(), array( 'format' => 'xml' ) ) ) );
	}
}
