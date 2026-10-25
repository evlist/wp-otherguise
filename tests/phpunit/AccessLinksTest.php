<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the private links (slice 400): the tokens.
 *
 * @package Otherguise
 */

use Otherguise\Access\Link\Links;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-access-meta.php';

/**
 * No WordPress: the metas, the random bytes and the clock are doubles.
 *
 * @covers \Otherguise\Access\Link\Links
 */
class AccessLinksTest extends TestCase {

	/**
	 * The metas.
	 *
	 * @var Otherguise_Test_Access_Meta
	 */
	private $meta;

	/**
	 * Bytes that the next draws give.
	 *
	 * @var string[]
	 */
	private $draws = array();

	/**
	 * Starts with no meta.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		$this->meta  = new Otherguise_Test_Access_Meta();
		$this->draws = array();
	}

	/**
	 * Builds the links with the doubles; the draws give the bytes of `$draws`, then random ones.
	 *
	 * @param callable|null $viewable Tells whether a type of post is viewable.
	 * @return Links
	 */
	private function links( $viewable = null ): Links {
		return new Links(
			$this->meta,
			function () {
				return array() === $this->draws ? random_bytes( 16 ) : array_shift( $this->draws );
			},
			static fn() => '2026-10-25 09:00:00',
			$viewable ?? static fn( $type ) => in_array( $type, array( 'post', 'page' ), true )
		);
	}

	/**
	 * Builds a post double.
	 *
	 * @param int    $id     Id.
	 * @param string $type   Type.
	 * @param string $status Status.
	 * @return object
	 */
	private function post( $id, $type = 'post', $status = 'draft' ) {
		return (object) array(
			'ID'          => $id,
			'post_type'   => $type,
			'post_status' => $status,
		);
	}

	/**
	 * A token is 22 characters of the URL-safe alphabet, made of 16 random bytes, never twice the same.
	 *
	 * @return void
	 */
	public function test_a_token_has_the_form_of_16_bytes_in_base64_url(): void {
		$links = $this->links();
		$seen  = array();

		for ( $id = 1; $id <= 50; $id++ ) {
			$token = $links->create( $id );

			$this->assertSame( 1, preg_match( '/^[A-Za-z0-9_-]{22}\\z/', $token ), $token );
			$this->assertTrue( Links::is_token( $token ) );
			$seen[ $token ] = true;
		}

		$this->assertCount( 50, $seen, 'No two the same.' );

		$this->draws = array( str_repeat( "\xfb\xff", 8 ) );

		$token = $links->create( 99 );

		$this->assertSame( '-__7__v_-__7__v_-__7_w', $token, 'The bytes fb ff, 8 times, in the URL-safe alphabet.' );
		$this->assertStringNotContainsString( '+', $token );
		$this->assertStringNotContainsString( '/', $token );
		$this->assertStringNotContainsString( '=', $token, 'No padding.' );
	}

	/**
	 * The form of a token.
	 *
	 * @return void
	 */
	public function test_what_is_a_token(): void {
		$this->assertTrue( Links::is_token( str_repeat( 'a', 22 ) ) );
		$this->assertFalse( Links::is_token( str_repeat( 'a', 21 ) ) );
		$this->assertFalse( Links::is_token( str_repeat( 'a', 23 ) ) );
		$this->assertFalse( Links::is_token( str_repeat( 'a', 21 ) . '+' ) );
		$this->assertFalse( Links::is_token( str_repeat( 'a', 21 ) . "\n" ), 'No line end after it.' );
		$this->assertFalse( Links::is_token( '' ) );
		$this->assertFalse( Links::is_token( null ) );
		$this->assertFalse( Links::is_token( array( str_repeat( 'a', 22 ) ) ) );
		$this->assertFalse( Links::is_token( 12345678901234567890123 ) );
	}

	/**
	 * Making the link of a post keeps the one it has; the date is that of the first.
	 *
	 * @return void
	 */
	public function test_creating_keeps_the_token_it_has(): void {
		$links = $this->links();

		$this->assertSame( '', $links->token( 12 ) );
		$this->assertSame( '', $links->created( 12 ) );

		$first = $links->create( 12 );

		$this->assertSame( $first, $links->token( 12 ) );
		$this->assertSame( $first, $links->create( 12 ), 'Run twice: the same.' );
		$this->assertSame( '2026-10-25 09:00:00', $links->created( 12 ) );
	}

	/**
	 * Replacing the token: the old one opens nothing any more.
	 *
	 * @return void
	 */
	public function test_replacing_the_token(): void {
		$links = $this->links();
		$old   = $links->create( 12 );
		$new   = $links->regenerate( 12 );

		$this->assertNotSame( $old, $new );
		$this->assertSame( $new, $links->token( 12 ) );
		$this->assertSame( 0, $links->find( $old ), 'The old link is closed.' );
		$this->assertSame( 12, $links->find( $new ) );

		$this->draws = array( 'same-bytes-16-xx', 'same-bytes-16-xx', 'other-bytes-16-y' );
		$links->regenerate( 13 );
		$first = $links->token( 13 );
		$links->regenerate( 13 );

		$this->assertNotSame( $first, $links->token( 13 ), 'Replaced even if the draw gave the same bytes twice.' );

		$this->assertSame( '', ( new Links( $this->meta ) )->created( 14 ), 'No token, no date.' );
	}

