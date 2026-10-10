<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The handlers that give stylesheets to the modes.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Admin;

use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Modes\Stylesheet\StylesheetException;
use Otherguise\Modes\Stylesheet\StylesheetFiles;
use Otherguise\Modes\Stylesheet\Stylesheets;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Service\InvalidStatementException;

defined( 'ABSPATH' ) || exit;

/**
 * The `admin_post_` actions that add a stylesheet to a mode (an uploaded file, or one of the Media Library) and take one away. Each one
 * checks the capability (`edit_theme_options` and, for an upload, `upload_files`), then the nonce, before reading anything. What is
 * refused is not an error page: it is a notice.
 */
final class StylesheetActions {
	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Stylesheets of the modes.
	 *
	 * @var Stylesheets
	 */
	private $stylesheets;

	/**
	 * Files of the Media Library.
	 *
	 * @var StylesheetFiles
	 */
	private $files;

	/**
	 * Modes.
	 *
	 * @var ModeRegistry
	 */
	private $modes;

	/**
	 * Builds the handlers.
	 *
	 * @param Environment     $environment Environment.
	 * @param Stylesheets     $stylesheets Stylesheets of the modes.
	 * @param StylesheetFiles $files       Files of the Media Library.
	 * @param ModeRegistry    $modes       Modes.
	 */
	public function __construct( Environment $environment, Stylesheets $stylesheets, StylesheetFiles $files, ModeRegistry $modes ) {
		$this->environment = $environment;
		$this->stylesheets = $stylesheets;
		$this->files       = $files;
		$this->modes       = $modes;
	}

	/**
	 * Adds a stylesheet to a mode: the file of the form, or the one chosen in the Media Library.
	 *
	 * @return void
	 */
	public function add_stylesheet() {
		if ( ! $this->environment->can() || ! $this->environment->can_upload() ) {
			$this->environment->deny();
		}

		$form = $this->environment->form();

		if ( ! $this->environment->verify_nonce( $form['_wpnonce'] ?? '', 'modes_add_stylesheet' ) ) {
			$this->environment->deny();
		}

		$mode = $form['mode'] ?? '';
		$how  = $form['how'] ?? '';

		if ( ! $this->modes->has( $mode ) || ! in_array( $how, array( 'upload', 'existing' ), true ) || ( 'existing' === $how && 1 !== preg_match( '/^[1-9][0-9]{0,18}\z/', $form['attachment'] ?? '' ) ) ) {
			$this->done( 'error', 'invalid_request' );

			return;
		}

		try {
			$id = 'upload' === $how ? $this->files->upload( 'stylesheet_file' ) : (int) $form['attachment'];

			$this->stylesheets->add( new EntityRef( 'mode', $mode ), $id );
		} catch ( StylesheetException $problem ) {
			$this->done( 'error', $problem->error_code() );

			return;
		} catch ( InvalidStatementException $problem ) {
			$this->done( 'error', 'triples_' . $problem->error_code() );

			return;
		}

		$this->done( 'stylesheet_added' );
	}

	/**
	 * Takes a stylesheet away from a mode; the file stays in the Media Library.
	 *
	 * @return void
	 */
	public function remove_stylesheet() {
		if ( ! $this->environment->can() ) {
			$this->environment->deny();
		}

		$form = $this->environment->form();

		if ( ! $this->environment->verify_nonce( $form['_wpnonce'] ?? '', 'modes_remove_stylesheet' ) ) {
			$this->environment->deny();
		}

		$mode       = $form['mode'] ?? '';
		$attachment = $form['attachment'] ?? '';

		if ( ! $this->modes->has( $mode ) || 1 !== preg_match( '/^[1-9][0-9]{0,18}\z/', $attachment ) ) {
			$this->done( 'error', 'invalid_request' );

			return;
		}

		try {
			$this->stylesheets->remove( new EntityRef( 'mode', $mode ), (int) $attachment );
		} catch ( StylesheetException $problem ) {
			$this->done( 'error', $problem->error_code() );

			return;
		} catch ( InvalidStatementException $problem ) {
			$this->done( 'error', 'triples_' . $problem->error_code() );

			return;
		}

		$this->done( 'stylesheet_removed' );
	}

	/**
	 * Redirects to the screen with the result.
	 *
	 * @param string      $notice Result.
	 * @param string|null $code   Code of the refusal.
	 * @return void
	 */
	private function done( $notice, $code = null ) {
		$this->environment->redirect(
			$this->environment->page_url( array( 'modes_notice' => $notice ) + ( null === $code ? array() : array( 'code' => $code ) ) )
		);
	}
}
