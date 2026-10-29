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
 * No WordPress: the entries of the submenu and the capabilities are doubles.
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
	 * The menu is hooked before the modules, and made of a top-level entry and its overview, which lists what the user may open.
	 *
	 * @return void
	 */
	public function test_the_menu_and_its_overview(): void {
		$hooks = array();
		$menu  = new AdminMenu(
			null,
			null,
			static function ( $hook, $callback, $priority ) use ( &$hooks ) {
				$hooks[] = array( $hook, $callback[1], $priority );
			}
		);

		$menu->register();

		$this->assertSame( array( array( 'admin_menu', 'add', 5 ) ), $hooks, 'Before the modules (priority 10), which need the parent.' );

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
	 * The overview lists the entries of the submenu that the user may open, other than itself, as links to their screens: a page of a module
	 * by its slug, a list of contents by its address.
	 *
	 * @return void
	 */
	public function test_the_overview_lists_the_screens_of_the_user(): void {
		$entries = static fn() => array(
			array( 'Overview', 'edit_others_posts', 'otherguise' ),
			array( 'Modes', 'edit_theme_options', 'modes' ),
			array( 'Books', 'manage_options', 'books' ),
			array( 'Printer <b>profiles</b>', 'edit_others_posts', 'edit.php?post_type=books_printer' ),
			'not an entry',
		);
		$can     = static fn( $capability ) => 'manage_options' !== $capability;
		$menu    = new AdminMenu( $entries, $can );

		ob_start();
		$menu->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1>Otherguise</h1>', $html );
		$this->assertStringContainsString( 'admin.php?page=modes">Modes</a>', $html );
		$this->assertStringContainsString( 'edit.php?post_type=books_printer">Printer profiles</a>', $html, 'A list of contents keeps its address; markup is not printed.' );
		$this->assertStringNotContainsString( 'Books', $html, 'A screen the user may not open is not listed.' );
		$this->assertStringNotContainsString( 'Overview', $html, 'The overview does not list itself.' );
		$this->assertSame( 2, substr_count( $html, '<li>' ) );
	}

	/**
	 * Without any entry the overview says so by showing an empty list, and reads `$submenu` of WordPress by default.
	 *
	 * @return void
	 */
	public function test_the_entries_come_from_the_submenu_of_wordpress(): void {
		$GLOBALS['submenu']['otherguise'] = array(
			array( 'Overview', 'edit_others_posts', 'otherguise' ),
			array( 'Access', 'edit_others_posts', 'access' ),
		);

		ob_start();
		( new AdminMenu( null, static fn() => true ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'admin.php?page=access">Access</a>', $html );

		unset( $GLOBALS['submenu'] );

		ob_start();
		( new AdminMenu( null, static fn() => true ) )->render();

		$this->assertSame( 0, substr_count( (string) ob_get_clean(), '<li>' ) );
	}
}
