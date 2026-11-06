<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Loads the Space panel in the editor of the wiki pages.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Editor;

use Otherguise\Wiki\Page\PageStore;
use Otherguise\Wiki\Space\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the script of the Space panel for a wiki page that the user may edit (slice 502).
 */
final class SpacePanel {
	public const SCRIPT = 'wiki-space';

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
	 * @param callable      $url  Address of a file of `editor/space/`.
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

		if ( PageStore::TYPE !== $post['type'] || $post['id'] <= 0 || ! ( $this->can )( 'edit_post', $post['id'] ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT,
			( $this->url )( 'space.js' ),
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-components', 'wp-i18n' ),
			'1',
			true
		);
		wp_add_inline_script(
			self::SCRIPT,
			'window.wikiSpace = ' . wp_json_encode(
				array(
					'postType' => PageStore::TYPE,
					'spaces'   => array_map(
						static function ( $space ) {
							return array(
								'name'  => $space['name'],
								'label' => $space['label'],
							);
						},
						Spaces::all()
					),
				)
			) . ';',
			'before'
		);
	}
}
