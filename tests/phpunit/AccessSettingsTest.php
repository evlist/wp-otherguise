<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the settings of the Access module (slice 401).
 *
 * @package Otherguise
 */

use Otherguise\Access\Admin\SettingsScreen;
use Otherguise\Access\Cli\LinksCommand;
use Otherguise\Access\Link\Links;
use Otherguise\Access\Rest\LinkRoutes;
use Otherguise\Access\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: stubs and doubles.
 *
 * @covers \Otherguise\Access\Settings\Settings
 * @covers \Otherguise\Access\Admin\SettingsScreen
 * @covers \Otherguise\Access\Cli\LinksCommand
 */
class AccessSettingsTest extends TestCase {

	/**
	 * Starts without any option, filter or recorded call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * A site that never saved the screen has the defaults: everything on, the post's own capability.
	 *
	 * @return void
	 */
	public function test_the_defaults(): void {
		$settings = new Settings();

		$this->assertTrue( $settings->links_enabled() );
		$this->assertTrue( $settings->unlisted_enabled() );
		$this->assertSame( 'edit_post', $settings->capability() );
	}

	/**
	 * What is stored is read; an option that is not an array, or that holds foreign keys, changes nothing else.
	 *
	 * @return void
	 */
	public function test_the_stored_values(): void {
		update_option(
			'access_settings',
			array(
				'links' => false,
				'capability' => 'manage_options',
				'unlisted' => false,
				'other' => 1,
			)
		);

		$settings = new Settings();

		$this->assertFalse( $settings->links_enabled() );
		$this->assertFalse( $settings->unlisted_enabled() );
		$this->assertSame( 'manage_options', $settings->capability() );
		$this->assertArrayNotHasKey( 'other', $settings->get() );

		update_option( 'access_settings', 'broken' );
		$this->assertTrue( $settings->links_enabled() );
	}

	/**
	 * The filters have the last word, and a capability that is not on the list is ignored.
	 *
	 * @return void
	 */
	public function test_the_filters(): void {
		$GLOBALS['otherguise_test_filters']['access_links_enabled']    = false;
		$GLOBALS['otherguise_test_filters']['access_unlisted_enabled'] = false;
		$GLOBALS['otherguise_test_filters']['access_link_capability']  = 'manage_options';

		$settings = new Settings();

		$this->assertFalse( $settings->links_enabled() );
		$this->assertFalse( $settings->unlisted_enabled() );
		$this->assertSame( 'manage_options', $settings->capability() );

		$GLOBALS['otherguise_test_filters']['access_link_capability'] = 'delete_users';
		$this->assertSame( 'edit_post', $settings->capability() );

		update_option( 'access_settings', array( 'capability' => array( 'x' ) ) );
		unset( $GLOBALS['otherguise_test_filters']['access_link_capability'] );
		$this->assertSame( 'edit_post', $settings->capability() );
	}

	/**
	 * Saving: booleans, a capability from the list, nothing else.
	 *
	 * @return void
	 */
	public function test_sanitizing(): void {
		$settings = new Settings();

		$this->assertSame(
			array(
				'links' => true,
				'capability' => 'edit_others_posts',
				'unlisted' => false,
			),
			$settings->sanitize(
				array(
					'links' => '1',
					'capability' => 'edit_others_posts',
					'evil' => 'x',
				)
			)
		);
		$this->assertSame(
			array(
				'links' => false,
				'capability' => 'edit_post',
				'unlisted' => true,
			),
			$settings->sanitize(
				array(
					'capability' => 'delete_users',
					'unlisted' => 1,
				)
			),
			'An unchecked box is absent from the form; a capability that is not on the list falls back.'
		);
		$this->assertSame(
			array(
				'links' => false,
				'capability' => 'edit_post',
				'unlisted' => false,
			),
			$settings->sanitize( 'x' )
		);
		$this->assertSame(
			array(
				'links' => false,
				'capability' => 'edit_post',
				'unlisted' => false,
			),
			$settings->sanitize( array( 'capability' => array( 'a' ) ) )
		);
	}

	/**
	 * Returns settings whose user has the capabilities given, with the post 5 editable.
	 *
	 * @param string[] $granted Capabilities of the user.
	 * @return Settings
	 */
	private function settings_for( array $granted ): Settings {
		return new Settings(
			static function ( $capability, $id = 0 ) use ( $granted ) {
				if ( 'edit_post' === $capability ) {
					return 5 === $id && in_array( 'edit_post', $granted, true );
				}

				return in_array( $capability, $granted, true );
			}
		);
	}

