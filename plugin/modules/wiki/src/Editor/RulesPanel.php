<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Loads the panel of the rules of a branch in the page editor.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Editor;

use Otherguise\Wiki\Rest\RulesRoutes;
use Otherguise\Wiki\Tree\Nodes;
use Otherguise\Wiki\Tree\Rules;
use Otherguise\Wiki\Tree\RulesMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the script of the panel **Branch rules** (slice 500) for a page that the user may edit; the inputs are read only for a user who
 * may not change the rules.
 */
final class RulesPanel {
	public const SCRIPT = 'wiki-rules';

	/**
	 * Gives the address of a file of the editor directory.
	 *
	 * @var callable
	 */
	private $url;

	/**
	 * Gives the id and the type of the post being edited, as an array (`id`, `type`).
	 *
	 * @var callable
	 */
	private $post;

	/**
	 * Tells whether the user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Builds the loader.
	 *
	 * @param callable      $url  Address of a file of `editor/rules/`.
	 * @param callable|null $post Default: the global post.
	 * @param callable|null $can  Default: `current_user_can`.
	 */
	public function __construct( $url, $post = null, $can = null ) {
		$this->url  = $url;
		$this->post = $post ?? static function () {
			$post = $GLOBALS['post'] ?? null;

			return is_object( $post ) ? array(
				'id'   => (int) $post->ID,
				'type' => (string) $post->post_type,
			) : array(
				'id'   => 0,
				'type' => '',
			);
		};
		$this->can  = $can ?? 'current_user_can';
	}

	/**
	 * Loads the script. Action `enqueue_block_editor_assets`.
	 *
	 * @return void
	 */
	public function enqueue() {
		$post = ( $this->post )();

		if ( Nodes::TYPE !== $post['type'] || $post['id'] <= 0 || ! ( $this->can )( 'edit_post', $post['id'] ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT,
			( $this->url )( 'rules.js' ),
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
			'1',
			true
		);
		wp_add_inline_script(
			self::SCRIPT,
			'window.wikiRules = ' . wp_json_encode(
				array(
					'postType' => Nodes::TYPE,
					'path'     => '/' . RulesRoutes::REST_NAMESPACE . '/rules',
					'keys'     => Rules::KEYS,
					'canEdit'  => (bool) ( $this->can )( RulesMeta::capability() ),
				)
			) . ';',
			'before'
		);
	}
}
