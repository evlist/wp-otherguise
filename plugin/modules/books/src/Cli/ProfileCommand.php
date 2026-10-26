<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI commands `wp otherguise books profile`.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Cli;

use Otherguise\Books\Project\PostTypes;
use Otherguise\Books\Project\PrinterProfile;
use Otherguise\Books\Project\Presets;
use Otherguise\Books\Project\Projects;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, shows and creates the printer profiles (slice 230). `create` can be run twice: a profile with that title is left as it is.
 */
final class ProfileCommand {
	/**
	 * Projects.
	 *
	 * @var Projects
	 */
	private $projects;

	/**
	 * The posts.
	 *
	 * @var WordPressProjects
	 */
	private $store;

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
	 * Stops with an error message.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Builds the command.
	 *
	 * @param Projects      $projects Projects.
	 * @param object        $store    The posts (`WordPressProjects`).
	 * @param callable|null $log      Default `WP_CLI::log`.
	 * @param callable|null $success  Default `WP_CLI::success`.
	 * @param callable|null $fail     Default `WP_CLI::error`.
	 */
	public function __construct( Projects $projects, $store, $log = null, $success = null, $fail = null ) {
		$this->projects = $projects;
		$this->store    = $store;
		$this->log      = $log ?? array( 'WP_CLI', 'log' );
		$this->success  = $success ?? array( 'WP_CLI', 'success' );
		$this->fail     = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Lists the printer profiles: id, title, page size, safety zone.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : `text` (tab-separated lines) or `json`.
	 * ---
	 * default: text
	 * ---
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		unset( $args );

		$rows = array();

		foreach ( $this->store->profiles() as $id => $title ) {
			$profile = $this->projects->profile( $id ) ?? PrinterProfile::defaults();
			$rows[]  = array(
				'id'    => $id,
				'title' => $title,
				'page'  => $profile['width_mm'] . 'x' . $profile['height_mm'],
				'safe'  => $profile['safe_mm'],
			);
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			( $this->log )( (string) wp_json_encode( $rows ) );
			return;
		}

		foreach ( $rows as $row ) {
			( $this->log )( implode( "\t", $row ) );
		}
	}

	/**
	 * Shows the fields of a printer profile.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Id of the profile.
	 *
	 * [--format=<format>]
	 * : `text` (one `key: value` line per field) or `json`.
	 * ---
	 * default: text
	 * ---
	 *
	 * @param array $args       Positional arguments: the id.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function show( $args, $assoc_args ) {
		$profile = $this->projects->profile( (int) ( $args[0] ?? 0 ) );

		if ( null === $profile ) {
			( $this->fail )( 'No printer profile with that id.' );
			return;
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			( $this->log )( (string) wp_json_encode( $profile ) );
			return;
		}

		foreach ( $profile as $key => $value ) {
			( $this->log )( $key . ': ' . ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value ) );
		}
	}

	/**
	 * Creates a printer profile, from a preset (`coollibri`, `flexilivre`) or from the defaults.
	 *
	 * A preset is a starting point written from the documentation of the printer on 2026-10-26: check it against the printer's site. A
	 * profile that has that title already is left as it is, so the command can be run again; `--dry-run` writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * <title>
	 * : Title of the profile.
	 *
	 * [--preset=<preset>]
	 * : `coollibri` or `flexilivre`.
	 *
	 * [--dry-run]
	 * : Say what would be done.
	 *
	 * @param array $args       Positional arguments: the title.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function create( $args, $assoc_args ) {
		$title  = trim( (string) ( $args[0] ?? '' ) );
		$preset = (string) ( $assoc_args['preset'] ?? '' );
		$values = '' === $preset ? PrinterProfile::defaults() : Presets::profile( $preset );

		if ( '' === $title ) {
			( $this->fail )( 'A title is needed.' );
			return;
		}

		if ( null === $values ) {
			( $this->fail )( '--preset: ' . implode( ' or ', array_keys( Presets::all() ) ) . '.' );
			return;
		}

		$existing = array_search( $title, $this->store->profiles(), true );

		if ( false !== $existing ) {
			( $this->log )( sprintf( 'The profile "%s" exists already (%d): left as it is.', $title, $existing ) );
			return;
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			( $this->log )( sprintf( 'Would create the profile "%s".', $title ) );
			return;
		}

		$id = $this->store->create( PostTypes::PROFILE, $title, $values );

		if ( $id <= 0 ) {
			( $this->fail )( 'The profile could not be created.' );
			return;
		}

		( $this->success )( sprintf( 'Profile %d created.', $id ) );
	}
}
