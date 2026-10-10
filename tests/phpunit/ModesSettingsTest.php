<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the setting that enables the modes, and of what the module does when they are disabled.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Module;
use Otherguise\Modes\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * No database: the options are the stubs of the tests.
 *
 * @covers \Otherguise\Modes\Settings\Settings
 * @covers \Otherguise\Modes\Module
 */
class ModesSettingsTest extends TestCase {

	/**
	 * Starts without any option, whatever the other tests left.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * Cleans the options.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		otherguise_test_reset();
	}

	/**
	 * Builds a module for a request with a query string, in a page of the site.
	 *
	 * @param array $query Query string.
	 * @return Module
	 */
	private function module( array $query ) {
		return new Module( static function () {}, static function () {}, static fn() => $query, null, static fn() => null, null, static fn() => true );
	}

	/**
	 * The modes are disabled by default (they do nothing until something is declared), when the option has no such key, and when it is garbage.
	 *
	 * @return void
	 */
	public function test_disabled_by_default(): void {
		$settings = new Settings();

		$this->assertFalse( $settings->is_enabled() );

		update_option( Settings::OPTION, array( 'other' => 1 ) );
		$this->assertFalse( $settings->is_enabled() );

		update_option( Settings::OPTION, 'garbage' );
		$this->assertFalse( $settings->is_enabled() );
	}

	/**
	 * Without the setting, the module ignores the query string.
	 *
	 * @return void
	 */
	public function test_the_module_by_default(): void {
		$module = $this->module( array( 'print' => '' ) );

		$this->assertSame( 'web', $module->active()->mode()->slug() );
		$this->assertSame( array(), $module->body_class( array() ) );
	}

	/**
	 * They can be disabled and enabled again.
	 *
	 * @return void
	 */
	public function test_disabled_and_enabled_again(): void {
		$settings = new Settings();

		update_option( Settings::OPTION, array( Settings::ENABLED => false ) );
		$this->assertFalse( $settings->is_enabled() );

		update_option( Settings::OPTION, array( Settings::ENABLED => '0' ) );
		$this->assertFalse( $settings->is_enabled() );

		update_option( Settings::OPTION, array( Settings::ENABLED => true ) );
		$this->assertTrue( $settings->is_enabled() );
	}

	/**
	 * The form is sanitized to a boolean; keys already stored stay and keys that are not ours are not stored.
	 *
	 * @return void
	 */
	public function test_sanitize(): void {
		$settings = new Settings();

		update_option( Settings::OPTION, array( 'kept' => 'yes' ) );

		$this->assertSame(
			array(
				'kept'           => 'yes',
				Settings::ENABLED => true,
			),
			$settings->sanitize(
				array(
					Settings::ENABLED => '1',
					'evil' => 'x',
				)
			)
		);
		$this->assertSame(
			array(
				'kept'           => 'yes',
				Settings::ENABLED => false,
			),
			$settings->sanitize( array() ),
			'An unchecked box is not posted: disabled.'
		);
		$this->assertFalse( $settings->sanitize( 'nonsense' )[ Settings::ENABLED ] );
	}

	/**
	 * Enabled: the module reads the query string and adds the class to the body.
	 *
	 * @return void
	 */
	public function test_the_module_when_enabled(): void {
		update_option( Settings::OPTION, array( Settings::ENABLED => true ) );

		$module = $this->module( array( 'print' => '' ) );

		$this->assertSame( 'print', $module->active()->mode()->slug() );
		$this->assertSame( array( 'modes-mode-print' ), $module->body_class( array() ) );
	}

	/**
	 * Disabled: every request is in the default mode, the query string counts for nothing, the body gets no class and no variant is applied.
	 *
	 * @return void
	 */
	public function test_the_module_when_disabled(): void {
		update_option( Settings::OPTION, array( Settings::ENABLED => false ) );

		$module = $this->module( array( 'mode' => 'print' ) );

		$this->assertSame( 'web', $module->active()->mode()->slug() );
		$this->assertFalse( $module->active()->is_explicit() );
		$this->assertSame( array( 'home' ), $module->body_class( array( 'home' ) ) );
		$this->assertSame( array( 'single.php' ), $module->applier()->hierarchy( array( 'single.php' ) ) );

		$block = array(
			'blockName' => 'core/template-part',
			'attrs'     => array( 'slug' => 'header' ),
		);

		$this->assertSame( $block, $module->applier()->block_data( $block ) );
	}

	/**
	 * The setting is read each time: enabling it takes effect at once.
	 *
	 * @return void
	 */
	public function test_the_setting_is_read_each_time(): void {
		$module = $this->module( array() );

		update_option( Settings::OPTION, array( Settings::ENABLED => false ) );
		$this->assertSame( array(), $module->body_class( array() ) );

		update_option( Settings::OPTION, array( Settings::ENABLED => true ) );
		$this->assertSame( array( 'modes-mode-web' ), $module->body_class( array() ) );
	}

	/**
	 * Uninstalling removes the setting.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_the_setting(): void {
		update_option( Settings::OPTION, array( Settings::ENABLED => false ) );

		$this->module( array() )->uninstall();

		$this->assertFalse( get_option( Settings::OPTION ) );
	}
}
