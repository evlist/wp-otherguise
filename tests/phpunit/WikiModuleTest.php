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
	 * The hooks of the module, with their priority and number of arguments; outside the administration there is no screen.
	 *
	 * @return void
	 */
	public function test_the_hooks(): void {
		$hooks = array();

		( new Module(
			static function ( $hook, $callback, $priority, $accepted ) use ( &$hooks ) {
				$hooks[ $hook ][] = array( $callback[1], $priority, $accepted );
			}
		) )->boot();

		$this->assertSame(
			array(
				'triples_register_predicates' => array( array( 'register_predicates', 10, 1 ) ),
				'init'                        => array( array( 'register', 10, 0 ), array( 'register', 10, 0 ), array( 'register_block', 10, 0 ), array( 'add_rule', 10, 0 ), array( 'maybe_flush', 99, 0 ), array( 'register_block', 10, 0 ), array( 'register_template', 10, 0 ) ),
				'update_post_metadata'        => array( array( 'lock_space', 10, 4 ) ),
				'delete_post_metadata'        => array( array( 'lock_space', 10, 4 ) ),
				'rest_pre_insert_wiki_page'   => array( array( 'check_rest', 10, 2 ) ),
				'rest_after_insert_wiki_page' => array( array( 'after_rest', 10, 1 ) ),
				'wp_after_insert_post'        => array( array( 'after_insert', 10, 1 ), array( 'after_insert', 10, 1 ) ),
				'cli_init'                    => array( array( 'register_command', 10, 0 ) ),
				'body_class'                  => array( array( 'body_class', 10, 1 ) ),
				'page_template_hierarchy'     => array( array( 'templates', 10, 1 ) ),
				'rest_api_init'               => array( array( 'register', 10, 0 ) ),
				'enqueue_block_editor_assets' => array( array( 'enqueue', 10, 0 ), array( 'enqueue', 10, 0 ) ),
				'rest_pre_insert_page'        => array( array( 'check_rest', 10, 2 ) ),
				'rest_after_insert_page'      => array( array( 'after_rest', 10, 1 ) ),
				'query_vars'                  => array( array( 'query_vars', 10, 1 ) ),
				'parse_request'               => array( array( 'parse_request', 10, 1 ) ),
				'redirect_canonical'          => array( array( 'redirect_canonical', 5, 2 ) ),
				'post_type_link'              => array( array( 'post_link', 10, 2 ) ),
				'404_template_hierarchy'      => array( array( 'hierarchy', 10, 1 ) ),
				'template_include'            => array( array( 'template', 99, 1 ) ),
				'admin_post_wiki_create'      => array( array( 'handle', 10, 0 ) ),
			),
			$hooks
		);
	}

	/**
	 * Activating the plugin adds the rewrite rule and flushes the rules once, and remembers it.
	 *
	 * @return void
	 */
	public function test_activating_flushes_the_rules(): void {
		( new Module() )->activate();

		$names = array_column( $GLOBALS['otherguise_test_calls'], 0 );

		$this->assertSame( array( 'add_rewrite_rule', 'flush_rewrite_rules' ), array_values( array_intersect( $names, array( 'add_rewrite_rule', 'flush_rewrite_rules' ) ) ) );
		$this->assertSame( '1', get_option( 'wiki_rules' ) );

		( new Module() )->uninstall();

		$this->assertFalse( get_option( 'wiki_rules' ) );
	}

	/**
	 * The four predicates of the names, with their limits (slice 501).
	 *
	 * @return void
	 */
	public function test_the_predicates_of_the_names(): void {
		$registry = new class() {
			/**
			 * The definitions registered.
			 *
			 * @var array<string, \Otherguise\Triples\Predicate\PredicateDefinition>
			 */
			public $definitions = array();

			/**
			 * Records a definition.
			 *
			 * @param \Otherguise\Triples\Predicate\PredicateDefinition $definition Definition.
			 * @return void
			 */
			public function register( $definition ) {
				$this->definitions[ $definition->slug() ] = $definition;
			}
		};

		( new Module() )->register_predicates( $registry );

		$limits = array();

		foreach ( $registry->definitions as $slug => $definition ) {
			$limits[ $slug ] = array( $definition->subject_types(), $definition->object_types(), $definition->max_objects_per_subject(), $definition->max_subjects_per_object() );
		}

		$this->assertSame(
			array(
				'wiki/name'        => array( array( 'post' ), array( 'string' ), 1, 1 ),
				'wiki/former-name' => array( array( 'post' ), array( 'string' ), null, 1 ),
				'wiki/folded-name' => array( array( 'post' ), array( 'string' ), 1, null ),
				'wiki/space'       => array( array( 'post' ), array( 'string' ), 1, null ),
				'wiki/key'         => array( array( 'post' ), array( 'string' ), 1, 1 ),
				'wiki/former-key'  => array( array( 'post' ), array( 'string' ), null, 1 ),
				'wiki/folded-key'  => array( array( 'post' ), array( 'string' ), 1, null ),
				'wiki/title'       => array( array( 'post' ), array( 'string' ), 1, null ),
			),
			$limits
		);
	}

	/**
	 * Without the extension intl the screen warns, and says why it matters.
	 *
	 * @return void
	 */
	public function test_the_screen_warns_when_intl_is_missing(): void {
		ob_start();
		( new SettingsScreen( static fn() => true, static fn() => false ) )->render();
		$without = ob_get_clean();

		ob_start();
		( new SettingsScreen( static fn() => true, static fn() => true ) )->render();
		$with = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $without );
		$this->assertStringContainsString( 'intl', $without );
		$this->assertStringNotContainsString( 'notice-warning', $with );
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
