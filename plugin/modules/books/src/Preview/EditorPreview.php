<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The preview panel of the post editor.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Preview;

use Otherguise\Books\Settings\PageFormat;

defined( 'ABSPATH' ) || exit;

/**
 * Loads, in the post editor, the script of the floating preview panel (slice 219) with the format of the page and the modes it can show,
 * and hides the admin bar in the preview iframe so that it does not change the height of the page. The panel itself is JavaScript.
 */
final class EditorPreview {
	/**
	 * Handle of the script of the geometry (pure functions).
	 */
	public const GEOMETRY = 'books-preview-geometry';

	/**
	 * Handle of the script of the floating panel (plain DOM).
	 */
	public const PANEL = 'books-preview-panel';

	/**
	 * Handle of the script that connects the panel to the editor.
	 */
	public const SCRIPT = 'books-preview';

	/**
	 * Query string argument that tells the page it is shown in the preview iframe.
	 */
	public const ARGUMENT = 'books_preview';

	/**
	 * Format of the page.
	 *
	 * @var PageFormat
	 */
	private $format;

	/**
	 * Returns the modes the panel offers, as a list of arrays `value` and `label`.
	 *
	 * @var callable
	 */
	private $modes;

	/**
	 * Returns the id of the post being edited, 0 when there is none.
	 *
	 * @var callable
	 */
	private $post_id;

	/**
	 * Tells whether the current user may edit a post: receives the capability and the post id.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Returns the address of a file of the editor directory.
	 *
	 * @var callable
	 */
	private $url;

	/**
	 * Reads the query string argument: returns true when the request carries it.
	 *
	 * @var callable
	 */
	private $in_preview;

	/**
	 * Builds the loader.
	 *
	 * @param PageFormat    $format     Format of the page.
	 * @param callable      $modes      Returns the modes (`value`, `label`) and, first, the default one of the panel is chosen from them.
	 * @param callable      $url        Receives a file name and returns its address.
	 * @param callable|null $post_id    Defaults to the id of the global `$post`.
	 * @param callable|null $can        Defaults to `current_user_can`.
	 * @param callable|null $in_preview Defaults to a read of the query string with `filter_input()`.
	 */
	public function __construct( PageFormat $format, $modes, $url, $post_id = null, $can = null, $in_preview = null ) {
		$this->format     = $format;
		$this->modes      = $modes;
		$this->url        = $url;
		$this->post_id    = $post_id ?? array( $this, 'global_post_id' );
		$this->can        = $can ?? 'current_user_can';
		$this->in_preview = $in_preview ?? array( $this, 'query_has_argument' );
	}

	/**
	 * Enqueues the scripts and the style of the panel. Action `enqueue_block_editor_assets`, priority 10.
	 *
	 * Only for the editor of a post that the current user may edit: not for the site editor or the widgets.
	 *
	 * @return void
	 */
	public function enqueue() {
		$post_id = (int) ( $this->post_id )();

		if ( $post_id <= 0 || ! ( $this->can )( 'edit_post', $post_id ) ) {
			return;
		}

		$modes   = array_values( (array) ( $this->modes )() );
		$slugs   = array_column( $modes, 'value' );
		$default = in_array( 'print', $slugs, true ) ? 'print' : ( $slugs[0] ?? '' );
		$frames  = apply_filters( 'books_preview_frame_modes', array( 'print' ) );

		wp_enqueue_script( self::GEOMETRY, ( $this->url )( 'geometry.js' ), array(), '219.1', true );
		wp_enqueue_script( self::PANEL, ( $this->url )( 'panel.js' ), array( self::GEOMETRY ), '219.1', true );
		wp_enqueue_script(
			self::SCRIPT,
			( $this->url )( 'preview.js' ),
			array( self::GEOMETRY, self::PANEL, 'wp-plugins', 'wp-edit-post', 'wp-components', 'wp-element', 'wp-data', 'wp-editor', 'wp-i18n' ),
			'219.1',
			true
		);
		wp_enqueue_style( self::SCRIPT, ( $this->url )( 'preview.css' ), array(), '219.1' );

		wp_add_inline_script(
			self::SCRIPT,
			'window.booksPreview = ' . wp_json_encode(
				array(
					'page'        => $this->format->get(),
					'modes'       => $modes,
					'defaultMode' => $default,
					'frameModes'  => is_array( $frames ) ? array_values( array_filter( $frames, 'is_string' ) ) : array( 'print' ),
					'argument'    => self::ARGUMENT,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Hides the admin bar in the preview iframe. Filter `show_admin_bar`, priority 10 (it only ever says no, so it does not depend on the
	 * order of the other plugins that answer the filter, except one that forces the bar to show with a later priority).
	 *
	 * @param mixed $show Whether the bar is shown.
	 * @return mixed
	 */
	public function admin_bar( $show ) {
		return ( $this->in_preview )() ? false : $show;
	}

	/**
	 * Returns the id of the global post.
	 *
	 * @return int
	 */
	public function global_post_id() {
		$post = $GLOBALS['post'] ?? null;

		return is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
	}

	/**
	 * Tells whether the query string carries the argument of the preview.
	 *
	 * @return bool
	 */
	public function query_has_argument() {
		return is_int( filter_input( INPUT_GET, self::ARGUMENT, FILTER_VALIDATE_INT ) );
	}
}
