<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the Books module: the settings screen and the loader of the preview panel (slice 219).
 *
 * @package Otherguise
 */

use Otherguise\Books\Admin\SettingsScreen;
use Otherguise\Books\Module;
use Otherguise\Books\Preview\EditorPreview;
use Otherguise\Books\Settings\PageFormat;
use Otherguise\Books\Settings\RendererSettings;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: the options and the WordPress functions are the stubs of the tests.
 *
 * @covers \Otherguise\Books\Module
 * @covers \Otherguise\Books\Admin\SettingsScreen
 * @covers \Otherguise\Books\Preview\EditorPreview
 */
class BooksModuleTest extends TestCase {

	/**
	 * Starts without any option or recorded call.
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
	 * Returns the calls recorded for a WordPress function.
	 *
	 * @param string $name Function.
	 * @return array<int, array>
	 */
	private function calls( $name ) {
		return array_values(
			array_filter(
				$GLOBALS['otherguise_test_calls'],
				static fn( $call ) => $call[0] === $name
			)
		);
	}

	/**
	 * The module registers the screen, the editor assets and the admin bar filter, and removes its option when uninstalled.
	 *
	 * @return void
	 */
	public function test_the_module(): void {
		$hooks  = array();
		$module = new Module(
			static function ( $hook, $callback, $priority = 10, $accepted = 1 ) use ( &$hooks ) {
				$hooks[ $hook ][] = array( $callback[1], $priority, $accepted );
			},
			static fn( $file ) => 'https://example.test/' . $file
		);

		$this->assertSame( 'books', $module->id() );
		$this->assertSame( array( 'triples', 'modes' ), $module->dependencies() );

		$module->activate();
		$module->boot();

		$this->assertSame(
			array(
				'admin_init'                         => array( array( 'register', 10, 0 ), array( 'schedule_cleanup', 10, 0 ) ),
				'admin_menu'                         => array( array( 'add_menu', 10, 0 ) ),
				'option_page_capability_books_settings_group' => array( array( 'save_capability', 10, 0 ) ),
				'enqueue_block_editor_assets'        => array( array( 'enqueue', 10, 0 ), array( 'enqueue', 10, 0 ) ),
				'show_admin_bar'                     => array( array( 'admin_bar', 10, 1 ) ),
				'rest_api_init'                      => array( array( 'register', 10, 0 ), array( 'register', 10, 0 ) ),
				'triples_register_predicates'        => array( array( 'register_predicates', 10, 1 ) ),
				'before_delete_post'                 => array( array( 'forget_post', 9, 1 ) ),
				'books_render_pdf'                   => array( array( 'run', 10, 3 ) ),
				'books_pdf_cleanup'                  => array( array( 'cleanup', 10, 0 ) ),
				'cli_init'                           => array( array( 'register_command', 10, 0 ) ),
				'init'                               => array( array( 'register', 20, 0 ), array( 'register', 10, 0 ) ),
				'wp_calculate_image_srcset'          => array( array( 'filter', 20, 1 ) ),
				'image_downsize'                     => array( array( 'filter', 100, 3 ) ),
				'wp_head'                            => array( array( 'print_script', 0, 0 ) ),
				'wp_footer'                          => array( array( 'footer', 1, 0 ) ),
				'redirect_canonical'                 => array( array( 'filter', 5, 1 ) ),
				'add_meta_boxes'                     => array( array( 'add', 10, 0 ) ),
				'save_post_books_printer'            => array( array( 'save_profile', 10, 1 ) ),
				'save_post_books_project'            => array( array( 'save_project', 10, 1 ) ),
				'wp_enqueue_scripts'                 => array( array( 'enqueue', 20, 0 ) ),
			),
			$hooks
		);

		$this->assertSame( array(), $module->preview_modes(), 'Without the Modes module: no mode.' );

		update_option( PageFormat::OPTION, array( 'width_mm' => 100 ) );
		update_option( RendererSettings::OPTION, array( 'gotenberg_url' => 'http://x' ) );
		$module->uninstall();
		$this->assertFalse( get_option( PageFormat::OPTION ), 'The option is removed.' );
		$this->assertFalse( get_option( RendererSettings::OPTION ), 'The settings of the server too.' );
		$this->assertCount( 2, $this->calls( 'wp_clear_scheduled_hook' ), 'The cron events are cleared.' );
	}

