<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI commands `wp otherguise books project`.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Cli;

use Otherguise\Books\Project\PostTypes;
use Otherguise\Books\Project\Projects;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, shows and changes the book projects (slice 230). `create` and `set-profile` can be run twice: nothing changes the second time.
 */
final class ProjectCommand {
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
	 * The relations of the projects (`ProjectLinks`).
	 *
	 * @var object
	 */
	private $links;

	/**
	 * Tells whether a post is a section: receives its id.
	 *
	 * @var callable
	 */
	private $is_section;

	/**
	 * Builds the command.
	 *
	 * @param Projects      $projects   Projects.
	 * @param object        $store      The posts (`WordPressProjects`).
	 * @param object        $links      The relations of the projects (`ProjectLinks`).
	 * @param callable      $is_section Receives a post id; tells whether it is a section.
	 * @param callable|null $log        Default `WP_CLI::log`.
	 * @param callable|null $success    Default `WP_CLI::success`.
	 * @param callable|null $fail       Default `WP_CLI::error`.
	 */
	public function __construct( Projects $projects, $store, $links, $is_section, $log = null, $success = null, $fail = null ) {
		$this->projects   = $projects;
		$this->store      = $store;
		$this->links      = $links;
		$this->is_section = $is_section;
		$this->log        = $log ?? array( 'WP_CLI', 'log' );
		$this->success    = $success ?? array( 'WP_CLI', 'success' );
		$this->fail       = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Lists the book projects: id, title, root section, profile.
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

		$rows = array_values( $this->projects->all() );

		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			( $this->log )( (string) wp_json_encode( $rows ) );
			return;
		}

