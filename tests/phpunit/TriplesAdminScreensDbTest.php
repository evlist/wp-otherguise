<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the pages of the administration screen, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Admin\AdminPage;
use Otherguise\Triples\Admin\MaintenanceScreen;
use Otherguise\Triples\Admin\OrphanScanner;
use Otherguise\Triples\Admin\RegisteredScreen;
use Otherguise\Triples\Admin\RegisteredView;
use Otherguise\Triples\Admin\SettingsPage;
use Otherguise\Triples\Admin\StatementsScreen;
use Otherguise\Triples\Admin\StatementsView;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Module;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\Uninstaller;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-environment.php';

/**
 * What the pages print, escaped; the menu; the settings. The list table is a stub of the core one: nothing here was displayed in a
 * browser.
 *
 * @covers \Otherguise\Triples\Admin\AdminPage
 * @covers \Otherguise\Triples\Admin\Markup
 * @covers \Otherguise\Triples\Admin\MaintenanceScreen
 * @covers \Otherguise\Triples\Admin\RegisteredScreen
 * @covers \Otherguise\Triples\Admin\RegisteredView
 * @covers \Otherguise\Triples\Admin\SettingsPage
 * @covers \Otherguise\Triples\Admin\StatementsScreen
 * @covers \Otherguise\Triples\Admin\StatementsTable
 */
class TriplesAdminScreensDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Environment.
	 *
	 * @var Otherguise_Test_Environment
	 */
	private $environment;

	/**
	 * Module.
	 *
	 * @var Module
	 */
	private $module;

	/**
	 * Service.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Page.
	 *
	 * @var AdminPage
	 */
	private $page;

	/**
	 * Settings.
	 *
	 * @var SettingsPage
	 */
	private $settings;

	/**
	 * Actions added by the page.
	 *
	 * @var array
	 */
	private $added = array();

	/**
	 * Builds the page.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->module      = Otherguise_Test_Fixtures::module( $this->wpdb );
		$this->statements  = $this->module->statements();
		$this->environment = new Otherguise_Test_Environment();
		$store             = $this->module->store();
		$scanner           = new OrphanScanner( $store, $this->module->entity_types() );
		$view              = new StatementsView( $this->statements, $store, $this->module->predicates(), $scanner );
		$this->settings    = new SettingsPage( $this->environment );
		$this->page        = new AdminPage(
			$this->environment,
			new StatementsScreen( $this->environment, $view, $store, $this->module->predicates(), $this->module->entity_types() ),
			new RegisteredScreen( $this->environment, new RegisteredView( $this->module->predicates(), $this->module->entity_types(), $this->module->datatypes(), $store ) ),
			new MaintenanceScreen( $this->environment, $scanner, $view, $this->settings ),
			function ( $hook, $callback ) {
				$this->added[ $hook ] = $callback;
			}
		);
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
	 * The menu entry asks for the capability and hooks the screen option.
	 *
	 * @return void
	 */
	public function test_the_menu_entry(): void {
		$this->page->register_menu();

		$this->assertSame( array( 'add_submenu_page', 'tools.php', 'Relations', 'Relations', 'manage_options', 'triples', array( $this->page, 'render' ) ), $GLOBALS['otherguise_test_calls'][0] );
		$this->assertSame( array( $this->page, 'load' ), $this->added['load-tools_page_triples'] );

		$this->page->load();

		$this->assertSame( 'add_screen_option', $GLOBALS['otherguise_test_calls'][1][0] );
		$this->assertSame( 'triples_per_page', $GLOBALS['otherguise_test_calls'][1][2]['option'] );
	}

	/**
	 * The screen option is kept between 1 and 200.
	 *
	 * @return void
	 */
	public function test_the_screen_option_is_clamped(): void {
		$this->assertSame( 50, $this->page->save_screen_option( false, 'triples_per_page', '50' ) );
		$this->assertSame( 1, $this->page->save_screen_option( false, 'triples_per_page', '-3' ) );
		$this->assertSame( 200, $this->page->save_screen_option( false, 'triples_per_page', '99999' ) );
	}

	/**
	 * Without the capability nothing is printed.
	 *
	 * @return void
	 */
	public function test_the_page_is_refused_without_the_capability(): void {
		$this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );

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
	 * The statements tab prints the table, with the labels escaped, the missing ends marked and a link to the confirmation.
	 *
	 * @return void
	 */
	public function test_the_statements_tab(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) );

		otherguise_test_wp_objects( array( new WP_Post( 12, 'post', '<script>alert(1)</script> & Co' ) ) );

		$html = $this->html();

		$this->assertStringContainsString( '<h1>Relations</h1>', $html );
		$this->assertStringContainsString( 'nav-tab-active', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '(missing)', $html );
		$this->assertStringContainsString( 'Illustrated by', $html );
		$this->assertStringContainsString( 'mode:print', $html );
		$this->assertStringContainsString( 'name="statement[]" value="' . $link->id() . '"', $html );
		$this->assertStringContainsString( 'action=delete&amp;statement%5B0%5D=' . $link->id(), $html );
		$this->assertSame( 1, substr_count( $html, 'name="statement[]"' ), 'The statement about a statement is not a row.' );

		$all = $this->html( array( 'with_qualifiers' => '1' ) );

		$this->assertSame( 2, substr_count( $all, 'name="statement[]"' ) );
	}

	/**
	 * The filters are kept in the form and in the hidden fields of the table.
	 *
	 * @return void
	 */
	public function test_the_filters_are_printed_and_kept(): void {
		$html = $this->html(
			array(
				'predicate'   => 'books/contains',
				'entity'      => 'post:12" onfocus="alert(1)',
				'entity_type' => 'post',
				'orphans'     => '1',
			)
		);

		$this->assertStringContainsString( '<option value="books/contains" selected="selected">', $html );
		$this->assertStringContainsString( 'name="orphans" value="1" checked="checked"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="predicate" value="books/contains" />', $html );
		$this->assertStringNotContainsString( 'onfocus', $html, 'An entity that does not parse is dropped.' );
		$this->assertStringContainsString( 'The end of the table was reached.', $html );
		$this->assertStringContainsString( 'No statement found.', $html );
	}

	/**
	 * With the orphans filter the page offers to go on after the last statement looked at.
	 *
	 * @return void
	 */
	public function test_the_orphans_filter_offers_the_next_batch(): void {
		for ( $number = 1; $number <= 3; $number++ ) {
			$this->module->store()->insert( new Statement( new EntityRef( 'post', '9' . $number ), 'test/anything', new EntityRef( 'post', '12' ) ) );
		}

		$html = $this->html( array( 'orphans' => '1' ) );

		$this->assertSame( 3, substr_count( $html, 'name="statement[]"' ) );
		$this->assertStringContainsString( 'The end of the table was reached.', $html );
	}

	/**
	 * The confirmation page says what will be deleted, with the dependents, and holds the form with its nonce.
	 *
	 * @return void
	 */
	public function test_the_confirmation_page(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) );
		$this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'web' ) );

		$html = $this->html(
			array(
				'action' => 'delete',
				'statement' => array( (string) $link->id(), '999', 'abc' ),
			)
		);

		$this->assertStringContainsString( '1 statement is selected; 3 statements will be deleted in all, with the statements about it.', $html );
		$this->assertStringContainsString( '<form method="post" action="http://example.test/wp-admin/admin-post.php">', $html );
		$this->assertStringContainsString( 'name="action" value="triples_delete"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce:triples_bulk_delete"', $html );
		$this->assertSame( 1, substr_count( $html, 'name="statement[]" value=' ), 'Only the statements that exist are carried by the form.' );

		$this->assertStringContainsString(
			'Nothing was selected.',
			$this->html(
				array(
					'action2' => 'delete',
					'statement' => array( '999' ),
				)
			)
		);
	}

	/**
	 * The registered tab: predicates with their counts, entity types, datatypes, and the predicates that are gone.
	 *
	 * @return void
	 */
	public function test_the_registered_tab(): void {
		$this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$this->module->store()->insert( new Statement( new EntityRef( 'post', '1' ), 'old/relation', new EntityRef( 'post', '2' ) ) );

		$html = $this->html( array( 'tab' => 'registered' ) );

		$this->assertStringContainsString( 'Predicates that are no longer registered', $html );
		$this->assertStringContainsString( '<code>old/relation</code>', $html );
		$this->assertStringContainsString( 'confirm_predicate=old%2Frelation', $html );
		$this->assertStringNotContainsString( 'name="_wpnonce"', $html, 'The list only links to the confirmation.' );
		$this->assertStringContainsString( '<code>media/illustrated-by</code>', $html );
		$this->assertStringContainsString( 'Datatypes', $html );
		$this->assertStringContainsString( 'xsd:integer', $html );
		$this->assertStringContainsString( '<code>mode</code>', $html );

		$confirm = $this->html(
			array(
				'tab' => 'registered',
				'confirm_predicate' => 'old/relation',
			)
		);

		$this->assertStringContainsString( '1 statement of the predicate old/relation, and the statements about it, will be deleted.', $confirm );
		$this->assertStringContainsString( 'name="predicate" value="old/relation"', $confirm );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce:triples_delete_predicate_old/relation"', $confirm );
		$this->assertStringNotContainsString( 'Datatypes', $confirm );
		$this->assertStringContainsString(
			'Datatypes',
			$this->html(
				array(
					'tab' => 'registered',
					'confirm_predicate' => 'media/illustrated-by',
				)
			),
			'A registered predicate has no confirmation page.'
		);
		$this->assertStringContainsString(
			'Datatypes',
			$this->html(
				array(
					'tab' => 'registered',
					'confirm_predicate' => 'ghost/none',
				)
			)
		);

		$view = new RegisteredView( $this->module->predicates(), $this->module->entity_types(), $this->module->datatypes(), $this->module->store() );
		$rows = array_column( $view->predicates(), 'count', 'slug' );

		$this->assertSame( 1, $rows['media/illustrated-by'] );
		$this->assertSame( 0, $rows['books/contains'] );
		$this->assertSame( array( 'old/relation' => 1 ), $view->unregistered() );
	}

	/**
	 * The maintenance tab scans a batch, shows the orphans, and prints the form with the start of the batch.
	 *
	 * @return void
	 */
	public function test_the_maintenance_tab(): void {
		$this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$gone = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 90 ) );

		otherguise_test_wp_objects( array( new WP_Post( 12 ), new WP_Post( 88, 'attachment' ) ) );

		$start = $this->html( array( 'tab' => 'maintenance' ) );

		$this->assertStringContainsString( 'Scan the first 200 statements', $start );
		$this->assertStringContainsString( 'name="triples_settings[delete_data_on_uninstall]" value="1"', $start );
		$this->assertStringContainsString( 'name="option_page" value="triples_settings_group"', $start );
		$this->assertStringNotContainsString( 'checked="checked"', $start );

		$batch = $this->html(
			array(
				'tab' => 'maintenance',
				'after' => '0',
			)
		);

		$this->assertStringContainsString( 'Statements up to #' . $gone->id() . ' were scanned: 1 orphan found.', $batch );
		$this->assertStringContainsString( 'name="action" value="triples_delete_orphans"', $batch );
		$this->assertStringContainsString( 'name="after" value="0"', $batch );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce:triples_delete_orphans"', $batch );
		$this->assertStringContainsString( 'The end of the table was reached.', $batch );

		update_option( Uninstaller::SETTINGS_OPTION, array( Uninstaller::DELETE_FLAG => true ) );

		$this->assertStringContainsString( 'value="1" checked="checked"', $this->html( array( 'tab' => 'maintenance' ) ) );
	}

	/**
	 * The notices say the result of the last action, and only the known ones.
	 *
	 * @return void
	 */
	public function test_notices(): void {
		$this->assertStringContainsString(
			'3 statements deleted.',
			$this->html(
				array(
					'triples_notice' => 'deleted',
					'n' => '3',
				)
			)
		);
		$this->assertStringContainsString(
			'1 statement deleted.',
			$this->html(
				array(
					'triples_notice' => 'orphans_deleted',
					'n' => '1',
				)
			)
		);
		$this->assertStringContainsString( 'notice-error', $this->html( array( 'triples_notice' => 'predicate_refused' ) ) );
		$this->assertStringNotContainsString( 'notice', $this->html( array( 'triples_notice' => '<script>' ) ) );
	}

	/**
	 * The setting is sanitized to a boolean, the other keys stay, and the capability is the one of the screen.
	 *
	 * @return void
	 */
	public function test_the_settings(): void {
		update_option( Uninstaller::SETTINGS_OPTION, array( 'other' => 'kept' ) );

		$this->assertSame(
			array(
				'other' => 'kept',
				Uninstaller::DELETE_FLAG => true,
			),
			$this->settings->sanitize(
				array(
					Uninstaller::DELETE_FLAG => '1',
					'evil' => 'x',
				)
			)
		);
		$this->assertSame(
			array(
				'other' => 'kept',
				Uninstaller::DELETE_FLAG => false,
			),
			$this->settings->sanitize( array() )
		);
		$this->assertSame(
			array(
				'other' => 'kept',
				Uninstaller::DELETE_FLAG => false,
			),
			$this->settings->sanitize( 'nonsense' )
		);
		$this->assertSame( 'manage_options', $this->settings->capability() );

		$this->settings->register();

		$this->assertSame( 'register_setting', $GLOBALS['otherguise_test_calls'][0][0] );
		$this->assertSame( 'triples_settings_group', $GLOBALS['otherguise_test_calls'][0][1] );
		$this->assertSame( 'triples_settings', $GLOBALS['otherguise_test_calls'][0][2] );
	}

	/**
	 * The administration is hooked in the administration only.
	 *
	 * @return void
	 */
	public function test_the_module_hooks_the_screen_in_the_administration_only(): void {
		$recorded = array();
		$add      = static function ( $hook ) use ( &$recorded ) {
			$recorded[] = $hook;
		};

		( new Module( static function () {}, $this->wpdb, $add, static fn() => false ) )->boot();
		$front = $recorded;

		$recorded = array();

		( new Module( static function () {}, $this->wpdb, $add, static fn() => true ) )->boot();

		$this->assertSame( array( 'deleted_post', 'deleted_term', 'deleted_user', 'init' ), $front );
		$this->assertSame(
			array(
				'deleted_post',
				'deleted_term',
				'deleted_user',
				'init',
				'admin_menu',
				'admin_init',
				'option_page_capability_triples_settings_group',
				'set_screen_option_triples_per_page',
				'admin_post_triples_delete',
				'admin_post_triples_delete_orphans',
				'admin_post_triples_delete_predicate',
			),
			$recorded
		);
	}
}