	/**
	 * The screen registers the option with the sanitizer, adds the page under Settings with the capability, and refuses to print for a user
	 * without the capability.
	 *
	 * @return void
	 */
	public function test_the_settings_screen(): void {
		$allowed = true;
		$asked   = array();
		$screen  = new SettingsScreen(
			new PageFormat(),
			new RendererSettings(),
			static function ( $capability ) use ( &$allowed, &$asked ) {
				$asked[] = $capability;

				return $allowed;
			},
			static fn( $address ) => array(
				'up'      => 'http://up:3000' === $address,
				'message' => 'no answer',
			)
		);

		$this->assertSame( 'manage_options', $screen->capability() );
		$this->assertSame( 'manage_options', $screen->save_capability() );

		$screen->register();
		$register = $this->calls( 'register_setting' );

		$this->assertCount( 2, $register, 'The page format and the rendering server.' );
		$this->assertSame( 'books_renderer', $register[1][2] );
		$this->assertSame( 'books_settings_group', $register[0][1] );
		$this->assertSame( 'books_page_format', $register[0][2] );
		$this->assertSame( 'array', $register[0][3]['type'] );
		$this->assertSame( 148.0, $register[0][3]['default']['width_mm'] );
		$this->assertSame(
			array(
				'width_mm'  => 100.0,
				'height_mm' => 210.0,
				'margin_mm' => 10.0,
			),
			call_user_func( $register[0][3]['sanitize_callback'], array( 'width_mm' => '100' ) ),
			'The sanitizer is the one of the format.'
		);

		$screen->add_menu();
		$menu = $this->calls( 'add_options_page' );

		$this->assertCount( 1, $menu );
		$this->assertSame( 'manage_options', $menu[0][3] );
		$this->assertSame( 'books', $menu[0][4] );

		update_option( PageFormat::OPTION, array( 'width_mm' => 120.5 ) );
		ob_start();
		$screen->render();
		$html = (string) ob_get_clean();

		$this->assertSame( array( 'manage_options' ), $asked );
		$this->assertStringContainsString( 'PDF server', $html );
		$this->assertStringContainsString( 'name="books_renderer[gotenberg_url]" value=""', $html );
		$this->assertStringContainsString( 'name="books_renderer[wait_delay]" value="0"', $html );
		$this->assertStringContainsString( 'name="books_renderer[media]"', $html );
		$this->assertStringContainsString( '<option value="print" selected="selected">', $html );
		$this->assertStringContainsString( 'name="books_renderer[keep_days]" value="7"', $html );
		$this->assertStringNotContainsString( 'The server answers', $html, 'No server, no check.' );
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( 'name="option_page" value="books_settings_group"', $html );
		$this->assertStringContainsString( 'name="books_page_format[width_mm]" value="120.5"', $html );
		$this->assertStringContainsString( 'name="books_page_format[height_mm]" value="210"', $html );
		$this->assertStringContainsString( 'name="books_page_format[margin_mm]" value="10"', $html );

		$allowed = false;
		ob_start();
		$screen->render();
		$this->assertSame( '', (string) ob_get_clean(), 'Without the capability nothing is printed.' );
	}

	/**
	 * Builds the loader of the editor assets.
	 *
	 * @param int  $post_id    Id of the post being edited.
	 * @param bool $can        Whether the user may edit it.
	 * @param bool $in_preview Whether the request carries the argument.
	 * @return EditorPreview
	 */
	private function preview( int $post_id, bool $can, bool $in_preview = false ) {
		return new EditorPreview(
			new PageFormat(),
			static fn() => array(
				array(
					'value' => 'web',
					'label' => 'Web',
				),
				array(
					'value' => 'print',
					'label' => 'Print',
				),
			),
			static fn( $file ) => 'https://example.test/books/' . $file,
			static fn() => $post_id,
			static fn() => $can,
			static fn() => $in_preview
		);
	}

