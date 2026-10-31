<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI: the private links.
 *
 * @package Otherguise
 */

namespace Otherguise\Access\Cli;

use Otherguise\Access\Link\Links;
use Otherguise\Access\Unlisted\Unlisted;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise access list|create|revoke` (slice 400). A command line has no logged-in user, so no capability is asked. Each command can
 * be run twice. The token is never printed unless `--show-token` is given.
 */
final class LinksCommand {
	/**
	 * The links.
	 *
	 * @var Links
	 */
	private $links;

	/**
	 * Reads a post.
	 *
	 * @var callable
	 */
	private $get_post;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Prints a success.
	 *
	 * @var callable
	 */
	private $success;

	/**
	 * Stops with an error.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * The unlisted state.
	 *
	 * @var Unlisted
	 */
	private $unlisted;

	/**
	 * Tells whether the private links are on.
	 *
	 * @var callable
	 */
	private $enabled;

	/**
	 * Builds the command.
	 *
	 * @param Links         $links    The links.
	 * @param callable|null $get_post Default `get_post`.
	 * @param callable|null $log      Default `WP_CLI::log`.
	 * @param callable|null $success  Default `WP_CLI::success`.
	 * @param callable|null $fail     Default `WP_CLI::error`.
	 * @param callable|null $enabled  Tells whether the private links are on (slice 401); default: yes.
	 * @param Unlisted|null $unlisted The unlisted state (slice 402); default: built from the links.
	 */
	public function __construct( Links $links, $get_post = null, $log = null, $success = null, $fail = null, $enabled = null, $unlisted = null ) {
		$this->unlisted = $unlisted ?? new Unlisted( $links );
		$this->enabled  = $enabled ?? static function () {
			return true;
		};
		$this->links    = $links;
		$this->get_post = $get_post ?? 'get_post';
		$this->log      = $log ?? array( 'WP_CLI', 'log' );
		$this->success  = $success ?? array( 'WP_CLI', 'success' );
		$this->fail     = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Lists the posts that have a link.
	 *
	 * ## OPTIONS
	 *
	 * [--show-token]
	 * : Print the address of the link too (it gives access to the post: keep it out of logs).
	 *
	 * [--format=<format>]
	 * : `table` (default) or `json`.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		unset( $args );

		$format = $assoc_args['format'] ?? 'table';

		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			( $this->fail )( '--format: table or json.' );
			return;
		}

		$rows = array();

		foreach ( $this->links->all() as $id => $created ) {
			$post = ( $this->get_post )( $id );
			$row  = array(
				'id'      => $id,
				'status'  => is_object( $post ) ? (string) $post->post_status : '',
				'title'   => is_object( $post ) ? (string) $post->post_title : '',
				'created' => $created,
			);

			if ( ! empty( $assoc_args['show-token'] ) ) {
				$row['url'] = (string) $this->links->address( $id );
			}

			$rows[] = $row;
		}

		if ( 'json' === $format ) {
			( $this->log )( (string) wp_json_encode( $rows ) );
			return;
		}

		foreach ( $rows as $row ) {
			( $this->log )( implode( "\t", $row ) );
		}
	}

	/**
	 * Makes the link of a post (the one it has is kept).
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Id of the post.
	 *
	 * [--show-token]
	 * : Print the address of the link.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function create( $args, $assoc_args ) {
		if ( ! (bool) ( $this->enabled )() ) {
			( $this->fail )( 'The private links are switched off (Otherguise, Access): no link is made.' );
			return;
		}

		$id   = $this->id( $args[0] ?? null );
		$post = ( $this->get_post )( $id );

		if ( ! is_object( $post ) ) {
			( $this->fail )( 'This post does not exist.' );
			return;
		}

		if ( ! $this->links->eligible( $post ) ) {
			( $this->fail )( 'This post cannot have a link: it is in the trash or it is not a page of the site.' );
			return;
		}

		$had = '' !== $this->links->token( $id );

		$this->links->create( $id );

		if ( ! empty( $assoc_args['show-token'] ) ) {
			( $this->log )( (string) $this->links->address( $id ) );
		}

		( $this->success )( $had ? sprintf( 'Post %d had a link already.', $id ) : sprintf( 'Link made for post %d.', $id ) );
	}

	/**
	 * Takes the link of a post away.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Id of the post.
	 *
	 * [--dry-run]
	 * : Say what would be done.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function revoke( $args, $assoc_args ) {
		$id  = $this->id( $args[0] ?? null );
		$had = '' !== $this->links->token( $id );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			( $this->log )( $had ? sprintf( 'Would revoke the link of post %d.', $id ) : sprintf( 'Post %d has no link.', $id ) );
			return;
		}

		$this->links->revoke( $id );

		( $this->success )( $had ? sprintf( 'Link of post %d revoked.', $id ) : sprintf( 'Post %d had no link.', $id ) );
	}

	/**
	 * Hides a published post from the lists of the site (home page, archives, search, feeds, sitemaps, previous and next links), or shows
	 * it again. Its address keeps working. It can be run twice.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Id of the post.
	 *
	 * [--off]
	 * : Show the post in the lists again.
	 *
	 * [--dry-run]
	 * : Say what would be done.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function unlisted( $args, $assoc_args ) {
		$id   = $this->id( $args[0] ?? null );
		$post = ( $this->get_post )( $id );
		$off  = ! empty( $assoc_args['off'] );

		if ( ! is_object( $post ) ) {
			( $this->fail )( 'This post does not exist.' );
			return;
		}

		if ( ! $off && ! $this->unlisted->eligible( $post ) ) {
			( $this->fail )( 'This post cannot be unlisted: it is in the trash or it is not a page of the site.' );
			return;
		}

		$was = $this->unlisted->is_unlisted( $id );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			( $this->log )( sprintf( $off ? 'Would show post %d in the lists again (it is %s).' : 'Would hide post %d from the lists (it is %s).', $id, $was ? 'unlisted' : 'listed' ) );
			return;
		}

		$this->unlisted->set( $id, ! $off );

		( $this->success )( sprintf( $off ? 'Post %d is listed again.' : 'Post %d is unlisted.', $id ) );
	}

	/**
	 * Lists the unlisted posts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : `table` (default) or `json`.
	 *
	 * @subcommand unlisted-list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function unlisted_list( $args, $assoc_args ) {
		unset( $args );

		$rows = array();

		foreach ( $this->unlisted->ids() as $id ) {
			$post   = ( $this->get_post )( $id );
			$rows[] = array(
				'id'     => $id,
				'status' => is_object( $post ) ? (string) $post->post_status : '',
				'title'  => is_object( $post ) ? (string) $post->post_title : '',
			);
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			( $this->log )( (string) wp_json_encode( $rows ) );
			return;
		}

		if ( array() === $rows ) {
			( $this->log )( 'No unlisted post.' );
			return;
		}

		foreach ( $rows as $row ) {
			( $this->log )( sprintf( '%d  %s  %s', $row['id'], $row['status'], $row['title'] ) );
		}
	}

	/**
	 * Reads an id.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function id( $value ) {
		if ( ! is_string( $value ) || ! ctype_digit( $value ) || (int) $value <= 0 ) {
			( $this->fail )( 'The post is given by its id.' );

			return 0;
		}

		return (int) $value;
	}
}
