<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the WP-CLI commands `wp otherguise books renderer` (slice 220).
 *
 * @package Otherguise
 */

use Otherguise\Books\Cli\RendererCommand;
use Otherguise\Books\Module;
use Otherguise\Books\Settings\RendererSettings;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress, no network: the output functions and the health check are doubles.
 *
 * @covers \Otherguise\Books\Cli\RendererCommand
 * @covers \Otherguise\Books\Settings\RendererSettings
 * @covers \Otherguise\Books\Module
 */
class BooksCliTest extends TestCase {

	/**
	 * Lines printed, in order, with their kind.
	 *
	 * @var array
	 */
	private $out = array();

	/**
	 * Starts without any option.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		$this->out = array();
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
	 * Builds the command with doubles; an error stops the command by an exception, as `WP_CLI::error` stops the process.
	 *
	 * @param array $health Answer of the health check.
	 * @return RendererCommand
	 */
	private function command( array $health = array( 'up' => true ) ) {
		return new RendererCommand(
			new RendererSettings(),
			static function ( $address ) use ( $health ) {
				return $health + array( 'asked' => $address );
			},
			function ( $line ) {
				$this->out[] = array( 'log', $line );
			},
			function ( $line ) {
				$this->out[] = array( 'success', $line );
			},
			static function ( $message ) {
				throw new RuntimeException( esc_html( $message ) );
			}
		);
	}

	/**
	 * Runs a command and returns the error message, or null.
	 *
	 * @param callable $run What to run.
	 * @return string|null
	 */
	private function failure( callable $run ) {
		try {
			$run();
		} catch ( RuntimeException $exception ) {
			return $exception->getMessage();
		}

		return null;
	}

	/**
	 * `get` prints the settings as text or JSON, and refuses another format.
	 *
	 * @return void
	 */
	public function test_get(): void {
		update_option(
			RendererSettings::OPTION,
			array(
				'gotenberg_url' => 'http://g:3000',
				'wait_delay' => 1.5,
			)
		);

		$this->command()->get( array(), array() );

		$this->assertSame(
			array(
				array( 'log', 'gotenberg_url: http://g:3000' ),
				array( 'log', 'wait_delay: 1.5' ),
				array( 'log', 'wait_expression: ' ),
				array( 'log', 'wait_selector: ' ),
				array( 'log', 'media: print' ),
				array( 'log', 'keep_days: 7' ),
			),
			$this->out
		);

		$this->out = array();
		$this->command()->get( array(), array( 'format' => 'json' ) );
		$this->assertCount( 1, $this->out );
		$this->assertSame( 'http://g:3000', json_decode( $this->out[0][1], true )['gotenberg_url'] );

		$this->assertSame( '--format: text or json.', $this->failure( fn() => $this->command()->get( array(), array( 'format' => 'xml' ) ) ) );
	}

	/**
	 * `set` changes what is given, says so, and changes nothing the second time.
	 *
	 * @return void
	 */
	public function test_set_is_idempotent(): void {
		$options = array(
			'address'    => 'http://gotenberg-testing:3000/',
			'wait-delay' => '2',
			'media'      => 'screen',
			'keep-days'  => '30',
		);

		$this->command()->set( array(), $options );

		$this->assertSame(
			array(
				array( 'log', 'Changed gotenberg_url: (empty) -> http://gotenberg-testing:3000' ),
				array( 'log', 'Changed wait_delay: 0 -> 2' ),
				array( 'log', 'Changed media: print -> screen' ),
				array( 'log', 'Changed keep_days: 7 -> 30' ),
				array( 'success', 'Saved.' ),
			),
			$this->out
		);
		$this->assertSame(
			array(
				'gotenberg_url'   => 'http://gotenberg-testing:3000',
				'wait_delay'      => 2.0,
				'wait_expression' => '',
				'wait_selector'   => '',
				'media'           => 'screen',
				'keep_days'       => 30,
			),
			( new RendererSettings() )->get()
		);

		$this->out = array();
		$this->command()->set( array(), $options );
		$this->assertSame( array( array( 'success', 'Nothing to change.' ) ), $this->out, 'Run again: nothing changes.' );
	}

	/**
	 * `set` keeps the settings that are not given.
	 *
	 * @return void
	 */
	public function test_set_changes_only_what_is_given(): void {
		update_option(
			RendererSettings::OPTION,
			array(
				'gotenberg_url' => 'http://old:3000',
				'media' => 'screen',
				'wait_selector' => '.ready',
			)
		);

		$this->command()->set(
			array(),
			array(
				'address' => 'http://new:3000',
				'wait-expression' => 'window.status === "ready"',
			)
		);

		$settings = ( new RendererSettings() )->get();

		$this->assertSame( 'http://new:3000', $settings['gotenberg_url'] );
		$this->assertSame( 'screen', $settings['media'] );
		$this->assertSame( '.ready', $settings['wait_selector'] );
		$this->assertSame( 'window.status === "ready"', $settings['wait_expression'] );

		$this->out = array();
		$this->command()->set( array(), array( 'wait-selector' => '' ) );
		$this->assertStringContainsString( 'wait_selector: .ready -> (empty)', $this->out[0][1], 'An empty text clears an option.' );
		$this->assertSame( '', ( new RendererSettings() )->get()['wait_selector'] );
	}

	/**
	 * `--dry-run` reports and writes nothing.
	 *
	 * @return void
	 */
	public function test_set_dry_run(): void {
		$this->command()->set(
			array(),
			array(
				'address' => 'http://g:3000',
				'dry-run' => true,
			)
		);

		$this->assertSame(
			array(
				array( 'log', 'Would change gotenberg_url: (empty) -> http://g:3000' ),
				array( 'success', 'Dry run: nothing was written.' ),
			),
			$this->out
		);
		$this->assertFalse( get_option( RendererSettings::OPTION ), 'Nothing was written.' );
	}