	/**
	 * Revoking takes the token and its date away, and tells whether there was one.
	 *
	 * @return void
	 */
	public function test_revoking(): void {
		$links = $this->links();
		$token = $links->create( 12 );

		$this->assertTrue( $links->revoke( 12 ) );
		$this->assertFalse( $links->revoke( 12 ), 'Run twice.' );
		$this->assertSame( '', $links->token( 12 ) );
		$this->assertSame( '', $links->created( 12 ) );
		$this->assertSame( 0, $links->find( $token ) );
		$this->assertSame( array(), $this->meta->values[12] ?? array() );
	}

	/**
	 * Finding the post of a token: only the right one opens it.
	 *
	 * @return void
	 */
	public function test_finding_the_post_of_a_token(): void {
		$links = $this->links();
		$one   = $links->create( 12 );
		$two   = $links->create( 13 );

		$this->assertSame( 12, $links->find( $one ) );
		$this->assertSame( 13, $links->find( $two ) );

		foreach ( array( '', 'nope', $one . 'x', substr( $one, 1 ), strtoupper( $one ) === $one ? strtolower( $one ) : strtoupper( $one ), null, false, 12, array( $one ), (object) array( 'a' => $one ) ) as $bad ) {
			$this->assertSame( 0, $links->find( $bad ), 'Opens nothing: ' . var_export( $bad, true ) );
		}

		$this->meta->set( 14, Links::TOKEN, 'short' );

		$this->assertSame( '', $links->token( 14 ), 'A stored value that is not a token is not one.' );
		$this->assertSame( 0, $links->find( 'short' ) );
	}

	/**
	 * The posts that have a link, with the date of the token.
	 *
	 * @return void
	 */
	public function test_listing_the_links(): void {
		$links = $this->links();

		$this->assertSame( array(), $links->all() );

		$links->create( 14 );
		$links->create( 12 );
		$this->meta->set( 20, Links::TOKEN, 'not a token' );

		$this->assertSame(
			array(
				12 => '2026-10-25 09:00:00',
				14 => '2026-10-25 09:00:00',
			),
			$links->all()
		);
	}

	/**
	 * The address of the link: the site and the token, and a mode when asked; false without a link.
	 *
	 * @return void
	 */
	public function test_the_address_of_a_link(): void {
		$links = $this->links();

		$this->assertFalse( $links->address( 12 ) );

		$token = $links->create( 12 );

		$this->assertSame( 'https://example.test/?shared=' . $token, $links->address( 12 ) );
		$this->assertSame( 'https://example.test/?shared=' . $token . '&mode=print', $links->address( 12, array( 'mode' => 'print' ) ) );
		$this->assertSame( 'https://example.test/?shared=' . $token . '&mode=printscript', $links->address( 12, array( 'mode' => 'PRINT<script>' ) ), 'A mode is a slug: what is not one is dropped.' );
		$this->assertSame( 'https://example.test/?shared=' . $token, $links->address( 12, array( 'mode' => '' ) ) );
		$this->assertSame( 'https://example.test/?shared=' . $token, $links->address( 12, array( 'mode' => array( 'print' ) ) ) );
		$this->assertSame( 'https://other.test/?shared=' . $token, $links->address( 12, array(), 'https://other.test/' ) );
	}

	/**
	 * Which posts can have a link.
	 *
	 * @return void
	 */
	public function test_which_posts_can_have_a_link(): void {
		$links = $this->links();

		foreach ( array( 'draft', 'pending', 'private', 'future', 'publish' ) as $status ) {
			$this->assertTrue( $links->eligible( $this->post( 1, 'post', $status ) ), $status );
		}

		$this->assertTrue( $links->eligible( $this->post( 1, 'page', 'draft' ) ) );
		$this->assertFalse( $links->eligible( $this->post( 1, 'post', 'trash' ) ), 'Not in the trash.' );
		$this->assertFalse( $links->eligible( $this->post( 1, 'post', 'auto-draft' ) ) );
		$this->assertFalse( $links->eligible( $this->post( 1, 'attachment', 'inherit' ) ) );
		$this->assertFalse( $links->eligible( $this->post( 1, 'wp_template', 'publish' ) ), 'Not seen on the front end.' );
		$this->assertFalse( $links->eligible( null ) );
		$this->assertFalse( $links->eligible( (object) array( 'ID' => 3 ) ) );
		$this->assertFalse( $links->eligible( 12 ) );
	}
}
