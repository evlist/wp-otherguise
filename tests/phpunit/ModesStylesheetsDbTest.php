<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the stylesheets of the modes, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Admin\AdminPage;
use Otherguise\Modes\Admin\SettingsPanel;
use Otherguise\Modes\Admin\StylesheetActions;
use Otherguise\Modes\Admin\StylesheetsScreen;
use Otherguise\Modes\Admin\VariantsScreen;
use Otherguise\Modes\Settings\Settings;
use Otherguise\Modes\Stylesheet\StylesheetException;
use Otherguise\Modes\Stylesheet\StylesheetLoader;
use Otherguise\Modes\Stylesheet\Stylesheets;
use Otherguise\Triples\Entity\EntityRef;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-environment.php';
require_once __DIR__ . '/support/class-otherguise-test-stylesheet-files.php';

/**
 * The service, the loader, the handlers and the section of the screen.
 *
 * @covers \Otherguise\Modes\Stylesheet\Stylesheets
 * @covers \Otherguise\Modes\Stylesheet\StylesheetLoader
 * @covers \Otherguise\Modes\Stylesheet\StylesheetException
 * @covers \Otherguise\Modes\Admin\StylesheetActions
 * @covers \Otherguise\Modes\Admin\StylesheetsScreen
 */
class ModesStylesheetsDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Modules wired together.
	 *
	 * @var Otherguise_Test_Modes_Site
	 */
	private $site;

	/**
	 * Files of the Media Library.
	 *
	 * @var Otherguise_Test_Stylesheet_Files
	 */
	private $files;

	/**
	 * Service.
	 *
	 * @var Stylesheets
	 */
	private $stylesheets;

	/**
	 * Environment.
	 *
	 * @var Otherguise_Test_Modes_Environment
	 */
	private $environment;

	/**
	 * Handlers.
	 *
	 * @var StylesheetActions
	 */
	private $actions;

	/**
	 * Builds the site, two stylesheets in the Media Library and the handlers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		otherguise_test_wp_objects( array( new WP_Post( 31, 'attachment' ), new WP_Post( 32, 'attachment' ), new WP_Post( 33, 'attachment' ), new WP_Post( 12 ) ) );

		$this->site = new Otherguise_Test_Modes_Site( $this->wpdb );
		$this->files = new Otherguise_Test_Stylesheet_Files();
		$this->files->file( 31, 'print' );
		$this->files->file( 32, 'print-fonts', 3400 );
		$this->stylesheets = new Stylesheets( $this->site->statements, $this->files );
		$this->environment = new Otherguise_Test_Modes_Environment();
		$this->actions     = new StylesheetActions( $this->environment, $this->stylesheets, $this->files, $this->site->modes->modes() );
	}

	/**
	 * Runs a handler and returns the URL it redirects to, or 'denied'.
	 *
	 * @param string $method Handler.
	 * @return string
	 */
	private function handle( $method ) {
		try {
			$this->actions->{$method}();
		} catch ( Otherguise_Test_Redirect $redirect ) {
			return $redirect->url;
		} catch ( Otherguise_Test_Denied $denied ) {
			return 'denied';
		}

		$this->fail( 'The handler should have redirected or refused.' );
	}

	/**
	 * Returns the attachments of a mode.
	 *
	 * @param string $slug Mode.
	 * @return int[]
	 */
	private function of( $slug ) {
		return $this->stylesheets->for_mode( new EntityRef( 'mode', $slug ) );
	}

	/**
	 * A mode has none at first; adding is idempotent, the order is the order of the ids, the statement holds a reference.
	 *
	 * @return void
	 */
	public function test_add_and_list(): void {
		$print = new EntityRef( 'mode', 'print' );

		$this->assertSame( array(), $this->of( 'print' ) );

		$this->files->file( 33, 'late' );
		$this->stylesheets->add( $print, 33 );
		$this->stylesheets->add( $print, 31 );
		$this->stylesheets->add( $print, 31 );

		$this->assertSame( array( 31, 33 ), $this->of( 'print' ) );
		$this->assertSame( array(), $this->of( 'web' ) );

		$statements = $this->site->statements->match( $print, 'modes/stylesheet' );

		$this->assertCount( 2, $statements );
		$this->assertSame( 'attachment', $statements[0]->object()->type() );
	}

	/**
	 * A mode object stands for its entity too.
	 *
	 * @return void
	 */
	public function test_a_mode_definition_is_accepted(): void {
		$this->stylesheets->add( $this->site->modes->modes()->get( 'print' ), 31 );

		$this->assertSame( array( 31 ), $this->stylesheets->for_mode( $this->site->modes->modes()->get( 'print' ) ) );
	}

	/**
	 * What is not a stylesheet or not a mode is refused, and nothing is stored.
	 *
	 * @return void
	 */
	public function test_refusals(): void {
		$before = count( $this->site->statements->match() );

		foreach ( array( array( 'print', 999, StylesheetException::NOT_A_STYLESHEET ), array( 'print', 12, StylesheetException::NOT_A_STYLESHEET ) ) as $case ) {
			try {
				$this->stylesheets->add( new EntityRef( 'mode', $case[0] ), $case[1] );
				$this->fail( 'Refused expected.' );
			} catch ( StylesheetException $problem ) {
				$this->assertSame( $case[2], $problem->error_code() );
			}
		}

		try {
			$this->stylesheets->add( new EntityRef( 'attachment', '31' ), 31 );
			$this->fail( 'Refused expected.' );
		} catch ( StylesheetException $problem ) {
			$this->assertSame( StylesheetException::NOT_A_MODE, $problem->error_code() );
		}

		$this->assertSame( $before, count( $this->site->statements->match() ) );
	}

	/**
	 * Removing takes the relation away and tells how many statements went; the file is not concerned.
	 *
	 * @return void
	 */
	public function test_remove(): void {
		$print = new EntityRef( 'mode', 'print' );

		$this->stylesheets->add( $print, 31 );
		$this->stylesheets->add( $print, 32 );

		$this->assertSame( 1, $this->stylesheets->remove( $print, 31 ) );
		$this->assertSame( 0, $this->stylesheets->remove( $print, 31 ) );
		$this->assertSame( array( 32 ), $this->of( 'print' ) );
		$this->assertNotNull( $this->files->describe( 31 ) );
	}

	/**
	 * Builds a loader that records what it enqueues.
	 *
	 * @param array  $enqueued Receives the calls.
	 * @param bool   $enabled  Whether the modes are enabled.
	 * @param string $mode     Mode of the request.
	 * @param bool   $service  Whether the service can be had.
	 * @return StylesheetLoader
	 */
	private function loader( array &$enqueued, $enabled = true, $mode = 'print', $service = true ) {
		return new StylesheetLoader(
			static fn() => $enabled,
			fn() => $this->site->modes->modes()->get( $mode ),
			fn() => $service ? $this->stylesheets : null,
			$this->files,
			static function ( $handle, $url, $deps, $version ) use ( &$enqueued ) {
				$enqueued[] = array( $handle, $url, $deps, $version );
			}
		);
	}

	/**
	 * The stylesheets of the mode of the request are enqueued, in order, with their address and the modification time as version.
	 *
	 * @return void
	 */
	public function test_the_loader_enqueues_the_stylesheets_of_the_mode(): void {
		$this->stylesheets->add( new EntityRef( 'mode', 'print' ), 32 );
		$this->stylesheets->add( new EntityRef( 'mode', 'print' ), 31 );

		$enqueued = array();
		$this->loader( $enqueued )->enqueue();

		$this->assertSame(
			array(
				array( 'modes-print-31', 'http://example.test/wp-content/uploads/print.css', array(), '17000' . 31 ),
				array( 'modes-print-32', 'http://example.test/wp-content/uploads/print-fonts.css', array(), '17000' . 32 ),
			),
			$enqueued
		);
	}

	/**
	 * Another mode, disabled modes, no service, and a file that is gone enqueue nothing for it.
	 *
	 * @return void
	 */
	public function test_the_loader_enqueues_nothing_otherwise(): void {
		$this->stylesheets->add( new EntityRef( 'mode', 'print' ), 31 );

		$enqueued = array();
		$this->loader( $enqueued, true, 'web' )->enqueue();
		$this->loader( $enqueued, false )->enqueue();
		$this->loader( $enqueued, true, 'print', false )->enqueue();
		$this->assertSame( array(), $enqueued );

		unset( $this->files->files[31] );
		$this->loader( $enqueued )->enqueue();
		$this->assertSame( array(), $enqueued );
	}

	/**
	 * Capability, upload capability and nonce come first, before anything is read.
	 *
	 * @return void
	 */
	public function test_capabilities_and_nonces(): void {
		$fields = array(
			'mode'       => 'print',
			'how'        => 'existing',
			'attachment' => '31',
		);

		$this->environment->allowed = false;
		$this->environment->post_with_nonce( 'modes_add_stylesheet', $fields );
		$this->assertSame( 'denied', $this->handle( 'add_stylesheet' ) );
		$this->environment->post_with_nonce( 'modes_remove_stylesheet', $fields );
		$this->assertSame( 'denied', $this->handle( 'remove_stylesheet' ) );

		$this->environment->allowed    = true;
		$this->environment->may_upload = false;
		$this->environment->post_with_nonce( 'modes_add_stylesheet', $fields );
		$this->assertSame( 'denied', $this->handle( 'add_stylesheet' ) );

		$this->environment->may_upload = true;
		$this->environment->post       = $fields + array( '_wpnonce' => 'nonce:modes_add_stylesheet' );
		$this->environment->valid_nonces = array();
		$this->assertSame( 'denied', $this->handle( 'add_stylesheet' ) );
		$this->environment->post_with_nonce( 'modes_remove_stylesheet', $fields );
		$this->environment->post['_wpnonce'] = 'nonce:modes_add_stylesheet';
		$this->assertSame( 'denied', $this->handle( 'remove_stylesheet' ), 'the nonce of another action' );

		$this->assertSame( array(), $this->of( 'print' ) );
	}

	/**
	 * A stylesheet of the Media Library is added, then removed.
	 *
	 * @return void
	 */
	public function test_add_an_existing_stylesheet_then_remove_it(): void {
		$this->environment->post_with_nonce(
			'modes_add_stylesheet',
			array(
				'mode'       => 'print',
				'how'        => 'existing',
				'attachment' => '31',
			)
		);

		$this->assertStringContainsString( 'options-general.php?page=modes&modes_notice=stylesheet_added', $this->handle( 'add_stylesheet' ) );
		$this->assertSame( array( 31 ), $this->of( 'print' ) );
		$this->assertSame( array(), $this->files->uploaded );

		$this->environment->post_with_nonce(
			'modes_remove_stylesheet',
			array(
				'mode'       => 'print',
				'attachment' => '31',
			)
		);

		$this->assertStringContainsString( 'modes_notice=stylesheet_removed', $this->handle( 'remove_stylesheet' ) );
		$this->assertSame( array(), $this->of( 'print' ) );
	}

	/**
	 * An uploaded file goes through the Media Library, and its attachment is added.
	 *
	 * @return void
	 */
	public function test_upload(): void {
		$this->files->file( 33, 'new' );
		$this->files->uploads = array( 33 );
		$this->environment->post_with_nonce(
			'modes_add_stylesheet',
			array(
				'mode' => 'print',
				'how'  => 'upload',
			)
		);

		$this->assertStringContainsString( 'modes_notice=stylesheet_added', $this->handle( 'add_stylesheet' ) );
		$this->assertSame( array( 'stylesheet_file' ), $this->files->uploaded );
		$this->assertSame( array( 33 ), $this->of( 'print' ) );
	}

	/**
	 * Every refusal is a notice with its code, and stores nothing.
	 *
	 * @return void
	 */
	public function test_refusals_are_notices(): void {
		$cases = array(
			'unknown mode'         => array(
				array(
					'mode' => 'book',
					'how' => 'existing',
					'attachment' => '31',
				),
				'invalid_request',
			),
			'no way'               => array(
				array(
					'mode' => 'print',
					'attachment' => '31',
				),
				'invalid_request',
			),
			'other way'            => array(
				array(
					'mode' => 'print',
					'how' => 'elsewhere',
					'attachment' => '31',
				),
				'invalid_request',
			),
			'no attachment'        => array(
				array(
					'mode' => 'print',
					'how' => 'existing',
				),
				'invalid_request',
			),
			'attachment not a number' => array(
				array(
					'mode' => 'print',
					'how' => 'existing',
					'attachment' => '3x',
				),
				'invalid_request',
			),
			'attachment zero'      => array(
				array(
					'mode' => 'print',
					'how' => 'existing',
					'attachment' => '0',
				),
				'invalid_request',
			),
			'not a stylesheet'     => array(
				array(
					'mode' => 'print',
					'how' => 'existing',
					'attachment' => '33',
				),
				'not_a_stylesheet',
			),
			'a post, not a file'   => array(
				array(
					'mode' => 'print',
					'how' => 'existing',
					'attachment' => '12',
				),
				'not_a_stylesheet',
			),
		);

		foreach ( $cases as $name => $case ) {
			$this->environment->post_with_nonce( 'modes_add_stylesheet', $case[0] );

			$this->assertStringContainsString( 'modes_notice=error&code=' . $case[1], $this->handle( 'add_stylesheet' ), $name );
		}

		foreach ( array( StylesheetException::UPLOAD_FAILED, StylesheetException::TOO_LARGE ) as $code ) {
			$this->files->uploads = array( $code );
			$this->environment->post_with_nonce(
				'modes_add_stylesheet',
				array(
					'mode' => 'print',
					'how' => 'upload',
				)
			);

			$this->assertStringContainsString( 'modes_notice=error&code=' . $code, $this->handle( 'add_stylesheet' ), $code );
		}

		$this->environment->post_with_nonce(
			'modes_remove_stylesheet',
			array(
				'mode' => 'print',
				'attachment' => 'x',
			)
		);
		$this->assertStringContainsString( 'code=invalid_request', $this->handle( 'remove_stylesheet' ) );

		$this->assertSame( array(), $this->of( 'print' ) );
	}

	/**
	 * Prints the page with the section of the stylesheets.
	 *
	 * @return string
	 */
	private function page() {
		$modes   = $this->site->modes->modes();
		$screen  = new StylesheetsScreen( $this->environment, $modes, $this->stylesheets, $this->files );
		$panel   = new SettingsPanel( $this->environment, new Settings() );
		$page    = new AdminPage( $this->environment, new VariantsScreen( $this->environment, $modes, $this->site->modes->variants(), $this->site->lookup, $panel, $screen ) );
		$this->environment->get = array();

		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	/**
	 * The section: each mode with its stylesheets (and the missing ones marked), the buttons, the form, the nonces, the escaping.
	 *
	 * @return void
	 */
	public function test_the_section(): void {
		$this->files->file( 33, '<b>x</b>' );
		$this->stylesheets->add( new EntityRef( 'mode', 'print' ), 31 );
		$this->stylesheets->add( new EntityRef( 'mode', 'print' ), 33 );
		$this->stylesheets->add( new EntityRef( 'mode', 'print' ), 32 );
		unset( $this->files->files[32] );

		$html = $this->page();

		$this->assertSame( 1, substr_count( $html, '<h2>Stylesheets</h2>' ) );
		$this->assertStringContainsString( '<a href="http://example.test/wp-content/uploads/print.css">print</a> (1200 B)', $html );
		$this->assertStringContainsString( '#32 <em>(missing)</em>', $html );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertSame( 3, substr_count( $html, 'value="modes_remove_stylesheet"' ) );
		$this->assertSame( 3, substr_count( $html, 'value="Remove from this mode"' ) );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce:modes_remove_stylesheet"', $html );
		$this->assertStringContainsString( '<em>None.</em>', $html );
		$this->assertStringContainsString( '<form method="post" enctype="multipart/form-data"', $html );
		$this->assertStringContainsString( 'name="action" value="modes_add_stylesheet"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce:modes_add_stylesheet"', $html );
		$this->assertStringContainsString( '<input type="file" name="stylesheet_file" accept=".css,text/css" />', $html );
		$this->assertStringContainsString( '<option value="print" selected="selected">Print</option>', $html );
		$this->assertStringContainsString( '<option value="31">print</option>', $html );
		$this->assertStringContainsString( 'name="how" value="existing"', $html );
	}

	/**
	 * With no stylesheet in the Media Library, only the upload is offered.
	 *
	 * @return void
	 */
	public function test_only_the_upload_when_the_library_has_none(): void {
		$this->files->files = array();

		$html = $this->page();

		$this->assertStringContainsString( 'name="how" value="upload"', $html );
		$this->assertStringNotContainsString( 'name="how" value="existing"', $html );
	}
}