		foreach ( $rows as $row ) {
			( $this->log )( implode( "\t", array( $row['id'], $row['title'], $row['root'], $row['profile'] ) ) );
		}
	}

	/**
	 * Shows a book project and the rules it gives to the page.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Id of the project.
	 *
	 * @param array $args       Positional arguments: the id.
	 * @param array $assoc_args Options (none).
	 * @return void
	 */
	public function show( $args, $assoc_args ) {
		unset( $assoc_args );

		$project = $this->projects->get( (int) ( $args[0] ?? 0 ) );

		if ( null === $project ) {
			( $this->fail )( 'No book project with that id.' );
			return;
		}

		foreach ( $project as $key => $value ) {
			( $this->log )( $key . ': ' . ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value ) );
		}

		$found = $project['root'] > 0 ? $this->projects->for_post( $project['root'], $project['id'] ) : null;

		( $this->log )( 'factor: ' . ( null === $found ? '(none)' : $found['factor'] ) );
		( $this->log )( 'css: ' . ( null === $found ? '(none: the profile or the root section is missing)' : $found['css'] ) );
	}

	/**
	 * Creates a book project on a top-level section with a printer profile.
	 *
	 * A project that has that title already is left as it is, so the command can be run again; `--dry-run` writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * <title>
	 * : Title of the project.
	 *
	 * --root=<section>
	 * : Id of the top-level section.
	 *
	 * --profile=<profile>
	 * : Id of the printer profile.
	 *
	 * [--scaling=<scaling>]
	 * : How the factor of the page is applied: adapt, zoom or scale.
	 * ---
	 * default: adapt
	 * options:
	 *   - adapt
	 *   - zoom
	 *   - scale
	 * ---
	 *
	 * [--dry-run]
	 * : Say what would be done.
	 *
	 * @param array $args       Positional arguments: the title.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function create( $args, $assoc_args ) {
		$title   = trim( (string) ( $args[0] ?? '' ) );
		$root    = (int) ( $assoc_args['root'] ?? 0 );
		$profile = (int) ( $assoc_args['profile'] ?? 0 );

		if ( '' === $title ) {
			( $this->fail )( 'A title is needed.' );
			return;
		}

		if ( $root <= 0 || ! ( $this->is_section )( $root ) ) {
			( $this->fail )( '--root: the id of a section.' );
			return;
		}

		if ( null === $this->projects->profile( $profile ) ) {
			( $this->fail )( '--profile: the id of a printer profile.' );
			return;
		}

		$scaling = (string) ( $assoc_args['scaling'] ?? 'adapt' );

		if ( ! in_array( $scaling, Projects::SCALINGS, true ) ) {
			( $this->fail )( '--scaling: adapt, zoom or scale.' );
			return;
		}

		foreach ( $this->projects->all() as $project ) {
			if ( $project['title'] === $title ) {
				( $this->log )( sprintf( 'The project "%s" exists already (%d): left as it is.', $title, $project['id'] ) );
				return;
			}
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			( $this->log )( sprintf( 'Would create the project "%s".', $title ) );
			return;
		}

		$id = $this->store->create( PostTypes::PROJECT, $title, Projects::normalize( array( 'scaling' => $scaling ) ) );

		if ( $id <= 0 || ! $this->links->set_root( $id, $root ) || ! $this->links->set_profile( $id, $profile ) ) {
			( $this->fail )( 'The project could not be created.' );
			return;
		}

		( $this->success )( sprintf( 'Project %d created.', $id ) );
	}

	/**
	 * Gives a book project another printer profile.
	 *
	 * @subcommand set-profile
	 *
	 * ## OPTIONS
	 *
	 * <project>
	 * : Id of the project.
	 *
	 * <profile>
	 * : Id of the printer profile.
	 *
	 * [--dry-run]
	 * : Say what would be done.
	 *
	 * @param array $args       Positional arguments: the project and the profile.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function set_profile( $args, $assoc_args ) {
		$project = $this->projects->get( (int) ( $args[0] ?? 0 ) );
		$profile = (int) ( $args[1] ?? 0 );

		if ( null === $project ) {
			( $this->fail )( 'No book project with that id.' );
			return;
		}

		if ( null === $this->projects->profile( $profile ) ) {
			( $this->fail )( 'No printer profile with that id.' );
			return;
		}

		if ( $project['profile'] === $profile ) {
			( $this->log )( sprintf( 'The project %d has the profile %d already.', $project['id'], $profile ) );
			return;
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			( $this->log )( sprintf( 'Would give the project %d the profile %d.', $project['id'], $profile ) );
			return;
		}

		$this->links->set_profile( $project['id'], $profile );
		( $this->success )( sprintf( 'The project %d has now the profile %d.', $project['id'], $profile ) );
	}

	/**
	 * Moves the root section and the profile of the projects made before the relations (slice 230) from their meta to relations.
	 *
	 * A project that has its relations already is left as it is, so the command can be run again; `--dry-run` writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Say what would be done.
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function migrate( $args, $assoc_args ) {
		unset( $args );

		$done = 0;

		foreach ( $this->store->projects() as $row ) {
			$meta = is_array( $row['meta'] ) ? $row['meta'] : array();

			if ( ! isset( $meta['root'] ) && ! isset( $meta['profile'] ) ) {
				continue;
			}

			if ( isset( $assoc_args['dry-run'] ) ) {
				( $this->log )( sprintf( 'Would move the root and the profile of the project %d to relations.', $row['id'] ) );
				++$done;
				continue;
			}

			if ( (int) ( $meta['root'] ?? 0 ) > 0 && 0 === (int) $this->links->root( $row['id'] ) ) {
				$this->links->set_root( $row['id'], (int) $meta['root'] );
			}

			if ( (int) ( $meta['profile'] ?? 0 ) > 0 && 0 === (int) $this->links->profile( $row['id'] ) ) {
				$this->links->set_profile( $row['id'], (int) $meta['profile'] );
			}

			$this->store->update_project( $row['id'], Projects::normalize( $meta ) );
			++$done;
		}

		( $this->success )( sprintf( '%d project(s) %s.', $done, isset( $assoc_args['dry-run'] ) ? 'to migrate' : 'migrated' ) );
	}
}
