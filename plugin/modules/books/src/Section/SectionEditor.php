<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Loads the Section panel in the post editor.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Section;

use Otherguise\Books\Rest\SectionRoutes;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the scripts of the Book section panel for the post types that can hold sections (slice 223), when the user may edit the post.
 */
final class SectionEditor {
	public const MODEL  = 'books-section-model';
	public const DIALOG = 'books-section-dialog';
	public const WINDOW = 'books-section-window';
	public const UI     = 'books-section-ui';
	public const SCRIPT = 'books-section';

	/**
	 * Gives the id of the post being edited.
	 *
	 * @var callable
	 */
	private $post_id;

	/**
	 * Gives the type of a post.
	 *
	 * @var callable
	 */
	private $post_type;

	/**
	 * Tells whether the user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Gives the address of a file of the editor directory.
	 *
	 * @var callable
	 */
	private $url;

	/**
	 * Gives the post types that can hold sections.
	 *
	 * @var callable
	 */
	private $types;

	/**
	 * Builds the loader.
	 *
	 * @param callable      $url       Address of a file of `editor/section/`.
	 * @param callable      $types     Post types that can hold sections.
	 * @param callable|null $post_id   Default: the global post.
	 * @param callable|null $post_type Default: `get_post_type`.
	 * @param callable|null $can       Default: `current_user_can`.
	 */
	public function __construct( $url, $types, $post_id = null, $post_type = null, $can = null ) {
		$this->url       = $url;
		$this->types     = $types;
		$this->post_id   = $post_id ?? array( $this, 'global_post_id' );
		$this->post_type = $post_type ?? 'get_post_type';
		$this->can       = $can ?? 'current_user_can';
	}

	/**
	 * Loads the scripts. Action `enqueue_block_editor_assets`.
	 *
	 * @return void
	 */
	public function enqueue() {
		$post_id = (int) ( $this->post_id )();
		$types   = (array) ( $this->types )();

		if ( $post_id <= 0 || ! in_array( ( $this->post_type )( $post_id ), $types, true ) || ! ( $this->can )( 'edit_post', $post_id ) ) {
			return;
		}

		wp_enqueue_script( self::MODEL, ( $this->url )( 'section-model.js' ), array(), '224.1', true );
		wp_enqueue_script( self::DIALOG, ( $this->url )( 'section-dialog.js' ), array(), '224.1', true );
		wp_enqueue_script( self::WINDOW, ( $this->url )( 'section-window.js' ), array(), '224.1', true );
		wp_enqueue_script( self::UI, ( $this->url )( 'section-ui.js' ), array(), '224.1', true );
		wp_enqueue_script(
			self::SCRIPT,
			( $this->url )( 'section.js' ),
			array( self::MODEL, self::DIALOG, self::WINDOW, self::UI, 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-data', 'wp-i18n', 'wp-api-fetch' ),
			'224.1',
			true
		);
		wp_enqueue_style( self::SCRIPT, ( $this->url )( 'section.css' ), array( 'dashicons' ), '224.1' );

		wp_add_inline_script(
			self::SCRIPT,
			'window.booksSection = ' . wp_json_encode(
				array(
					'path'      => '/' . SectionRoutes::REST_NAMESPACE . '/sections',
					'postTypes' => array_values( $types ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The id of the post of the global query.
	 *
	 * @return int
	 */
	public function global_post_id() {
		$post = $GLOBALS['post'] ?? null;

		return is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
	}
}
