<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI commands `wp otherguise books renderer`.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Cli;

use Otherguise\Books\Settings\RendererSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the PDF rendering server of the site.
 *
 * Reads, sets, turns off and checks the server (slice 220), so that the scripts that copy a site onto another can point the copy at its
 * own server. `set` can be run again: it changes only what differs and says so; `--dry-run` writes nothing. The option of the address is
 * `--address`, not `--url`: WP-CLI keeps `--url` for itself (the address of the site) and would not give it to the command.
 */
final class RendererCommand {
	/**
	 * Settings.
	 *
	 * @var RendererSettings
	 */
	private $settings;

	/**
	 * Checks a server: receives its address, returns `up` and `message`.
	 *
	 * @var callable
	 */
	private $health;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Prints a success message.
	 *
	 * @var callable
	 */
	private $success;

	/**
	 * Stops with an error message (exit status 1).
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Stores the settings: receives the option name and the value.
	 *
	 * @var callable
	 */
	private $store;

	/**
	 * Builds the command.
	 *
	 * @param RendererSettings $settings Settings.
	 * @param callable         $health   Receives an address and returns `up` and `message`.
	 * @param callable|null    $log      Defaults to `WP_CLI::log`.
	 * @param callable|null    $success  Defaults to `WP_CLI::success`.
	 * @param callable|null    $fail     Defaults to `WP_CLI::error`.
	 * @param callable|null    $store    Defaults to `update_option`.
	 */
	public function __construct( RendererSettings $settings, $health, $log = null, $success = null, $fail = null, $store = null ) {
		$this->settings = $settings;
		$this->health   = $health;
		$this->log      = $log ?? array( 'WP_CLI', 'log' );
		$this->success  = $success ?? array( 'WP_CLI', 'success' );
		$this->fail     = $fail ?? array( 'WP_CLI', 'error' );
		$this->store    = $store ?? 'update_option';
	}

	/**
	 * Prints the settings of the PDF server.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : `text` (one `key: value` line per setting) or `json`.
	 * ---
	 * default: text
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise books renderer get
	 *     wp otherguise books renderer get --format=json
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function get( $args, $assoc_args ) {
		unset( $args );

		$values = $this->settings->get();
		$format = $assoc_args['format'] ?? 'text';

		if ( 'json' === $format ) {
			( $this->log )( (string) wp_json_encode( $values ) );
			return;
		}

		if ( 'text' !== $format ) {
			( $this->fail )( '--format: text or json.' );
			return;
		}

		foreach ( $values as $key => $value ) {
			( $this->log )( $key . ': ' . $value );
		}
	}

	/**
	 * Sets the address of the server and the options of the conversion.
	 *
	 * Only the options given are changed, and they are checked as on the settings screen except that a wrong value is an error, not
	 * replaced by a default; nothing is changed when one is wrong. The command says what it changes and changes nothing when the values
	 * are already those, so it can be run again.
	 *
	 * ## OPTIONS
	 *
	 * [--address=<address>]
	 * : Address of the Gotenberg server, an http or https address such as `http://gotenberg:3000` (not `--url`: WP-CLI keeps it for the site).
	 *
	 * [--wait-delay=<seconds>]
	 * : A fixed wait before the page is converted, from 0 to 60.
	 *
	 * [--wait-expression=<js>]
	 * : A JavaScript expression that the page makes true when it is ready (empty for none).
	 *
	 * [--wait-selector=<css>]
	 * : A CSS selector of an element that appears when the page is ready (empty for none).
	 *
	 * [--media=<media>]
	 * : The media the page is printed with: `print` or `screen`.
	 *
	 * [--keep-days=<n>]
	 * : How many days the PDF files are kept, from 1 to 90.
	 *
	 * [--dry-run]
	 * : Say what would change, write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise books renderer set --address=http://gotenberg-testing:3000
	 *     wp otherguise books renderer set --media=screen --wait-delay=2 --dry-run
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function set( $args, $assoc_args ) {
		unset( $args );

		$names   = array(
			'address'         => RendererSettings::URL,
			'wait-delay'      => RendererSettings::WAIT_DELAY,
			'wait-expression' => RendererSettings::WAIT_EXPRESSION,
			'wait-selector'   => RendererSettings::WAIT_SELECTOR,
			'media'           => RendererSettings::MEDIA,
			'keep-days'       => RendererSettings::KEEP_DAYS,
		);
		$changes = array();

		foreach ( $names as $option => $key ) {
			if ( array_key_exists( $option, $assoc_args ) ) {
				$changes[ $key ] = $assoc_args[ $option ];
			}
		}

		if ( array() === $changes ) {
			( $this->fail )( 'Give at least one option: --address, --wait-delay, --wait-expression, --wait-selector, --media or --keep-days.' );
			return;
		}

		$checked = $this->settings->validate( $changes );

		if ( '' !== $checked['error'] ) {
			( $this->fail )( $checked['error'] );
			return;
		}

		$this->apply( $checked['values'], ! empty( $assoc_args['dry-run'] ) );
	}

	/**
	 * Turns the PDF mode off: the address is emptied, the other settings are kept.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Say what would change, write nothing.
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function unset( $args, $assoc_args ) {
		unset( $args );

		$this->apply( array( RendererSettings::URL => '' ), ! empty( $assoc_args['dry-run'] ) );
	}

	/**
	 * Checks that the server answers (`GET /health` of Gotenberg). Exit status 0 when it is up, 1 otherwise.
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise books renderer check
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options (none).
	 * @return void
	 */
	public function check( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$address = $this->settings->get()[ RendererSettings::URL ];

		if ( '' === $address ) {
			( $this->fail )( 'No PDF server is configured.' );
			return;
		}

		$health = ( $this->health )( $address );

		if ( empty( $health['up'] ) ) {
			( $this->fail )( 'The server ' . $address . ' does not answer: ' . (string) ( $health['message'] ?? '' ) );
			return;
		}

		( $this->success )( 'The server ' . $address . ' answers.' );
	}

	/**
	 * Applies changes: says what differs, writes unless it is a dry run.
	 *
	 * @param array<string, mixed> $changes New values by key.
	 * @param bool                 $dry_run Write nothing.
	 * @return void
	 */
	private function apply( array $changes, $dry_run ) {
		$current = $this->settings->get();
		$lines   = array();

		foreach ( $changes as $key => $value ) {
			if ( $current[ $key ] !== $value ) {
				$lines[]         = $key . ': ' . $this->shown( $current[ $key ] ) . ' -> ' . $this->shown( $value );
				$current[ $key ] = $value;
			}
		}

		if ( array() === $lines ) {
			( $this->success )( 'Nothing to change.' );
			return;
		}

		foreach ( $lines as $line ) {
			( $this->log )( ( $dry_run ? 'Would change ' : 'Changed ' ) . $line );
		}

		if ( $dry_run ) {
			( $this->success )( 'Dry run: nothing was written.' );
			return;
		}

		( $this->store )( RendererSettings::OPTION, $current );
		( $this->success )( 'Saved.' );
	}

	/**
	 * Shows a value in a report: an empty text is shown as `(empty)`.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function shown( $value ) {
		return '' === $value ? '(empty)' : (string) $value;
	}
}
