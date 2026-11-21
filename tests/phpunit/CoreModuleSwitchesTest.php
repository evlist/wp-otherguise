<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the switch per module (slice 500).
 *
 * @package Otherguise
 */

use Otherguise\Core\AdminMenu;
use Otherguise\Core\DisabledByDefault;
use Otherguise\Core\ModuleSwitches;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-switch-module.php';

/**
 * No database and no WordPress: stubs and doubles.
 *
 * @covers \Otherguise\Core\ModuleSwitches
 * @covers \Otherguise\Core\AdminMenu
 */
class CoreModuleSwitchesTest extends TestCase {

	/**
	 * The notices of the test.
	 *
	 * @var array<int, array{string, string}>
	 */
	private $notices = array();

	/**
	 * Starts without any option or recorded call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		Otherguise_Test_Switch_Module::$activated = array();
		$this->notices                            = array();
	}

	/**
	 * Five modules: triples (nothing), modes and books (need triples), access (nothing), wiki (needs triples, off by default).
	 *
	 * @return Otherguise_Test_Switch_Module[]
	 */
	private function modules(): array {
		return array(
			new Otherguise_Test_Switch_Module( 'triples' ),
			new Otherguise_Test_Switch_Module( 'modes', array( 'triples' ) ),
			new Otherguise_Test_Switch_Module( 'books', array( 'triples', 'modes' ) ),
			new Otherguise_Test_Switch_Module( 'access' ),
			new class( 'wiki', array( 'triples' ) ) extends Otherguise_Test_Switch_Module implements DisabledByDefault {
			},
		);
	}

	/**
	 * The switches, with the notices of the test.
	 *
	 * @param callable|null $filter The filter, or the default one.
	 * @return ModuleSwitches
	 */
	private function switches( $filter = null ): ModuleSwitches {
		return new ModuleSwitches(
			$this->modules(),
			null,
			$filter,
			null,
			function ( $code, $message ) {
				$this->notices[] = array( $code, $message );
			}
		);
	}

	/**
	 * A site that chose nothing has every module on but the one that is off by default.
	 *
	 * @return void
	 */
	public function test_the_defaults(): void {
		$this->assertSame( array( 'triples', 'modes', 'books', 'access' ), $this->switches()->defaults() );
		$this->assertSame( array( 'triples', 'modes', 'books', 'access' ), $this->switches()->enabled() );
	}

	/**
	 * What the site chose wins over the default, both ways; what is not a known module is ignored.
	 *
	 * @return void
	 */
	public function test_what_the_site_chose(): void {
		update_option(
			ModuleSwitches::OPTION,
			array(
				'wiki'    => true,
				'books'   => false,
				'access'  => 0,
				'unknown' => true,
			)
		);

		$this->assertSame(
			array(
				'wiki' => true,
				'books' => false,
				'access' => false,
			),
			$this->switches()->stored()
		);
		$this->assertSame( array( 'triples', 'modes', 'wiki' ), $this->switches()->enabled(), 'In the order of the composition root.' );

		update_option( ModuleSwitches::OPTION, 'broken' );
		$this->assertSame( array(), $this->switches()->stored() );
		$this->assertSame( array( 'triples', 'modes', 'books', 'access' ), $this->switches()->enabled() );
	}

	/**
	 * A module is never enabled without the modules it needs, at any depth.
	 *
	 * @return void
	 */
	public function test_the_dependencies_are_added(): void {
		update_option(
			ModuleSwitches::OPTION,
			array(
				'triples' => false,
				'modes' => false,
				'books' => true,
				'access' => false,
				'wiki' => false,
			)
		);

		$this->assertSame( array( 'triples', 'modes', 'books' ), $this->switches()->enabled(), 'Books needs modes and triples.' );
		$this->assertSame( array( 'books' ), $this->switches()->needed_by( 'modes' ) );
		$this->assertSame( array( 'modes', 'books' ), $this->switches()->needed_by( 'triples' ) );
		$this->assertSame( array(), $this->switches()->needed_by( 'books' ) );
	}

	/**
	 * The filter has the last word: it receives the list, may return another, and `null` means every module.
	 *
	 * @return void
	 */
	public function test_the_filter_has_the_last_word(): void {
		$received = null;

		$this->assertSame(
			array( 'triples', 'wiki' ),
			$this->switches(
				static function ( $list ) use ( &$received ) {
					$received = $list;

					return array( 'wiki', 'nothing', 'triples' );
				}
			)->enabled(),
			'The unknown module is dropped; the order is the root.'
		);
		$this->assertSame( array( 'triples', 'modes', 'books', 'access' ), $received );
		$this->assertSame( array( 'triples', 'modes', 'books', 'access', 'wiki' ), $this->switches( static fn() => null )->enabled(), 'Null is every module.' );
		$this->assertSame( array( 'triples', 'modes' ), $this->switches( static fn() => array( 'modes' ) )->enabled(), 'The dependencies come with it.' );
	}