	/**
	 * The assets are enqueued only in the editor of a post that the user may edit, with the format and the modes for the script.
	 *
	 * @return void
	 */
	public function test_the_editor_assets(): void {
		$this->preview( 0, true )->enqueue();
		$this->preview( 12, false )->enqueue();

		$this->assertSame( array(), $this->calls( 'wp_enqueue_script' ), 'No post, or a post that the user may not edit: nothing.' );

		$this->preview( 12, true )->enqueue();

		$scripts = $this->calls( 'wp_enqueue_script' );

		$this->assertCount( 3, $scripts );
		$this->assertSame( 'books-preview-geometry', $scripts[0][1] );
		$this->assertSame( 'https://example.test/books/geometry.js', $scripts[0][2] );
		$this->assertSame( 'books-preview-panel', $scripts[1][1] );
		$this->assertSame( 'https://example.test/books/panel.js', $scripts[1][2] );
		$this->assertSame( array( 'books-preview-geometry' ), $scripts[1][3] );
		$this->assertSame( 'books-preview', $scripts[2][1] );
		$this->assertSame( 'https://example.test/books/preview.js', $scripts[2][2] );
		$this->assertContains( 'books-preview-geometry', $scripts[2][3] );
		$this->assertContains( 'books-preview-panel', $scripts[2][3] );
		$this->assertContains( 'wp-plugins', $scripts[2][3] );
		$this->assertContains( 'wp-components', $scripts[2][3], 'For the button of the toolbar.' );
		$this->assertContains( 'wp-api-fetch', $scripts[2][3], 'For the routes of the PDF preview.' );
		$this->assertNotContains( 'wp-interface', $scripts[2][3], 'Not a script handle of WordPress: a missing dependency drops the script.' );
		$this->assertCount( 1, $this->calls( 'wp_enqueue_style' ) );

		$inline = $this->calls( 'wp_add_inline_script' );

		$this->assertCount( 1, $inline );
		$this->assertSame( 'books-preview', $inline[0][1] );
		$this->assertSame( 'before', $inline[0][3] );
		$this->assertStringStartsWith( 'window.booksPreview = ', $inline[0][2] );

		$data = json_decode( substr( $inline[0][2], strlen( 'window.booksPreview = ' ), -1 ), true );

		$this->assertEquals( 148, $data['page']['width_mm'] );
		$this->assertSame( 'print', $data['defaultMode'] );
		$this->assertSame( array( 'print' ), $data['frameModes'] );
		$this->assertSame( 'books_preview', $data['argument'] );
		$this->assertSame( array( 'web', 'print' ), array_column( $data['modes'], 'value' ) );
	}

	/**
	 * The admin bar is hidden only in the iframe of the preview; the answer of the others is kept.
	 *
	 * @return void
	 */
	public function test_the_admin_bar(): void {
		$this->assertFalse( $this->preview( 1, true, true )->admin_bar( true ) );
		$this->assertTrue( $this->preview( 1, true, false )->admin_bar( true ) );
		$this->assertFalse( $this->preview( 1, true, false )->admin_bar( false ) );
	}

	/**
	 * The defaults read the global post and the query string.
	 *
	 * @return void
	 */
	public function test_the_defaults_of_the_loader(): void {
		$loader = new EditorPreview( new PageFormat(), static fn() => array(), static fn( $file ) => $file );

		unset( $GLOBALS['post'] );
		$this->assertSame( 0, $loader->global_post_id() );

		$GLOBALS['post'] = (object) array( 'ID' => '77' );
		$this->assertSame( 77, $loader->global_post_id() );
		unset( $GLOBALS['post'] );

		$this->assertFalse( $loader->query_has_argument(), 'No such argument in the query string of the tests.' );
	}
}
