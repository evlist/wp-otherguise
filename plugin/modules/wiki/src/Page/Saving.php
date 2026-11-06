<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The name of a page is claimed when the page is saved.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Page;

use Otherguise\Wiki\NameException;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the index of the names true (slice 502). **Before the save** (REST, the block editor): the name is checked and the editor is told at
 * once, with an error 409 that says which name exists, and 400 for a title that the rules refuse or a page published with no space.
 * **After the save** (every way to save a page): the name is claimed, a new title is a **rename** (the old name stays as an alias), and a
 * page that cannot have its name is **put back to draft** with a notice, so that no published page is left without its name.
 */
final class Saving {
	/**
	 * Name of the transient that holds the notice of a user.
	 */
	public const NOTICE = 'wiki_name_notice_';

	/**
	 * The statuses of a page that is seen by other people or is waiting to be.
	 */
	private const VISIBLE = array( 'publish', 'future', 'private', 'pending' );

	/**
	 * Returns the service of the pages; called when needed.
	 *
	 * @var callable
	 */
	private $pages;

	/**
	 * Builds the hooks.
	 *
	 * @param callable $pages Returns the `Pages` service.
	 */
	public function __construct( $pages ) {
		$this->pages = $pages;
	}

	/**
	 * Checks the name before a page is saved through REST. Filter `rest_pre_insert_wiki_page`.
	 *
	 * @param mixed            $prepared The post as it will be saved.
	 * @param \WP_REST_Request $request  The request.
	 * @return mixed The post, or a `WP_Error`.
	 */
	public function check_rest( $prepared, $request ) {
		if ( ! is_object( $prepared ) || ! is_object( $request ) ) {
			return $prepared;
		}

		$pages  = $this->pages();
		$id     = (int) ( $prepared->ID ?? 0 );
		$stored = $id > 0 ? $pages->store()->get( $id ) : null;
		$meta   = $request['meta'] ?? array();
		$title  = isset( $prepared->post_title ) ? (string) $prepared->post_title : ( null === $stored ? '' : $stored['title'] );
		$status = isset( $prepared->post_status ) ? (string) $prepared->post_status : ( null === $stored ? 'draft' : $stored['status'] );
		$space  = is_array( $meta ) && isset( $meta[ PageStore::SPACE_META ] ) ? (string) $meta[ PageStore::SPACE_META ] : ( null === $stored ? '' : $stored['space'] );

		if ( null !== $stored && '' !== $stored['space'] && '' !== $space && ! hash_equals( $stored['space'], $pages->canonical_space( $space ) ) ) {
			return new \WP_Error( 'wiki_space_locked', __( 'The space of a page cannot be changed here: moving a page changes its address.', 'otherguise' ), array( 'status' => 400 ) );
		}

		if ( '' === $space && in_array( $status, self::VISIBLE, true ) ) {
			return new \WP_Error( 'wiki_no_space', __( 'Choose the space of the page first.', 'otherguise' ), array( 'status' => 400 ) );
		}

		if ( '' === $space || '' === trim( $title ) ) {
			return $prepared;
		}

		$refusal = $pages->check( $id, $space, $title );

		if ( null === $refusal ) {
			return $prepared;
		}

		return new \WP_Error(
			$refusal->error_code(),
			NameException::TAKEN === $refusal->error_code()
				/* translators: %s: the title typed. */
				? sprintf( __( 'A page of this name exists in this space: %s', 'otherguise' ), $title )
				: wp_specialchars_decode( $refusal->getMessage(), ENT_QUOTES ),
			array( 'status' => NameException::TAKEN === $refusal->error_code() ? 409 : 400 )
		);
	}

	/**
	 * Claims the name after a save through REST. Action `rest_after_insert_wiki_page`.
	 *
	 * @param \WP_Post $post The page.
	 * @return void
	 */
	public function after_rest( $post ) {
		$this->claim( is_object( $post ) ? (int) $post->ID : 0 );
	}

	/**
	 * Claims the name after any save. Action `wp_after_insert_post`.
	 *
	 * @param int $post_id The post.
	 * @return void
	 */
	public function after_insert( $post_id ) {
		$this->claim( (int) $post_id );
	}

	/**
	 * Claims the name of a page; one that cannot have it goes back to draft, with a notice for the user.
	 *
	 * @param int $id The page.
	 * @return void
	 */
	private function claim( $id ) {
		if ( $id <= 0 ) {
			return;
		}

		$pages = $this->pages();

		try {
			$pages->sync( $id );
		} catch ( NameException $refusal ) {
			$page = $pages->store()->get( $id );

			if ( null !== $page && in_array( $page['status'], self::VISIBLE, true ) ) {
				$pages->store()->set_status( $id, 'draft' );
			}

			set_transient( self::NOTICE . get_current_user_id(), $refusal->getMessage(), 120 );
		}
	}

	/**
	 * Tells the user why a page went back to draft. Action `admin_notices`.
	 *
	 * @return void
	 */
	public function notice() {
		$key     = self::NOTICE . get_current_user_id();
		$message = get_transient( $key );

		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $key );

		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The page was saved as a draft.', 'otherguise' ) . ' ' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * The service of the pages.
	 *
	 * @return Pages
	 */
	private function pages() {
		return ( $this->pages )();
	}
}
