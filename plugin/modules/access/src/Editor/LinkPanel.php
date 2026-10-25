<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Loads the Share link panel in the post editor.
 *
 * @package Otherguise
 */

namespace Otherguise\Access\Editor;

use Otherguise\Access\Rest\LinkRoutes;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the scripts of the Share link panel for the posts that can have a link, when the user may edit the post (slice 400).
 */
final class LinkPanel {
	public const PANEL  = 'access-link-panel';
	public const SCRIPT = 'access-link';

	/**
	 * Gives the address of a file of the editor directory.
	 *
	 * @var callable
	 */
	private $url;

	/**
	 * Gives the id of the post being edited.
	 *
	 * @var callable
	 */
	private $post_id;

	/**
	 * Tells whether the user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Gives the types of post that can have a link.
	 *
	 * @var callable
	 */
	private $types;

	/**
	 * Builds the loader.
	 *
	 * @param callable      $url     Address of a file of `editor/link/`.
	 * @param callable|null $post_id Default: the global post.
	 * @param callable|null $can     Default: `current_user_can`.
	 * @param callable|null $types   Default: the public types of post, except the attachments.
	 */
	public function __construct( $url, $post_id = null, $can = null, $types = null ) {
		$this->url     = $url;
		$this->post_id = $post_id ?? array( $this, 'global_post_id' );
		$this->can     = $can ?? 'current_user_can';
		$this->types   = $types ?? static function () {
			return array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		};
	}

	/**
	 * Loads the scripts. Action `enqueue_block_editor_assets`.
	 *
	 * @return void
	 */
	public function enqueue() {
		$post_id = (int) ( $this->post_id )();

		if ( $post_id <= 0 || ! ( $this->can )( 'edit_post', $post_id ) ) {
			return;
		}

		wp_enqueue_script( self::PANEL, ( $this->url )( 'link-panel.js' ), array(), '400.1', true );
		wp_enqueue_script(
			self::SCRIPT,
			( $this->url )( 'link.js' ),
			array( self::PANEL, 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-data', 'wp-i18n', 'wp-api-fetch' ),
			'400.1',
			true
		);
		wp_enqueue_style( self::SCRIPT, ( $this->url )( 'link.css' ), array(), '400.1' );

		wp_add_inline_script(
			self::SCRIPT,
			'window.accessLink = ' . wp_json_encode(
				array(
					'path'      => '/' . LinkRoutes::REST_NAMESPACE . '/posts',
					'postTypes' => array_values( (array) ( $this->types )() ),
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
