<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the shortcode that gives the address of a page in a mode.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Link\UrlShortcode;
use Otherguise\Modes\Mode\ModeDefinition;
use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Modes\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress: the page address, the current post and the escaping are injected.
 *
 * @covers \Otherguise\Modes\Link\UrlShortcode
 */
class ModesUrlShortcodeTest extends TestCase {

	/**
	 * The modes are disabled by default: these tests need them.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		update_option( Settings::OPTION, array( 'enabled' => true ) );
	}

	/**
	 * Cleans the options.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		otherguise_test_reset();
	}

	/**
	 * Builds the shortcode: the page of post 7 is /seven/, the page of the request /request/.
	 *
	 * @param int $current Current post.
	 * @return UrlShortcode
	 */
	private function shortcode( $current = 7 ) {
		$modes = new ModeRegistry(
			static function ( $registry ) {
				$registry->register( new ModeDefinition( 'web', 'Web' ) );
				$registry->register( new ModeDefinition( 'print', 'Print', 'print' ) );
			},
			static fn() => 'web'
		);

		return new UrlShortcode(
			$modes,
			new Settings(),
			static fn( $post_id ) => $post_id > 0 ? 'https://e.org/post-' . $post_id . '/?print=print' : 'https://e.org/request/?print=print',
			static fn() => $current,
			static fn( $url ) => $url
		);
	}

	/**
	 * The address of the current post in a mode: the default mode has no argument, the alias of another mode is replaced.
	 *
	 * @return void
	 */
	public function test_the_address_of_the_current_post_in_a_mode(): void {
		$this->assertSame( 'https://e.org/post-7/', $this->shortcode()->render( array( 'mode' => 'web' ) ) );
		$this->assertSame( 'https://e.org/post-7/?mode=print', $this->shortcode()->render( array( 'mode' => 'print' ) ) );
	}

	/**
	 * `post` names another post; without a current post the address of the request is used.
	 *
	 * @return void
	 */
	public function test_the_post_and_the_request(): void {
		$this->assertSame(
			'https://e.org/post-12/',
			$this->shortcode()->render(
				array(
					'mode' => 'web',
					'post' => '12',
				)
			)
		);
		$this->assertSame( 'https://e.org/request/', $this->shortcode( 0 )->render( array( 'mode' => 'web' ) ) );
	}

	/**
	 * Nothing for an unknown mode, no mode, odd attributes or disabled modes.
	 *
	 * @return void
	 */
	public function test_nothing_when_there_is_nothing_to_give(): void {
		$this->assertSame( '', $this->shortcode()->render( array( 'mode' => 'book' ) ) );
		$this->assertSame( '', $this->shortcode()->render( array() ) );
		$this->assertSame( '', $this->shortcode()->render( '' ) );
		$this->assertSame( '', $this->shortcode()->render( array( 'mode' => array( 'web' ) ) ) );

		update_option( Settings::OPTION, array( 'enabled' => false ) );

		$this->assertSame( '', $this->shortcode()->render( array( 'mode' => 'web' ) ) );
	}

	/**
	 * The address is escaped by the injected function, whatever it does.
	 *
	 * @return void
	 */
	public function test_the_address_is_escaped(): void {
		$modes = new ModeRegistry(
			static function ( $registry ) {
				$registry->register( new ModeDefinition( 'web', 'Web' ) );
			},
			static fn() => 'web'
		);
		$short = new UrlShortcode( $modes, new Settings(), static fn() => 'https://e.org/a/', static fn() => 1, static fn( $url ) => '[' . $url . ']' );

		$this->assertSame( '[https://e.org/a/]', $short->render( array( 'mode' => 'web' ) ) );
	}
}
