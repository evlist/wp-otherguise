<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the items of the list of the units of a section (slice 235).
 *
 * @package Otherguise
 */

use Otherguise\Books\Admin\SettingsScreen;
use Otherguise\Books\Block\SectionListBlock;
use Otherguise\Books\Settings\ListItems;
use Otherguise\Books\Settings\PageFormat;
use Otherguise\Books\Settings\RendererSettings;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: stubs and doubles.
 *
 * @covers \Otherguise\Books\Block\SectionListBlock
 * @covers \Otherguise\Books\Settings\ListItems
 * @covers \Otherguise\Books\Admin\SettingsScreen
 */
class BooksListItemsTest extends TestCase {

	/**
	 * Starts without any option, filter or template part.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * The parts that the theme has.
	 *
	 * @return array<string, string>
	 */
	private function parts() {
		return array(
			'section-item'       => 'Item of a section',
			'section-item-small' => 'Small item',
			'header'             => 'Header',
		);
	}

	/**
	 * What is saved on the screen: slugs, once each, and nothing else.
	 *
	 * @return void
	 */
	public function test_sanitizing(): void {
		$items = new ListItems( fn() => $this->parts() );

		$this->assertSame( array( 'section-item', 'small' ), $items->sanitize( array( 'section-item', 'Section Item', 'Small', 3, '', array( 'x' ) ) ) );
		$this->assertSame( array(), $items->sanitize( 'broken' ) );
	}

	/**
	 * Allowed: the saved slugs and those of the filter; usable: allowed and present in the theme; the choices carry the title of the part.
	 *
	 * @return void
	 */
	public function test_allowed_and_usable(): void {
		$items = new ListItems( fn() => $this->parts() );

		$this->assertSame( array(), $items->allowed() );
		$this->assertFalse( $items->usable( 'section-item' ) );
		$this->assertFalse( $items->usable( '' ) );

		update_option( ListItems::OPTION, array( 'section-item', 'gone', 'section-item' ) );
		$GLOBALS['otherguise_test_filters']['books_list_items'] = array( 'section-item-small', '' );

		$this->assertSame( array( 'section-item', 'gone', 'section-item-small' ), $items->allowed() );
		$this->assertTrue( $items->usable( 'section-item' ) );
		$this->assertFalse( $items->usable( 'gone' ), 'The theme no longer has it.' );
		$this->assertFalse( $items->usable( 'header' ), 'Not allowed.' );
		$this->assertSame(
			array(
				array(
					'value' => 'section-item',
					'label' => 'Item of a section',
				),
				array(
					'value' => 'section-item-small',
					'label' => 'Small item',
				),
			),
			$items->choices()
		);

		update_option( ListItems::OPTION, 'broken' );
		$this->assertSame( array( 'section-item-small' ), $items->allowed() );
	}

	/**
	 * Builds the block with a renderer that records what it is asked.
	 *
	 * @param string   $mode     Mode of the request.
	 * @param string[] $received Receives the markups.
	 * @param string   $html     What the renderer returns.
	 * @return SectionListBlock
	 */
	private function block( $mode, array &$received, $html = '<ul>x</ul>' ) {
		return new SectionListBlock(
			static fn() => $mode,
			static fn() => 'class="w"',
			null,
			new ListItems( fn() => $this->parts() ),
			static function ( $markup, $context ) use ( &$received, $html ) {
				$received[] = array( $markup, $context );

				return $html;
			}
		);
	}

	/**
	 * The list is a Query Loop that lists the units, with the title and the excerpt, or the part chosen when it is usable; it is wrapped, shown in
	 * its modes, and nothing when the loop gives nothing.
	 *
	 * @return void
	 */
	public function test_the_list_block(): void {
		$received = array();
		$web      = $this->block( 'web', $received );

		$this->assertSame( '<div class="w"><ul>x</ul></div>', $web->render( array(), '' ), 'Every mode by default.' );
		$this->assertStringContainsString( '<!-- wp:query ', $received[0][0] );
		$this->assertStringContainsString( '"books_units":true', $received[0][0] );
		$this->assertStringContainsString( '<!-- wp:post-title {"level":3,"isLink":true} /--><!-- wp:post-excerpt /-->', $received[0][0], 'The default item.' );
		$this->assertStringNotContainsString( 'template-part', $received[0][0] );

		$this->assertSame( '<div class="w"><ul>x</ul></div>', $web->render( array( 'modes' => array( 'web' ) ), '' ) );

		$printed = array();
		$print   = $this->block( 'print', $printed );
		$this->assertSame( '', $print->render( array( 'modes' => array( 'web' ) ), '' ), 'Not in print.' );
		$this->assertSame( array(), $printed, 'Nothing is rendered for a mode that is not asked.' );

		$empty = array();
		$this->assertSame( '', $this->block( 'web', $empty, "  \n" )->render( array(), '' ), 'Nothing to show.' );
	}

	/**
	 * The item chosen is used when the settings allow it and the theme has it; else the default.
	 *
	 * @return void
	 */
	public function test_the_item_chosen(): void {
		$received = array();
		$block    = $this->block( 'web', $received );

		$block->render( array( 'item' => 'section-item' ), '' );
		$this->assertStringNotContainsString( 'template-part', $received[0][0], 'Not allowed in the settings yet.' );

		update_option( ListItems::OPTION, array( 'section-item', 'gone' ) );

		$block->render( array( 'item' => 'section-item' ), '' );
		$this->assertStringContainsString( '<!-- wp:template-part {"slug":"section-item"} /-->', $received[1][0] );
		$this->assertStringNotContainsString( 'post-title', $received[1][0] );

		$block->render( array( 'item' => 'gone' ), '' );
		$this->assertStringContainsString( 'post-title', $received[2][0], 'The theme no longer has it: the default.' );

		$block->render( array( 'item' => array( 'x' ) ), '' );
		$this->assertStringContainsString( 'post-title', $received[3][0] );
	}

	/**
	 * Every loop of a page has its own identifier.
	 *
	 * @return void
	 */
	public function test_the_loops_are_told_apart(): void {
		$one = SectionListBlock::markup( '' );
		$two = SectionListBlock::markup( '' );

		preg_match( '/"queryId":(\d+)/', $one, $a );
		preg_match( '/"queryId":(\d+)/', $two, $b );

		$this->assertNotSame( $a[1], $b[1] );
	}

	/**
	 * The screen shows the parts of the theme with a box each, the saved ones checked; it registers the option with its own cleaning.
	 *
	 * @return void
	 */
	public function test_the_screen(): void {
		update_option( ListItems::OPTION, array( 'section-item' ) );

		$items  = new ListItems( fn() => $this->parts() );
		$screen = new SettingsScreen( new PageFormat(), new RendererSettings(), static fn() => true, static fn() => array(), $items );

		ob_start();
		$screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Items of the lists of units', $html );
		$this->assertStringContainsString( 'name="books_list_items[]" value="section-item" checked="checked"', $html );
		$this->assertStringContainsString( 'name="books_list_items[]" value="section-item-small" />', $html );

		$screen->register();
		$registered = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_setting' === $call[0] && ListItems::OPTION === $call[2] ) );

		$this->assertCount( 1, $registered );

		$none = new SettingsScreen( new PageFormat(), new RendererSettings(), static fn() => true, static fn() => array(), new ListItems( static fn() => array() ) );

		ob_start();
		$none->render();
		$this->assertStringContainsString( 'The active theme has no template part.', (string) ob_get_clean() );
	}
}
