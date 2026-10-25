<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the private links (slice 400): the module, the loader of the panel and the public function.
 *
 * @package Otherguise
 */

use Otherguise\Access\Editor\LinkPanel;
use Otherguise\Access\Link\Links;
use Otherguise\Access\Module;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: the hooks, the stubs and the doubles.
 *
 * @covers \Otherguise\Access\Module
 * @covers \Otherguise\Access\Editor\LinkPanel
 */
class AccessModuleTest extends TestCase {

	/**
	 * Starts without any recorded call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * The module is called `access` and depends on nothing.
	 *
	 * @return void
	 */
	public function test_it_depends_on_nothing(): void {
		$module = new Module();

		$this->assertSame( 'access', $module->id() );
		$this->assertSame( array(), $module->dependencies() );
		$this->assertNull( $module->activate() );
		$this->assertSame( $module->links(), $module->links(), 'The same links each time.' );
	}

	/**
	 * The hooks, with their priority and the number of arguments.
	 *
	 * @return void
	 */
	public function test_the_hooks(): void {
		$hooks  = array();
		$module = new Module(
			static function ( $hook, $callback, $priority, $accepted ) use ( &$hooks ) {
				$hooks[ $hook ][] = array( $callback[1], $priority, $accepted );
			}
		);

		$module->boot();

		$this->assertSame(
			array(
				'init'                     => array( array( 'enable_links', 1, 0 ) ),
				'wxr_export_skip_postmeta' => array( array( 'skip_in_export', 10, 2 ) ),
				'cli_init'                 => array( array( 'register_command', 10, 0 ) ),
			),
			$hooks,
			'Outside the administration, and before the settings are read, only these three.'
		);

		$hooks = array();
		$module->enable_links();

		$this->assertSame(
			array(
				'query_vars'                  => array( array( 'query_vars', 10, 1 ) ),
				'parse_request'               => array( array( 'parse_request', 10, 1 ) ),
				'posts_pre_query'             => array( array( 'posts_pre_query', 10, 2 ) ),
				'redirect_canonical'          => array( array( 'redirect_canonical', 10, 1 ) ),
				'send_headers'                => array( array( 'send_headers', 10, 0 ) ),
				'wp_robots'                   => array( array( 'robots', 10, 1 ) ),
				'rest_api_init'               => array( array( 'register', 10, 0 ) ),
				'enqueue_block_editor_assets' => array( array( 'enqueue', 10, 0 ) ),
			),
			$hooks
		);
	}

	/**
	 * Switched off, the module registers none of the hooks of the links (slice 401).
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_when_the_links_are_off(): void {
		update_option( 'access_settings', array( 'links' => false ) );

		$hooks  = array();
		$module = new Module(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
			}
		);

		$module->enable_links();

		$this->assertSame( array(), $hooks );
	}

	/**
	 * The tokens stay out of the export of WordPress, and nothing else is skipped by this module.
	 *
	 * @return void
	 */
	public function test_the_tokens_stay_out_of_the_export(): void {
		$module = new Module();

		$this->assertTrue( $module->skip_in_export( false, Links::TOKEN ) );
		$this->assertTrue( $module->skip_in_export( false, Links::CREATED ) );
		$this->assertFalse( $module->skip_in_export( false, '_thumbnail_id' ) );
		$this->assertTrue( $module->skip_in_export( true, '_thumbnail_id' ), 'What another filter skipped stays skipped.' );
	}

	/**
	 * Removing the plugin deletes every token and every date.
	 *
	 * @return void
	 */
	public function test_uninstalling_deletes_every_token(): void {
		update_option( 'access_settings', array( 'links' => false ) );

		( new Module() )->uninstall();

		$calls = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'delete_post_meta_by_key' === $call[0] ) );

		$this->assertSame( array( array( 'delete_post_meta_by_key', '_access_token' ), array( 'delete_post_meta_by_key', '_access_token_created' ) ), $calls );
		$this->assertFalse( get_option( 'access_settings' ), 'The option is deleted too.' );
	}

	/**
	 * Returns the calls recorded for a WordPress function.
	 *
	 * @param string $name Function.
	 * @return array<int, array>
	 */
	private function calls( $name ) {
		return array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => $call[0] === $name ) );
	}

	/**
	 * Builds the loader with doubles.
	 *
	 * @param int  $post_id Post being edited.
	 * @param bool $can     Whether the user may edit it.
	 * @return LinkPanel
	 */
	private function panel( $post_id, $can ): LinkPanel {
		return new LinkPanel(
			static fn( $file ) => 'https://example.test/link/' . $file,
			static fn() => $post_id,
			static fn( $capability, $id ) => $can && 'edit_post' === $capability && $id === $post_id,
			static fn() => array( 'post', 'page' )
		);
	}

	/**
	 * The scripts, the style and the settings are loaded for a post that the user may edit.
	 *
	 * @return void
	 */
	public function test_the_panel_is_loaded(): void {
		$this->panel( 12, true )->enqueue();

		$scripts = $this->calls( 'wp_enqueue_script' );

		$this->assertSame( array( 'access-link-panel', 'access-link' ), array_column( $scripts, 1 ) );
		$this->assertSame( 'https://example.test/link/link-panel.js', $scripts[0][2] );
		$this->assertSame( 'https://example.test/link/link.js', $scripts[1][2] );
		$this->assertContains( 'access-link-panel', $scripts[1][3] );

		foreach ( array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-i18n', 'wp-api-fetch' ) as $dependency ) {
			$this->assertContains( $dependency, $scripts[1][3] );
		}

		$this->assertCount( 1, $this->calls( 'wp_enqueue_style' ) );

		$inline = $this->calls( 'wp_add_inline_script' );

		$this->assertSame( 'window.accessLink = {"path":"\/access\/v1\/posts","postTypes":["post","page"]};', $inline[0][2] );
		$this->assertSame( 'before', $inline[0][3] );
	}

	/**
	 * Nothing is loaded without a post, or for a post that the user may not edit.
	 *
	 * @return void
	 */
	public function test_the_panel_is_not_loaded_otherwise(): void {
		$this->panel( 0, true )->enqueue();
		$this->panel( 12, false )->enqueue();

		$this->assertSame( array(), $this->calls( 'wp_enqueue_script' ) );
		$this->assertSame( array(), $this->calls( 'wp_add_inline_script' ) );

		$panel = new LinkPanel( static fn( $file ) => $file );

		unset( $GLOBALS['post'] );
		$this->assertSame( 0, $panel->global_post_id() );

		$GLOBALS['post'] = new WP_Post( 31 );
		$this->assertSame( 31, $panel->global_post_id() );

		unset( $GLOBALS['post'] );
	}

	/**
	 * The public function answers false when the module is not there.
	 *
	 * @return void
	 */
	public function test_the_public_function_without_the_module(): void {
		require_once dirname( __DIR__, 2 ) . '/plugin/modules/access/functions.php';

		$this->assertTrue( function_exists( 'access_url' ) );
		$this->assertFalse( access_url( 12 ), 'The module is not registered in this test.' );
		$this->assertFalse( access_url( (object) array( 'ID' => 12 ), array( 'create' => true ) ) );
	}
}
