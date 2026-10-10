<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Gives a new post the template chosen for its type.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Template;

use Otherguise\Modes\Settings\DefaultTemplates;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked to `wp_insert_post`. The editor creates an auto-draft when a new post is opened: that is the only post that gets the template,
 * so that a post created any other way (WP-CLI, the REST API, an import) and every update are left alone, and the author's later choice,
 * "Default template" included, is kept.
 */
final class NewPostTemplate {
	/**
	 * Template of the post meta.
	 */
	public const META = '_wp_page_template';

	/**
	 * Settings.
	 *
	 * @var DefaultTemplates
	 */
	private $templates;

	/**
	 * Reads a post meta: `get_post_meta`.
	 *
	 * @var callable
	 */
	private $get_meta;

	/**
	 * Writes a post meta: `update_post_meta`.
	 *
	 * @var callable
	 */
	private $set_meta;

	/**
	 * Builds the hook.
	 *
	 * @param DefaultTemplates $templates Settings.
	 * @param callable|null    $get_meta  Defaults to WordPress `get_post_meta`.
	 * @param callable|null    $set_meta  Defaults to WordPress `update_post_meta`.
	 */
	public function __construct( DefaultTemplates $templates, $get_meta = null, $set_meta = null ) {
		$this->templates = $templates;
		$this->get_meta  = $get_meta ?? 'get_post_meta';
		$this->set_meta  = $set_meta ?? 'update_post_meta';
	}

	/**
	 * Gives the template to an auto-draft of a type that has one. Action `wp_insert_post`.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Whether it is an update.
	 * @return void
	 */
	public function apply( $post_id, $post, $update ) {
		if ( $update || ! is_object( $post ) || 'auto-draft' !== ( $post->post_status ?? '' ) ) {
			return;
		}

		$slug = $this->templates->for_type( (string) ( $post->post_type ?? '' ) );

		if ( '' === $slug || '' !== (string) ( $this->get_meta )( $post_id, self::META, true ) ) {
			return;
		}

		// The template may have gone since the setting was saved: the post then keeps the default template.
		if ( $this->templates->exists( $slug ) ) {
			( $this->set_meta )( $post_id, self::META, $slug );
		}
	}
}