	/**
	 * The server decides who may share: the post's own capability by default, a stricter one when set, nobody when the links are off.
	 *
	 * @return void
	 */
	public function test_who_may_share(): void {
		$author = $this->settings_for( array( 'edit_post' ) );
		$editor = $this->settings_for( array( 'edit_post', 'edit_others_posts' ) );
		$admin  = $this->settings_for( array( 'edit_post', 'edit_others_posts', 'manage_options' ) );
		$nobody = $this->settings_for( array() );

		$this->assertTrue( $author->may_share( 5 ) );
		$this->assertFalse( $author->may_share( 6 ), 'Not a post the user may edit.' );
		$this->assertFalse( $author->may_share( 0 ) );
		$this->assertFalse( $nobody->may_share( 5 ) );

		update_option( 'access_settings', array( 'capability' => 'edit_others_posts' ) );
		$this->assertFalse( $author->may_share( 5 ) );
		$this->assertTrue( $editor->may_share( 5 ) );

		update_option( 'access_settings', array( 'capability' => 'manage_options' ) );
		$this->assertFalse( $editor->may_share( 5 ) );
		$this->assertTrue( $admin->may_share( 5 ) );
		$this->assertFalse( $this->settings_for( array( 'manage_options' ) )->may_share( 5 ), 'An administrator who cannot edit the post cannot share it.' );

		update_option( 'access_settings', array( 'links' => false ) );
		$this->assertFalse( $admin->may_share( 5 ), 'The links are off.' );
	}

	/**
	 * The route answers according to the setting it is given.
	 *
	 * @return void
	 */
	public function test_the_routes_use_the_setting(): void {
		$settings = $this->settings_for( array( 'edit_post' ) );
		$routes   = new LinkRoutes( new Links(), static fn( $id ) => $settings->may_share( (int) $id ) );
		$request  = new class() {
			/**
			 * Parameter.
			 *
			 * @param string $name Name.
			 * @return int
			 */
			public function get_param( $name ) {
				unset( $name );

				return 5;
			}
		};

		$this->assertTrue( $routes->can( $request ) );

		update_option( 'access_settings', array( 'capability' => 'manage_options' ) );
		$this->assertFalse( $routes->can( $request ) );
	}

	/**
	 * The menu entry, the setting, the capability of the saving.
	 *
	 * @return void
	 */
	public function test_the_screen_is_registered(): void {
		$hooks  = array();
		$screen = new SettingsScreen( new Settings() );

		$screen->register(
			static function ( $hook, $callback, $priority, $accepted ) use ( &$hooks ) {
				$hooks[ $hook ] = array( $callback[1], $priority, $accepted );
			}
		);

		$this->assertSame(
			array(
				'admin_menu'                               => array( 'add_menu', 10, 0 ),
				'otherguise_admin_screens'                 => array( 'declare_screen', 10, 1 ),
				'admin_init'                               => array( 'register_setting', 10, 0 ),
				'option_page_capability_access_settings_group' => array( 'capability', 10, 0 ),
			),
			$hooks
		);

		$screen->add_menu();
		$screen->register_setting();

		$menu = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'add_options_page' === $call[0] ) );

		$this->assertSame( 'manage_options', $menu[0][3] );
		$this->assertSame( 'access', $menu[0][4] );

		$screens = $screen->declare_screen( array( 'kept' ) );

		$this->assertSame( 'kept', $screens[0] );
		$this->assertSame(
			array(
				'title'       => 'Access',
				'description' => 'Private links to posts that are not published, and posts that are published but unlisted.',
				'capability'  => 'manage_options',
				'page'        => 'access',
				'order'       => 30,
			),
			$screens[1],
			'The screen declared to the hub.'
		);
		$this->assertCount( 1, $screen->declare_screen( 'not an array' ) );

		$registered = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_setting' === $call[0] ) );

		$this->assertSame( 'access_settings_group', $registered[0][1] );
		$this->assertSame( 'access_settings', $registered[0][2] );
		$this->assertSame( 'manage_options', $screen->capability() );

		$GLOBALS['otherguise_test_filters']['access_admin_capability'] = 'edit_theme_options';
		$this->assertSame( 'edit_theme_options', $screen->capability() );

		$GLOBALS['otherguise_test_filters']['access_admin_capability'] = '';
		$this->assertSame( 'manage_options', $screen->capability() );
	}

	/**
	 * The form shows the values, through the Settings API, and only to someone who may.
	 *
	 * @return void
	 */
	public function test_the_form(): void {
		update_option(
			'access_settings',
			array(
				'links' => true,
				'capability' => 'edit_others_posts',
				'unlisted' => false,
			)
		);

		ob_start();
		( new SettingsScreen( new Settings(), static fn() => true ) )->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="option_page" value="access_settings_group"', $html );
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( 'name="access_settings[links]" value="1" checked="checked"', $html );
		$this->assertStringContainsString( '<option value="edit_others_posts" selected="selected">', $html );
		$this->assertStringNotContainsString( 'name="access_settings[unlisted]" value="1" checked', $html );

		ob_start();
		( new SettingsScreen( new Settings(), static fn() => false ) )->render();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Switched off, the command makes no link, but lists and revokes still work (to clean up).
	 *
	 * @return void
	 */
	public function test_the_command_refuses_to_make_a_link_when_the_links_are_off(): void {
		$errors  = array();
		$command = new LinksCommand(
			new Links(),
			static fn( $id ) => new WP_Post( (int) $id ),
			static fn() => null,
			static fn() => null,
			static function ( $message ) use ( &$errors ) {
				$errors[] = $message;
			},
			static fn() => false
		);

		$command->create( array( '5' ), array() );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'switched off', $errors[0] );
	}
}