	/**
	 * The form: a ticked box is on, the others are off, a module that another one needs stays on with a notice, an unknown one is dropped.
	 *
	 * @return void
	 */
	public function test_the_form_is_cleaned(): void {
		$switches = $this->switches();

		$this->assertSame(
			array(
				'triples' => true,
				'modes' => false,
				'books' => false,
				'access' => true,
				'wiki' => false,
			),
			$switches->sanitize(
				array(
					'triples' => '1',
					'access' => '1',
					'unknown' => '1',
				)
			)
		);
		$this->assertSame( array(), $this->notices );

		$on = $switches->sanitize( array( 'books' => '1' ) );

		$this->assertSame(
			array(
				'triples' => true,
				'modes' => true,
				'books' => true,
				'access' => false,
				'wiki' => false,
			),
			$on
		);
		$this->assertSame( array( 'needed-triples', 'needed-modes' ), array_column( $this->notices, 0 ) );
		$this->assertSame( 'The module Module triples stays on: another module that is on needs it.', $this->notices[0][1] );

		update_option( ModuleSwitches::OPTION, array( 'wiki' => true ) );

		$this->assertSame(
			array(
				'triples' => true,
				'modes' => true,
				'books' => true,
				'access' => true,
				'wiki' => true,
			),
			$this->switches()->sanitize( 'nonsense' ),
			'A value that is not a list changes nothing.'
		);
	}

	/**
	 * The setting, the capability and the activation of what is switched on are registered.
	 *
	 * @return void
	 */
	public function test_the_hooks_and_the_activation(): void {
		$hooks    = array();
		$switches = $this->switches();

		$switches->register(
			static function ( $hook, $callback, $priority, $accepted ) use ( &$hooks ) {
				$hooks[ $hook ] = array( $callback[1], $priority, $accepted );
			}
		);

		$this->assertSame(
			array(
				'admin_init'                                => array( 'register_setting', 10, 0 ),
				'option_page_capability_otherguise_modules_group' => array( 'capability', 10, 0 ),
				'update_option_otherguise_modules'          => array( 'activate_new', 10, 0 ),
				'add_option_otherguise_modules'             => array( 'activate_new', 10, 0 ),
			),
			$hooks
		);

		$switches->register_setting();

		$registered = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_setting' === $call[0] ) )[0];

		$this->assertSame( array( 'otherguise_modules_group', 'otherguise_modules' ), array( $registered[1], $registered[2] ) );
		$this->assertSame( array( $switches, 'sanitize' ), $registered[3]['sanitize_callback'] );
		$this->assertSame( 'manage_options', $switches->capability() );

		update_option(
			ModuleSwitches::OPTION,
			array(
				'wiki' => true,
				'access' => false,
			)
		);
		$switches->activate_new();

		$this->assertSame( array( 'triples', 'modes', 'books', 'wiki' ), Otherguise_Test_Switch_Module::$activated, 'Only what is on, dependencies first.' );
	}

	/**
	 * The section of the overview: a box per module, the state, what it needs and what needs it, the note of the module that is off by default;
	 * nothing for a user who may not.
	 *
	 * @return void
	 */
	public function test_the_section(): void {
		ob_start();
		$this->switches()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<h2>Modules</h2>', $html );
		$this->assertStringContainsString( 'name="option_page" value="otherguise_modules_group"', $html );
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( 'name="otherguise_modules[access]" value="1" checked="checked"', $html );
		$this->assertStringContainsString( 'name="otherguise_modules[wiki]" value="1" />', $html, 'The module that is off has an empty box.' );
		$this->assertStringContainsString( 'Off until you switch it on.', $html );
		$this->assertStringContainsString( 'Needs: Module triples.', $html );
		$this->assertStringContainsString( 'Needed by: Module modes, Module books.', $html );
		$this->assertStringContainsString( 'Does wiki.', $html, 'The description of the module.' );
		$this->assertStringContainsString( '<input type="hidden" name="otherguise_modules[triples]" value="1" />', $html, 'The module that others need is posted by a hidden field.' );
		$this->assertStringNotContainsString( 'name="otherguise_modules[triples]" value="1" checked', $html, 'And its box is not posted.' );
		$this->assertStringContainsString( 'disabled="disabled"', $html );

		$denied = new ModuleSwitches( $this->modules(), null, null, static fn() => false );

		ob_start();
		$denied->render();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The overview prints the section at its end, with screens or without.
	 *
	 * @return void
	 */
	public function test_the_overview_holds_the_section(): void {
		$section = static function () {
			echo '<p class="section">modules</p>';
		};
		$menu    = new AdminMenu(
			static fn() => array(
				array(
					'title' => 'A screen',
					'page' => 'a-screen',
				),
			),
			static fn() => true,
			static function () {},
			$section
		);

		ob_start();
		$menu->render();
		$with = ob_get_clean();

		$this->assertStringContainsString( 'A screen', $with );
		$this->assertStringEndsWith( '<p class="section">modules</p></div>', $with );

		$empty = new AdminMenu( static fn() => array(), static fn() => true, static function () {}, $section );

		ob_start();
		$empty->render();
		$this->assertStringEndsWith( '<p class="section">modules</p></div>', ob_get_clean(), 'Even when no screen is available.' );
	}

	/**
	 * The real modules: the wiki is off by default and the others are on, and every module says what it is.
	 *
	 * @return void
	 */
	public function test_the_real_modules(): void {
		$modules  = require dirname( __DIR__, 2 ) . '/plugin/includes/modules.php';
		$switches = new ModuleSwitches( $modules );

		$this->assertSame( array( 'triples', 'modes', 'media', 'books', 'access' ), $switches->defaults() );
		$this->assertNotContains( 'wiki', $switches->enabled() );

		update_option( ModuleSwitches::OPTION, array( 'wiki' => true ) );

		$this->assertContains( 'wiki', $switches->enabled() );
		$this->assertContains( 'triples', $switches->enabled(), 'The wiki needs Triples.' );

		foreach ( $modules as $module ) {
			$this->assertInstanceOf( Otherguise\Core\DescribedModule::class, $module, $module->id() );
			$this->assertNotSame( '', $module->title() );
			$this->assertNotSame( '', $module->description() );
		}
	}
}
