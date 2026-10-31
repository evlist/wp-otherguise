<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the menu "Otherguise" of the administration.
 *
 * @package Otherguise
 */

use Otherguise\Core\AdminMenu;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress: the screens of the modules and the capabilities are doubles.
 *
 * @covers \Otherguise\Core\AdminMenu
 */
class CoreAdminMenuTest extends TestCase {

	/**
	 * Starts clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * Cleans up.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		otherguise_test_reset();
	}

	/**
	 * What the modules declare.
	 *
	 * @return array<int, mixed>
	 */
	private function declared() {
		return array(
			array(
				'title'       => 'Books',
				'description' => 'The format <b>of</b> the page.',
				'capability'  => 'manage_options',
				'page'        => 'books',
				'order'       => 20,
			),
			array(
				'title'      => 'Modes',
				'capability' => 'edit_theme_options',
				'page'       => 'modes',
				'order'      => 10,
			),
			array(
				'title'      => 'Printer profiles',
				'capability' => 'edit_others_posts',
				'post_type'  => 'books_printer',
				'order'      => 40,
			),
			array(
				'title'       => 'Relations',
				'description' => 'A tool.',
				'capability'  => 'manage_options',
				'page'        => 'triples',
				'group'       => 'tools',
				'order'       => 60,
			),
			array(
				'title' => 'No target',
				'page'  => '',
			),
			array( 'page' => 'nameless' ),
			'not a screen',
		);
	}

	/**
	 * The menu is hooked before the modules, and made of a top-level entry and its overview.
	 *
	 * @return void
	 */
	public function test_the_menu_and_its_overview_entry(): void {
		$hooks = array();
		$menu  = new AdminMenu(
			null,
			null,
			static function ( $hook, $callback, $priority ) use ( &$hooks ) {
				$hooks[] = array( $hook, $callback[1], $priority );
			}
		);

		$menu->register();

		$this->assertSame(
			array(
				array( 'admin_menu', 'add', 5 ),
				array( 'admin_enqueue_scripts', 'separate', 10 ),
			),
			$hooks,
			'The menu is made before the modules (priority 10 and after), which need the parent.'
		);

		$menu->add();

		$top = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'add_menu_page' === $call[0] ) );
		$sub = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'add_submenu_page' === $call[0] ) );

		$this->assertCount( 1, $top );
		$this->assertSame( 'otherguise', $top[0][4] );
		$this->assertSame( 'edit_others_posts', $top[0][3] );
		$this->assertCount( 1, $sub );
		$this->assertSame( 'otherguise', $sub[0][1], 'The first entry of the submenu is the menu itself.' );
		$this->assertSame( 'Overview', $sub[0][3] );
		$this->assertSame( 'otherguise', $sub[0][5] );
	}

	/**
	 * The overview lists the screens the user may open, in order, with their line, the settings and then the tools.
	 *
	 * @return void
	 */
	public function test_the_overview_lists_the_screens_of_the_user(): void {
		$menu = new AdminMenu( fn() => $this->declared(), static fn( $capability ) => 'edit_theme_options' !== $capability );

		ob_start();
		$menu->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1>Otherguise</h1>', $html );
		$this->assertStringContainsString( '<h2>Settings</h2>', $html );
		$this->assertStringContainsString( '<a href="https://example.test/wp-admin/admin.php?page=books"><strong>Books</strong></a> &mdash; The format &lt;b&gt;of&lt;/b&gt; the page.', $html, 'A page, with its line escaped.' );
		$this->assertStringContainsString( 'edit.php?post_type=books_printer"><strong>Printer profiles</strong>', $html, 'A list of contents.' );
		$this->assertStringNotContainsString( 'Modes', $html, 'A screen the user may not open is not listed.' );
		$this->assertStringNotContainsString( 'No target', $html );
		$this->assertStringNotContainsString( 'nameless', $html );
		$this->assertLessThan( strpos( $html, 'Printer profiles' ), strpos( $html, 'Books' ), 'In the order asked for.' );

		$tools = strpos( $html, '<h2>Tools</h2>' );

		$this->assertNotFalse( $tools );
		$this->assertGreaterThan( $tools, strpos( $html, 'admin.php?page=triples"><strong>Relations</strong>' ), 'The relations are with the tools.' );
		$this->assertLessThan( $tools, strpos( $html, 'Printer profiles' ), 'The settings come first.' );
	}

	/**
	 * Without a screen to open the overview says so; without a tool it has no heading for them.
	 *
	 * @return void
	 */
	public function test_the_overview_without_screen_or_tool(): void {
		ob_start();
		( new AdminMenu( fn() => array(), static fn() => true ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No screen of Otherguise is available to you.', $html );

		ob_start();
		( new AdminMenu( fn() => array_slice( $this->declared(), 0, 1 ), static fn() => true ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h2>Settings</h2>', $html );
		$this->assertStringNotContainsString( '<h2>Tools</h2>', $html );
	}

	/**
	 * A line sets the first tool apart from the settings in the menu, by a style (a submenu has no separator): on a page, or on a list of
	 * contents; nothing without a tool; a slug is cleaned before it is in the style.
	 *
	 * @return void
	 */
	public function test_a_line_sets_the_tools_apart(): void {
		( new AdminMenu( fn() => $this->declared() ) )->separate();

		$style = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_add_inline_style' === $call[0] ) );

		$this->assertCount( 1, $style );
		$this->assertSame( 'otherguise-menu', $style[0][1] );
		$this->assertStringContainsString( '#adminmenu .wp-submenu li:has(> a[href$="?page=triples"]){margin-top:6px;padding-top:6px;border-top:1px solid', $style[0][2] );

		otherguise_test_reset();

		( new AdminMenu(
			static fn() => array(
				array(
					'title'     => 'A list',
					'post_type' => 'books_printer"]{}',
					'group'     => 'tools',
				),
			)
		) )->separate();

		$style = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_add_inline_style' === $call[0] ) );

		$this->assertStringContainsString( 'a[href$="post_type=books_printer"]', $style[0][2], 'A slug is cleaned.' );

		otherguise_test_reset();
		( new AdminMenu( fn() => array_slice( $this->declared(), 0, 3 ) ) )->separate();

		$this->assertSame( array(), $GLOBALS['otherguise_test_calls'], 'Nothing is added without a tool.' );
	}
}
