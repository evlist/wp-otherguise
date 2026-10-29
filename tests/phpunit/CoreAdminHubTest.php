<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the hub "Otherguise" of the Settings menu.
 *
 * @package Otherguise
 */

use Otherguise\Core\AdminHub;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress: the screens of the modules, the capabilities and the screen being shown are doubles.
 *
 * @covers \Otherguise\Core\AdminHub
 */
class CoreAdminHubTest extends TestCase {

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
				'url'         => 'tools.php?page=triples',
				'group'       => 'see-also',
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
	 * The hub hooks itself, and adds one entry to the Settings menu.
	 *
	 * @return void
	 */
	public function test_the_entry_of_the_settings_menu(): void {
		$actions = array();
		$filters = array();
		$hub     = new AdminHub(
			null,
			null,
			static function ( $hook, $callback ) use ( &$actions ) {
				$actions[] = array( $hook, $callback[1] );
			},
			static function ( $hook, $callback ) use ( &$filters ) {
				$filters[] = array( $hook, $callback[1] );
			}
		);

		$hub->register();

		$this->assertSame(
			array(
				array( 'admin_menu', 'add' ),
				array( 'admin_enqueue_scripts', 'hide_entries' ),
				array( 'in_admin_header', 'breadcrumb' ),
			),
			$actions
		);
		$this->assertSame(
			array(
				array( 'parent_file', 'parent_file' ),
				array( 'submenu_file', 'submenu_file' ),
			),
			$filters
		);

		$hub->add();

		$menu = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'add_options_page' === $call[0] ) );

		$this->assertCount( 1, $menu );
		$this->assertSame( 'edit_others_posts', $menu[0][3] );
		$this->assertSame( 'otherguise', $menu[0][4] );
		$this->assertSame( 'https://example.test/wp-admin/options-general.php?page=otherguise', AdminHub::url() );
	}

	/**
	 * The lines of the pages of the modules are hidden in the Settings menu by a style; a list of contents and an address have no line there,
	 * and nothing is printed without a page.
	 *
	 * @return void
	 */
	public function test_the_lines_of_the_pages_are_hidden(): void {
		$hub = new AdminHub( fn() => $this->declared() );

		$hub->hide_entries();

		$style = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_add_inline_style' === $call[0] ) );

		$this->assertCount( 1, $style );
		$this->assertSame( 'otherguise-hub', $style[0][1] );
		$this->assertSame( '#adminmenu .wp-submenu li:has(> a[href$="?page=modes"]),#adminmenu .wp-submenu li:has(> a[href$="?page=books"]){display:none;}', $style[0][2] );

		otherguise_test_reset();

		$hub = new AdminHub(
			static fn() => array(
				array(
					'title'     => 'Profiles',
					'post_type' => 'books_printer',
				),
				array(
					'title' => 'Odd',
					'page'  => '"]{}x',
				),
			)
		);

		$hub->hide_entries();

		$style = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_add_inline_style' === $call[0] ) );

		$this->assertSame( '#adminmenu .wp-submenu li:has(> a[href$="?page=x"]){display:none;}', $style[0][2], 'A slug is cleaned before it is in the style.' );

		otherguise_test_reset();
		( new AdminHub( static fn() => array() ) )->hide_entries();

		$this->assertSame( array(), $GLOBALS['otherguise_test_calls'], 'Nothing is added without a page.' );
	}

	/**
	 * The hub lists the screens the user may open, in order, with their line, and puts what is not a setting under "See also".
	 *
	 * @return void
	 */
	public function test_the_hub_lists_the_screens_of_the_user(): void {
		$hub = new AdminHub( fn() => $this->declared(), static fn( $capability ) => 'edit_theme_options' !== $capability );

		ob_start();
		$hub->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1>Otherguise</h1>', $html );
		$this->assertStringContainsString( '<a href="https://example.test/wp-admin/options-general.php?page=books"><strong>Books</strong></a> &mdash; The format &lt;b&gt;of&lt;/b&gt; the page.', $html, 'A page, with its line escaped.' );
		$this->assertStringContainsString( 'edit.php?post_type=books_printer"><strong>Printer profiles</strong>', $html, 'A list of contents.' );
		$this->assertStringNotContainsString( 'Modes', $html, 'A screen the user may not open is not listed.' );
		$this->assertStringNotContainsString( 'No target', $html );
		$this->assertStringNotContainsString( 'nameless', $html );
		$this->assertLessThan( strpos( $html, 'Printer profiles' ), strpos( $html, 'Books' ), 'In the order asked for.' );

		$see = strpos( $html, '<h2>See also</h2>' );

		$this->assertNotFalse( $see );
		$this->assertGreaterThan( $see, strpos( $html, 'tools.php?page=triples"><strong>Relations</strong>' ), 'The relations are under See also.' );
		$this->assertLessThan( $see, strpos( $html, 'Books' ) );
	}

	/**
	 * Without any screen to open, the hub says so and has no "See also" without an entry.
	 *
	 * @return void
	 */
	public function test_the_hub_without_screen(): void {
		$hub = new AdminHub( fn() => array(), static fn() => true );

		ob_start();
		$hub->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No screen of Otherguise is available to you.', $html );
		$this->assertStringNotContainsString( 'See also', $html );
	}

	/**
	 * On a screen of a module the Settings menu stays open on "Otherguise" and a breadcrumb leads back; elsewhere nothing changes, nor on a
	 * screen that is in "See also".
	 *
	 * @return void
	 */
	public function test_the_menu_and_the_breadcrumb_on_a_screen_of_a_module(): void {
		$current = array(
			'page'      => '',
			'post_type' => '',
		);
		$hub     = new AdminHub(
			fn() => $this->declared(),
			static fn() => true,
			null,
			null,
			static function () use ( &$current ) {
				return $current;
			}
		);

		$this->assertSame( 'index.php', $hub->parent_file( 'index.php' ) );
		$this->assertSame( 'edit.php', $hub->submenu_file( 'edit.php' ) );

		ob_start();
		$hub->breadcrumb();

		$this->assertSame( '', ob_get_clean() );

		foreach ( array(
			array( 'page', 'books', 'Books' ),
			array( 'post_type', 'books_printer', 'Printer profiles' ),
		) as $case ) {
			$current = array(
				'page'      => '',
				'post_type' => '',
				$case[0]    => $case[1],
			);

			$this->assertSame( 'options-general.php', $hub->parent_file( 'options.php' ), $case[1] );
			$this->assertSame( 'otherguise', $hub->submenu_file( null ), $case[1] );

			ob_start();
			$hub->breadcrumb();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( '<a href="https://example.test/wp-admin/options-general.php?page=otherguise">Otherguise</a> &rsaquo; ' . $case[2], $html, $case[1] );
		}

		$current = array(
			'page'      => 'triples',
			'post_type' => '',
		);

		$this->assertSame( 'tools.php', $hub->parent_file( 'tools.php' ), 'A tool stays under Tools.' );

		$current = array(
			'page'      => 'otherguise',
			'post_type' => '',
		);

		$this->assertSame( 'options-general.php', $hub->parent_file( 'options-general.php' ), 'The hub is in the Settings menu already.' );
	}
}