	/**
	 * Wrong values and no option are errors, and nothing is changed, even for the right values given with them.
	 *
	 * @return void
	 */
	public function test_set_refuses_wrong_values(): void {
		update_option( RendererSettings::OPTION, array( 'gotenberg_url' => 'http://old:3000' ) );

		$cases = array(
			array( array(), 'Give at least one option' ),
			array( array( 'address' => 'ftp://nope' ), 'The address is not valid' ),
			array( array( 'address' => '' ), 'The address is not valid' ),
			array( array( 'wait-delay' => '61' ), 'between 0 and 60' ),
			array( array( 'wait-delay' => '-1' ), 'between 0 and 60' ),
			array( array( 'wait-delay' => 'soon' ), 'between 0 and 60' ),
			array( array( 'wait-expression' => str_repeat( 'a', 501 ) ), 'one line, at most 500' ),
			array( array( 'wait-selector' => "a\nb" ), 'one line, at most 200' ),
			array( array( 'media' => 'tv' ), 'print or screen' ),
			array( array( 'keep-days' => '0' ), 'between 1 and 90' ),
			array( array( 'keep-days' => '2.5' ), 'between 1 and 90' ),
			array( array( 'keep-days' => '91' ), 'between 1 and 90' ),
		);

		foreach ( $cases as $case ) {
			$message = $this->failure( fn() => $this->command()->set( array(), $case[0] ) );

			$this->assertNotNull( $message, 'Refused: ' . wp_json_encode( $case[0] ) );
			$this->assertStringContainsString( $case[1], (string) $message );
		}

		$this->assertSame( 'http://old:3000', ( new RendererSettings() )->get()['gotenberg_url'], 'Nothing was changed.' );

		$this->assertNotNull(
			$this->failure(
				fn() => $this->command()->set(
					array(),
					array(
						'address' => 'http://new:3000',
						'media' => 'tv',
					)
				)
			)
		);
		$this->assertSame( 'http://old:3000', ( new RendererSettings() )->get()['gotenberg_url'], 'The right value given with a wrong one is not written.' );
	}

	/**
	 * `unset` empties the address and keeps the rest; again, nothing to change.
	 *
	 * @return void
	 */
	public function test_unset(): void {
		update_option(
			RendererSettings::OPTION,
			array(
				'gotenberg_url' => 'http://g:3000',
				'media' => 'screen',
			)
		);

		$this->command()->unset( array(), array( 'dry-run' => true ) );
		$this->assertSame( 'http://g:3000', ( new RendererSettings() )->get()['gotenberg_url'] );

		$this->out = array();
		$this->command()->unset( array(), array() );
		$this->assertSame( 'Changed gotenberg_url: http://g:3000 -> (empty)', $this->out[0][1] );
		$this->assertSame( '', ( new RendererSettings() )->get()['gotenberg_url'] );
		$this->assertSame( 'screen', ( new RendererSettings() )->get()['media'] );

		$this->out = array();
		$this->command()->unset( array(), array() );
		$this->assertSame( array( array( 'success', 'Nothing to change.' ) ), $this->out );
	}

	/**
	 * `check` succeeds when the server is up and fails with the message otherwise, or when there is no server.
	 *
	 * @return void
	 */
	public function test_check(): void {
		$this->assertSame( 'No PDF server is configured.', $this->failure( fn() => $this->command()->check( array(), array() ) ) );

		update_option( RendererSettings::OPTION, array( 'gotenberg_url' => 'http://g:3000' ) );

		$this->command()->check( array(), array() );
		$this->assertSame( array( array( 'success', 'The server http://g:3000 answers.' ) ), $this->out );

		$message = $this->failure(
			fn() => $this->command(
				array(
					'up'      => false,
					'message' => 'Could not resolve host',
				)
			)->check( array(), array() )
		);
		$this->assertSame( 'The server http://g:3000 does not answer: Could not resolve host', $message );

		$this->assertStringContainsString( 'does not answer', (string) $this->failure( fn() => $this->command( array( 'up' => false ) )->check( array(), array() ) ) );
	}

	/**
	 * The module registers the command on `cli_init`.
	 *
	 * @return void
	 */
	public function test_the_module_registers_the_command(): void {
		$hooks  = array();
		$module = new Module(
			static function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[ $hook ][] = $callback[1];
			},
			static fn( $file ) => $file
		);
		$module->boot();

		$this->assertSame( array( 'register_command' ), $hooks['cli_init'] );

		WP_CLI::$commands = array();
		$module->register_command();

		$this->assertArrayHasKey( 'otherguise books renderer', WP_CLI::$commands );
		$this->assertInstanceOf( RendererCommand::class, WP_CLI::$commands['otherguise books renderer'] );
	}

	/**
	 * The default output functions are those of WP-CLI.
	 *
	 * @return void
	 */
	public function test_the_defaults(): void {
		$command = new RendererCommand( new RendererSettings(), static fn() => array() );

		foreach ( array(
			'log' => 'log',
			'success' => 'success',
			'fail' => 'error',
		) as $property => $method ) {
			$reflection = new ReflectionProperty( RendererCommand::class, $property );
			$reflection->setAccessible( true );
			$this->assertSame( array( 'WP_CLI', $method ), $reflection->getValue( $command ) );
		}

		$store = new ReflectionProperty( RendererCommand::class, 'store' );
		$store->setAccessible( true );
		$this->assertSame( 'update_option', $store->getValue( $command ) );
	}
}
