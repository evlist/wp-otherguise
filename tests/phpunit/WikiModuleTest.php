<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the wiki module and of the screen of its spaces (slice 500).
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Admin\SettingsScreen;
use Otherguise\Wiki\Module;
use Otherguise\Wiki\Space\Spaces;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: stubs and doubles.
 *
 * @covers \Otherguise\Wiki\Module
 * @covers \Otherguise\Wiki\Admin\SettingsScreen
 */
class WikiModuleTest extends TestCase {

	/**
	 * Starts without any option, filter or recorded call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * The module is called `wiki` and depends on Triples, and only on it.
	 *
	 * @return void
	 */
	public function test_it_depends_on_triples(): void {
		$module = new Module();

		$this->assertSame( 'wiki', $module->id() );
		$this->assertSame( array( 'triples' ), $module->dependencies() );
		$this->assertNull( $module->activate() );
	}

	/**
	 * Outside the administration the module hooks nothing.
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_outside_the_administration(): void {
		$hooks = array();

		( new Module(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
			}
		) )->boot();

		$this->assertSame( array(), $hooks );
	}

	/**
	 * Removing the plugin deletes the spaces.
	 *
	 * @return void
	 */
	public function test_uninstalling_deletes_the_spaces(): void {
		update_option( Spaces::OPTION, array( array( 'name' => 'Biography' ) ) );

		( new Module() )->uninstall();

		$this->assertFalse( get_option( Spaces::OPTION ) );
	}

	/**
	 * The menu entry, the setting, the capability of the saving.
	 *
	 * @return void
	 */
	public function test_the_screen_is_registered(): void {
		$hooks  = array();
		$screen = new SettingsScreen();

		$screen->register(
			static function ( $hook, $callback, $priority, $accepted ) use ( &$hooks ) {
				$hooks[ $hook ] = array( $callback[1], $priority, $accepted );
			}
		);

		$this->assertSame(
			array(
				'admin_menu'                                => array( 'add_menu', 10, 0 ),
				'otherguise_admin_screens'                  => array( 'declare_screen', 10, 1 ),
				'admin_init'                                => array( 'register_setting', 10, 0 ),
				'option_page_capability_wiki_settings_group' => array( 'capability', 10, 0 ),
			),
			$hooks
		);

		$screen->add_menu();
		$screen->register_setting();

		$menu = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'add_submenu_page' === $call[0] ) );

		$this->assertSame( 'otherguise', $menu[0][1], 'Under the Otherguise menu.' );
		$this->assertSame( 'Wiki', $menu[0][3] );
		$this->assertSame( 'manage_options', $menu[0][4] );
		$this->assertSame( 'wiki', $menu[0][5] );

		$screens = $screen->declare_screen( array( 'kept' ) );

		$this->assertSame( 'kept', $screens[0] );
		$this->assertSame( 'Wiki', $screens[1]['title'] );
		$this->assertSame( 'wiki', $screens[1]['page'] );
		$this->assertSame( 'settings', $screens[1]['group'] );
		$this->assertSame( 40, $screens[1]['order'] );
		$this->assertCount( 1, $screen->declare_screen( 'not an array' ) );

		$registered = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_setting' === $call[0] ) );

		$this->assertSame( 'wiki_settings_group', $registered[0][1] );
		$this->assertSame( 'wiki_spaces', $registered[0][2] );
		$this->assertSame( array( Spaces::class, 'sanitize' ), $registered[0][3]['sanitize_callback'] );

		$GLOBALS['otherguise_test_filters']['wiki_admin_capability'] = 'edit_theme_options';
		$this->assertSame( 'edit_theme_options', $screen->capability() );

		$GLOBALS['otherguise_test_filters']['wiki_admin_capability'] = '';
		$this->assertSame( 'manage_options', $screen->capability() );
	}

	/**
	 * The form shows each space, with the name it has, and an empty row to add one; only to someone who may.
	 *
	 * @return void
	 */
	public function test_the_form(): void {
		update_option(
			Spaces::OPTION,
			array(
				array(
					'name' => 'Biography',
					'label' => 'Auto"biography',
					'order' => 10,
				),
			)
		);

		ob_start();
		( new SettingsScreen( static fn() => true ) )->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="option_page" value="wiki_settings_group"', $html );
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( 'name="wiki_spaces[0][was]" value="Biography"', $html );
		$this->assertStringContainsString( 'name="wiki_spaces[0][name]" value="Biography"', $html );
		$this->assertStringContainsString( 'value="Auto&quot;biography"', $html, 'Escaped.' );
		$this->assertStringContainsString( 'name="wiki_spaces[0][remove]"', $html );
		$this->assertStringContainsString( 'name="wiki_spaces[1][name]" value=""', $html, 'The empty row.' );
		$this->assertStringNotContainsString( 'name="wiki_spaces[1][remove]"', $html );
		$this->assertCount( 1, array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'settings_errors' === $call[0] ) );

		ob_start();
		( new SettingsScreen( static fn() => false ) )->render();
		$this->assertSame( '', ob_get_clean() );
	}
}
