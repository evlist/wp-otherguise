<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The creation of the page that does not exist.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Missing;

use Otherguise\Wiki\NameException;
use Otherguise\Wiki\Page\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * The form of the page that does not exist posts here (slice 504), on `admin-post.php`: the nonce **tied to the pair space and title** is
 * checked, the right to create is checked again, the space is looked up again and the name put through the rules; the page is created as a
 * **draft** with the title as it was in the address, and the user lands in its editor. If the name was taken in the meantime the user is
 * sent to that page; a refusal stops with a message and no page. The server decides: nothing is trusted from the form.
 */
final class CreateAction {
	/**
	 * The pages.
	 *
	 * @var Pages
	 */
	private $pages;

	/**
	 * The page that does not exist, for the right to create and the nonce.
	 *
	 * @var MissingPage
	 */
	private $missing;

	/**
	 * Gives the value posted for a field, or null.
	 *
	 * @var callable
	 */
	private $posted;

	/**
	 * Checks a nonce (value, action).
	 *
	 * @var callable
	 */
	private $verify;

	/**
	 * Redirects to an address and ends the request.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Stops the request with a message and a status.
	 *
	 * @var callable
	 */
	private $stop;

	/**
	 * Returns the address of the editor of a page.
	 *
	 * @var callable
	 */
	private $editor;

	/**
	 * Returns the address of a page.
	 *
	 * @var callable
	 */
	private $permalink;

	/**
	 * Builds the action.
	 *
	 * @param Pages         $pages     The pages.
	 * @param MissingPage   $missing   The page that does not exist.
	 * @param callable|null $posted    Receives a field name; default reads the request.
	 * @param callable|null $verify    Receives a nonce and an action; default `wp_verify_nonce`.
	 * @param callable|null $redirect  Receives an address; default `wp_safe_redirect()` and `exit`.
	 * @param callable|null $stop      Receives a message and a status; default `wp_die()`.
	 * @param callable|null $editor    Receives a page id; default the edit link of the page.
	 * @param callable|null $permalink Receives a page id; default `get_permalink`.
	 */
	public function __construct( Pages $pages, MissingPage $missing, $posted = null, $verify = null, $redirect = null, $stop = null, $editor = null, $permalink = null ) {
		$this->pages     = $pages;
		$this->missing   = $missing;
		$this->posted    = $posted ?? static function ( $name ) {
			$value = filter_input( INPUT_POST, $name );

			return is_string( $value ) ? $value : null;
		};
		$this->verify    = $verify ?? 'wp_verify_nonce';
		$this->redirect  = $redirect ?? static function ( $url ) {
			wp_safe_redirect( $url );
			exit;
		};
		$this->stop      = $stop ?? static function ( $message, $status ) {
			wp_die( esc_html( $message ), '', array( 'response' => (int) $status ) );
		};
		$this->editor    = $editor ?? static function ( $id ) {
			return (string) get_edit_post_link( (int) $id, 'raw' );
		};
		$this->permalink = $permalink ?? 'get_permalink';
	}

	/**
	 * Handles the form. Action `admin_post_wiki_create` (a logged-in user: there is no `nopriv` hook).
	 *
	 * @return void
	 */
	public function handle() {
		$space = (string) ( $this->posted )( 'wiki_space' );
		$title = (string) ( $this->posted )( 'wiki_title' );
		$nonce = (string) ( $this->posted )( '_wpnonce' );

		if ( '' === $nonce || ! ( $this->verify )( $nonce, MissingPage::nonce_action( $space, $title ) ) ) {
			( $this->stop )( __( 'This link has expired: ask for the page again.', 'otherguise' ), 403 );

			return;
		}

		if ( ! $this->missing->may_create( $space ) ) {
			( $this->stop )( __( 'You are not allowed to create this page.', 'otherguise' ), 403 );

			return;
		}

		try {
			$id = $this->pages->create( $space, $title, '', 'draft' );
		} catch ( NameException $refusal ) {
			if ( NameException::TAKEN === $refusal->error_code() && $refusal->holder() > 0 ) {
				( $this->redirect )( (string) ( $this->permalink )( $refusal->holder() ) );

				return;
			}

			( $this->stop )( $refusal->getMessage(), 400 );

			return;
		}

		( $this->redirect )( ( $this->editor )( $id ) );
	}
}
