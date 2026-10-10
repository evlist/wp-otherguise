<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the address of a page in a mode and of the block that links to it.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Link\LinkBlock;
use Otherguise\Modes\Link\ModeUrl;
use Otherguise\Modes\Mode\ActiveMode;
use Otherguise\Modes\Mode\ModeDefinition;
use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Modes\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress: the page address and the wrapper are injected.
 *
 * @covers \Otherguise\Modes\Link\ModeUrl
 * @covers \Otherguise\Modes\Link\LinkBlock
 */
class ModesLinkTest extends TestCase {

	/**
	 * The modes are disabled by default: these tests need them.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		update_option( \Otherguise\Modes\Settings\Settings::OPTION, array( 'enabled' => true ) );
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
	 * Modes web (default), print (alias `print`) and book.
	 *
	 * @return ModeRegistry
	 */
	private function modes() {
		return new ModeRegistry(
			static function ( $registry ) {
				$registry->register( new ModeDefinition( 'web', 'Web' ) );
				$registry->register( new ModeDefinition( 'print', 'Print', 'print' ) );
				$registry->register( new ModeDefinition( 'book', 'Book' ) );
			},
			static fn() => 'web'
		);
	}

	/**
	 * Builds the block for a request.
	 *
	 * @param array $query Query string of the request.
	 * @return LinkBlock
	 */
	private function block( array $query = array() ) {
		$modes = $this->modes();

		return new LinkBlock( $modes, new ActiveMode( $modes, static fn() => $query ), new Settings(), static fn() => 'https://example.org/post/?a=1#x', static fn() => 'class="wp-block-modes-link"' );
	}

	/**
	 * Data for the addresses.
	 *
	 * @return array
	 */
	public static function addresses(): array {
		return array(
			'print, no query'         => array( 'https://e.org/p/', 'print', 'https://e.org/p/?mode=print' ),
			'default mode, no query'  => array( 'https://e.org/p/', 'web', 'https://e.org/p/' ),
			'keeps arguments'         => array( 'https://e.org/?p=3&x=a%20b', 'print', 'https://e.org/?p=3&x=a%20b&mode=print' ),
			'replaces mode'           => array( 'https://e.org/p/?mode=book&x=1', 'print', 'https://e.org/p/?x=1&mode=print' ),
			'removes the alias'       => array( 'https://e.org/p/?print=print&x=1', 'book', 'https://e.org/p/?x=1&mode=book' ),
			'back to default'         => array( 'https://e.org/p/?print', 'web', 'https://e.org/p/' ),
			'keeps the fragment'      => array( 'https://e.org/p/?mode=book#top', 'print', 'https://e.org/p/?mode=print#top' ),
			'fragment, no query'      => array( 'https://e.org/p/#top', 'print', 'https://e.org/p/?mode=print#top' ),
			'similar key is kept'     => array( 'https://e.org/p/?printer=1&mode2=x', 'print', 'https://e.org/p/?printer=1&mode2=x&mode=print' ),
			'empty pairs are dropped' => array( 'https://e.org/p/?&&x=1&', 'web', 'https://e.org/p/?x=1' ),
		);
	}

	/**
	 * The address in a mode.
	 *
	 * @dataProvider addresses
	 * @param string $url      Address.
	 * @param string $slug     Target mode.
	 * @param string $expected Expected address.
	 * @return void
	 */
	public function test_mode_url( string $url, string $slug, string $expected ): void {
		$modes = $this->modes();

		$this->assertSame( $expected, ModeUrl::build( $url, $modes->get( $slug ), $modes ) );
	}

	/**
	 * A link around the content, to the print version, with nofollow and an accessible name.
	 *
	 * @return void
	 */
	public function test_renders_a_link(): void {
		$html = $this->block()->render(
			array(
				'mode' => 'print',
				'label' => 'Print "it"',
			),
			'<p>icon</p>'
		);

		$this->assertSame(
			'<a class="wp-block-modes-link" href="https://example.org/post/?a=1&#038;mode=print#x" rel="nofollow" aria-label="Print &quot;it&quot;" title="Print &quot;it&quot;"><p>icon</p></a>',
			str_replace( '&amp;', '&#038;', $html )
		);
	}

	/**
	 * Without content the link shows the label, or the label of the mode.
	 *
	 * @return void
	 */
	public function test_default_content(): void {
		$this->assertStringContainsString( '>Print</a>', $this->block()->render( array( 'mode' => 'print' ), '  ' ) );
		$this->assertStringContainsString(
			'>A &lt;b&gt;</a>',
			$this->block()->render(
				array(
					'mode' => 'print',
					'label' => 'A <b>',
				),
				''
			)
		);
	}

	/**
	 * Nothing for the mode of the request, an unknown or missing mode, or disabled modes.
	 *
	 * @return void
	 */
	public function test_renders_nothing(): void {
		$this->assertSame( '', $this->block( array( 'mode' => 'print' ) )->render( array( 'mode' => 'print' ), 'x' ) );
		$this->assertSame( '', $this->block( array( 'print' => '' ) )->render( array( 'mode' => 'print' ), 'x' ) );
		$this->assertSame( '', $this->block()->render( array( 'mode' => 'web' ), 'x' ) );
		$this->assertSame( '', $this->block()->render( array( 'mode' => 'nope' ), 'x' ) );
		$this->assertSame( '', $this->block()->render( array(), 'x' ) );
		$this->assertSame( '', $this->block()->render( array( 'mode' => array( 'print' ) ), 'x' ) );

		update_option( Settings::OPTION, array( 'enabled' => 0 ) );
		$this->assertSame( '', $this->block()->render( array( 'mode' => 'print' ), 'x' ) );
	}

	/**
	 * From the print version, the link to the default mode has no mode in it.
	 *
	 * @return void
	 */
	public function test_link_back_to_web(): void {
		$html = $this->block( array( 'mode' => 'print' ) )->render( array( 'mode' => 'web' ), 'x' );

		$this->assertStringContainsString( 'href="https://example.org/post/?a=1#x"', $html );
	}
}
