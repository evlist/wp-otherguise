<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the administration screen of the modes, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Admin\Admin;
use Otherguise\Modes\Admin\AdminActions;
use Otherguise\Modes\Admin\AdminPage;
use Otherguise\Modes\Admin\SettingsPanel;
use Otherguise\Modes\Admin\VariantsScreen;
use Otherguise\Modes\Module;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-environment.php';

/**
 * The handlers (capability, nonce, effect, refusals as notices) and what the page prints.
 *
 * @covers \Otherguise\Modes\Admin\Admin
 * @covers \Otherguise\Modes\Admin\AdminActions
 * @covers \Otherguise\Modes\Admin\AdminPage
 * @covers \Otherguise\Modes\Admin\VariantsScreen
 * @covers \Otherguise\Modes\Template\TemplateLookup
 */
class ModesAdminDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Modules wired together.
	 *
	 * @var Otherguise_Test_Modes_Site
	 */
	private $site;

	/**
	 * Environment.
	 *
	 * @var Otherguise_Test_Modes_Environment
	 */
	private $environment;

	/**
	 * Handlers.
	 *
	 * @var AdminActions
	 */
	private $actions;

	/**
	 * Page.
	 *
	 * @var AdminPage
	 */
	private $page;

	/**
	 * Builds the site with a few templates and the screen on a test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->site = new Otherguise_Test_Modes_Site( $this->wpdb );

		foreach ( array( 'single', 'single-print', 'page', 'page-print' ) as $slug ) {
			$this->site->template( $slug, 'wp_template', 'Title of ' . $slug );
		}

		foreach ( array( 'header', 'header-print' ) as $slug ) {
			$this->site->template( $slug, 'wp_template_part' );
		}

		$variants          = $this->site->modes->variants();
		$this->environment = new Otherguise_Test_Modes_Environment();
		$this->actions     = new AdminActions( $this->environment, $variants, $this->site->modes->modes() );
		$this->page        = new AdminPage( $this->environment, new VariantsScreen( $this->environment, $this->site->modes->modes(), $variants, $this->site->lookup, new SettingsPanel( $this->environment, $this->site->modes->settings() ) ) );
	}

	/**
	 * Runs a handler and returns the URL it redirects to.
	 *
	 * @param string $method Handler.
	 * @return string
	 */
	private function redirect_of( $method ) {
		try {
			$this->actions->{$method}();
		} catch ( Otherguise_Test_Redirect $redirect ) {
			return $redirect->url;
		}

		$this->fail( 'The handler should have redirected.' );
	}

	/**
	 * Tells whether a handler refused the request.
	 *
	 * @param string $method Handler.
	 * @return bool
	 */
	private function is_denied( $method ) {
		try {
			$this->actions->{$method}();
		} catch ( Otherguise_Test_Denied $denied ) {
			return true;
		} catch ( Otherguise_Test_Redirect $redirect ) {
			return false;
		}

		return false;
	}

	/**
	 * The fields of a form that declares single-print as the print variant of single.
	 *
	 * @return array
	 */
	private function fields() {
		return array(
			'kind'    => 'template',
			'source'  => 'twentytwentyfive//single',
			'variant' => 'twentytwentyfive//single-print',
			'mode'    => 'print',
		);
	}

	/**
	 * Counts the statements.
	 *
	 * @return int
	 */
	private function total() {
		return count( $this->site->statements->match() );
	}

	/**
	 * Prints the page and returns the HTML.
	 *
	 * @param array $query Query string.
	 * @return string
	 */
	private function html( array $query = array() ) {
		$this->environment->get = $query;

		ob_start();
		$this->page->render();

		return (string) ob_get_clean();
	}

	/**
	 * Without the capability, or with a bad nonce, nothing is read or written.
	 *
	 * @return void
	 */
	public function test_capability_and_nonce(): void {
		foreach ( array(
			'declare_variant'  => 'modes_declare',
			'withdraw_variant' => 'modes_withdraw',
			'remove_variant'   => 'modes_remove',
		) as $method => $action ) {
			$this->environment->allowed = false;
			$this->environment->post_with_nonce( $action, $this->fields() );
			$this->assertTrue( $this->is_denied( $method ), $method . ' without the capability' );

			$this->environment->allowed = true;
			$this->environment->post    = $this->fields();
			$this->assertTrue( $this->is_denied( $method ), $method . ' without a nonce' );

			$this->environment->post_with_nonce( 'modes_other', $this->fields() );
			$this->assertTrue( $this->is_denied( $method ), $method . ' with the nonce of another action' );
		}

		$this->assertSame( 0, $this->total() );

		$this->environment->post_with_nonce( 'modes_withdraw', $this->fields() );
		$this->assertTrue( $this->is_denied( 'declare_variant' ), 'The nonce of withdrawing does not declare.' );
		$this->assertSame( 0, $this->total() );
	}

	/**
	 * Declaring stores the variant and says so; declaring again is harmless.
	 *
	 * @return void
	 */
	public function test_declare(): void {
		$this->environment->post_with_nonce( 'modes_declare', $this->fields() );

		$this->assertStringContainsString( 'options-general.php?page=modes&modes_notice=declared', $this->redirect_of( 'declare_variant' ) );
		$this->assertSame( 2, $this->total() );

		$this->assertStringContainsString( 'modes_notice=declared', $this->redirect_of( 'declare_variant' ) );
		$this->assertSame( 2, $this->total() );
	}

	/**
	 * What the rules refuse comes back as a notice with a code, and nothing is stored.
	 *
	 * @return void
	 */
	public function test_refusals_are_notices(): void {
		$cases = array(
			'same_template'       => array( 'variant' => 'twentytwentyfive//single' ),
			'theme_mismatch'      => array( 'variant' => 'othertheme//single-print' ),
			'triples_object_missing' => array( 'variant' => 'twentytwentyfive//ghost' ),
			'invalid_request'     => array( 'kind' => 'post' ),
		);

		foreach ( $cases as $code => $change ) {
			$this->environment->post_with_nonce( 'modes_declare', $change + $this->fields() );

			$this->assertStringContainsString( 'modes_notice=error&code=' . $code, $this->redirect_of( 'declare_variant' ), $code );
		}

		foreach ( array(
			array( 'mode' => 'ghost' ),
			array( 'mode' => '' ),
			array( 'source' => 'not an id' ),
			array( 'variant' => '' ),
			array( 'kind' => 'template_part' ),
		) as $change ) {
			$this->environment->post_with_nonce( 'modes_declare', $change + $this->fields() );

			$expected = array( 'kind' => 'template_part' ) === $change ? 'triples_subject_missing' : 'invalid_request';

			$this->assertStringContainsString( 'code=' . $expected, $this->redirect_of( 'declare_variant' ), implode( ',', array_keys( $change ) ) );
		}

		$this->assertSame( 0, $this->total() );

		$this->environment->post_with_nonce( 'modes_declare', $this->fields() );
		$this->redirect_of( 'declare_variant' );
		$this->environment->post_with_nonce( 'modes_declare', array( 'variant' => 'twentytwentyfive//page-print' ) + $this->fields() );

		$this->assertStringContainsString( 'code=mode_already_served', $this->redirect_of( 'declare_variant' ) );
	}

	/**
	 * Withdrawing a mode and removing a relation.
	 *
	 * @return void
	 */
	public function test_withdraw_and_remove(): void {
		$this->environment->post_with_nonce( 'modes_declare', $this->fields() );
		$this->redirect_of( 'declare_variant' );
		$this->environment->post_with_nonce( 'modes_declare', array( 'mode' => 'web' ) + $this->fields() );
		$this->redirect_of( 'declare_variant' );

		$this->environment->post_with_nonce( 'modes_withdraw', array( 'mode' => 'web' ) + $this->fields() );
		$this->assertStringContainsString( 'modes_notice=withdrawn', $this->redirect_of( 'withdraw_variant' ) );
		$this->assertSame( 2, $this->total() );

		$this->environment->post_with_nonce( 'modes_remove', $this->fields() );
		$this->assertStringContainsString( 'modes_notice=removed', $this->redirect_of( 'remove_variant' ) );
		$this->assertSame( 0, $this->total() );
	}

	/**
	 * The page: the modes, how to reach them, the variants with their modes and their forms, escaped.
	 *
	 * @return void
	 */
	public function test_the_page(): void {
		$this->site->template( 'about', 'wp_template', '<script>alert(1)</script>' );

		$variants = $this->site->modes->variants();
		$print    = $this->site->modes->modes()->get( 'print' );
		$variants->declare( $variants->template( 'single' ), $print, $variants->template( 'single-print' ) );
		$variants->declare( $variants->template( 'single' ), $this->site->modes->modes()->get( 'web' ), $variants->template( 'single-print' ) );
		$variants->declare( $variants->template( 'about' ), $print, $variants->template( 'page-print' ) );
		$variants->declare( $variants->part( 'header' ), $print, $variants->part( 'header-print' ) );

		$html = $this->html();

		$this->assertStringContainsString( '<h1>Otherguise modes</h1>', $html );
		$this->assertStringContainsString( '<code>?mode=print</code>, <code>?print</code>', $html );
		$this->assertStringContainsString( 'http://example.test/?mode=print', $html );
		$this->assertStringContainsString( 'Title of single (single)', $html . $this->environment->admin_url( '' ) );
		$this->assertStringContainsString( 'name="action" value="modes_withdraw"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce:modes_withdraw"', $html );
		$this->assertStringContainsString( 'value="Withdraw from Print"', $html );
		$this->assertStringContainsString( 'value="Withdraw from Web"', $html );
		$this->assertStringContainsString( 'name="action" value="modes_remove"', $html );
		$this->assertStringContainsString( 'name="action" value="modes_declare"', $html );
		$this->assertStringContainsString( '<option value="twentytwentyfive//page-print">Title of page-print (page-print)</option>', $html );
		$this->assertStringContainsString( '<option value="twentytwentyfive//header-print">header-print</option>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertSame( 1, substr_count( $html, '>Template parts<' ) );
		$this->assertStringContainsString( 'twentytwentyfive//single-print', $html );
	}

	/**
	 * A relation whose template disappeared is shown as missing.
	 *
	 * @return void
	 */
	public function test_a_missing_template_is_marked(): void {
		$variants = $this->site->modes->variants();
		$variants->declare( $variants->template( 'single' ), $this->site->modes->modes()->get( 'print' ), $variants->template( 'single-print' ) );

		$this->assertStringNotContainsString( '(missing)', $this->html() );

		unset( $this->site->lookup->templates['wp_template|twentytwentyfive//single-print'] );

		$this->assertStringContainsString( '(missing)', $this->html() );
	}

	/**
	 * Without variants the page says so, and without templates to choose from the form is not offered.
	 *
	 * @return void
	 */
	public function test_empty_states(): void {
		$html = $this->html();

		$this->assertSame( 2, substr_count( $html, 'No variant declared.' ) );
		$this->assertStringContainsString( 'name="action" value="modes_declare"', $html );

		$this->site->lookup->templates = array();

		$this->assertStringContainsString( 'The active theme has nothing to choose from.', $this->html() );
		$this->assertStringNotContainsString( 'name="action" value="modes_declare"', $this->html() );
	}

	/**
	 * The setting that enables the modes: the form, its state, the warning, and the option registered with the Settings API.
	 *
	 * @return void
	 */
	public function test_the_setting_that_enables_the_modes(): void {
		$html = $this->html();

		$this->assertStringContainsString( '<form method="post" action="http://example.test/wp-admin/options.php">', $html );
		$this->assertStringContainsString( 'name="option_page" value="modes_settings_group"', $html );
		$this->assertStringContainsString( 'name="modes_settings[enabled]" value="1" checked="checked"', $html );
		$this->assertStringNotContainsString( 'The modes are disabled', $html );

		update_option( 'modes_settings', array( 'enabled' => false ) );

		$html = $this->html();

		$this->assertStringNotContainsString( 'checked="checked"', $html );
		$this->assertStringContainsString( 'The modes are disabled', $html );
		$this->assertStringContainsString( 'name="action" value="modes_declare"', $html, 'The variants can still be edited while the modes are disabled.' );

		$panel = new SettingsPanel( $this->environment, $this->site->modes->settings() );
		$panel->register();

		$this->assertSame( 'register_setting', $GLOBALS['otherguise_test_calls'][0][0] );
		$this->assertSame( 'modes_settings_group', $GLOBALS['otherguise_test_calls'][0][1] );
		$this->assertSame( 'modes_settings', $GLOBALS['otherguise_test_calls'][0][2] );
		$this->assertSame( 'edit_theme_options', $panel->capability() );
	}

	/**
	 * Without the capability nothing is printed.
	 *
	 * @return void
	 */
	public function test_the_page_is_refused_without_the_capability(): void {
		$this->environment->allowed = false;

		ob_start();

		try {
			$this->page->render();
			$this->fail( 'The page should be refused.' );
		} catch ( Otherguise_Test_Denied $denied ) {
			$this->assertSame( '', (string) ob_get_clean() );
		}
	}

	/**
	 * The notices.
	 *
	 * @return void
	 */
	public function test_notices(): void {
		$this->assertStringContainsString( 'Variant declared.', $this->html( array( 'modes_notice' => 'declared' ) ) );
		$this->assertStringContainsString( 'Variant withdrawn from the mode.', $this->html( array( 'modes_notice' => 'withdrawn' ) ) );
		$this->assertStringContainsString( 'Variant removed.', $this->html( array( 'modes_notice' => 'removed' ) ) );
		$this->assertStringContainsString(
			'notice-error',
			$this->html(
				array(
					'modes_notice' => 'error',
					'code' => 'mode_already_served',
				)
			)
		);
		$this->assertStringContainsString(
			'withdraw it first',
			$this->html(
				array(
					'modes_notice' => 'error',
					'code' => 'mode_already_served',
				)
			)
		);
		$this->assertStringContainsString(
			'does not exist',
			$this->html(
				array(
					'modes_notice' => 'error',
					'code' => 'triples_object_missing',
				)
			)
		);
		$this->assertStringContainsString(
			'could not be saved',
			$this->html(
				array(
					'modes_notice' => 'error',
					'code' => '<script>',
				)
			)
		);
		$this->assertStringNotContainsString( 'class="notice', $this->html( array( 'modes_notice' => '<script>' ) ) );
	}

	/**
	 * The menu entry, the hooks of the composition, and the module that adds them in the administration only.
	 *
	 * @return void
	 */
	public function test_hooks(): void {
		$this->page->register_menu();

		$this->assertSame( array( 'add_submenu_page', 'options-general.php', 'Otherguise modes', 'Otherguise modes', 'edit_theme_options', 'modes', array( $this->page, 'render' ) ), $GLOBALS['otherguise_test_calls'][0] );

		$added = array();
		$admin = new Admin( $this->environment, $this->site->modes->modes(), $this->site->modes->variants(), $this->site->lookup, $this->site->modes->settings() );

		$admin->register(
			static function ( $hook ) use ( &$added ) {
				$added[] = $hook;
			}
		);

		$this->assertSame( array( 'admin_menu', 'admin_init', 'option_page_capability_modes_settings_group', 'admin_post_modes_declare', 'admin_post_modes_withdraw', 'admin_post_modes_remove' ), $added );

		foreach ( array( false, true ) as $in_admin ) {
			$recorded = array();
			$module   = new Module(
				static function () {},
				static function ( $hook ) use ( &$recorded ) {
					$recorded[] = $hook;
				},
				static fn() => array(),
				null,
				fn() => $this->site->statements,
				$this->site->lookup,
				null,
				static fn() => $in_admin
			);

			$module->boot();

			$this->assertSame( $in_admin, in_array( 'admin_post_modes_declare', $recorded, true ), 'Hooked in the administration only.' );
		}
	}
}
