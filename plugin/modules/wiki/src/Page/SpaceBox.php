<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The box of the space, on the classic edit screen.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Page;

use Otherguise\Wiki\Space\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * The classic screen (slice 502): a box to choose the space of a new page, shown as text once it is set. In the block editor the panel
 * does it (`__back_compat_meta_box` hides this box there).
 */
final class SpaceBox {
	public const ACTION = 'wiki_space_box';
	public const NONCE  = 'wiki_space_nonce';

	/**
	 * Tells whether the user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Checks a nonce.
	 *
	 * @var callable
	 */
	private $verify;

	/**
	 * Gives the value posted for a field, or null.
	 *
	 * @var callable
	 */
	private $posted;

	/**
	 * Builds the box.
	 *
	 * @param callable|null $can    Default `current_user_can`.
	 * @param callable|null $verify Receives the nonce and the action; default `wp_verify_nonce`.
	 * @param callable|null $posted Receives a field name and returns the value posted or null; default reads the request.
	 */
	public function __construct( $can = null, $verify = null, $posted = null ) {
		$this->can    = $can ?? 'current_user_can';
		$this->verify = $verify ?? 'wp_verify_nonce';
		$this->posted = $posted ?? static function ( $name ) {
			$value = filter_input( INPUT_POST, $name );

			return is_string( $value ) ? $value : null;
		};
	}

	/**
	 * Adds the box. Action `add_meta_boxes_wiki_page`.
	 *
	 * @return void
	 */
	public function register() {
		add_meta_box(
			'wiki-space',
			__( 'Space', 'otherguise' ),
			array( $this, 'render' ),
			PageStore::TYPE,
			'side',
			'high',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Prints the box.
	 *
	 * @param \WP_Post $post The page.
	 * @return void
	 */
	public function render( $post ) {
		$current = (string) get_post_meta( (int) $post->ID, PageStore::SPACE_META, true );

		if ( '' !== $current ) {
			echo '<p><strong>' . esc_html( $current ) . '</strong></p><p class="description">' . esc_html__( 'A page stays in its space: moving it changes its address.', 'otherguise' ) . '</p>';

			return;
		}

		wp_nonce_field( self::ACTION, self::NONCE );
		echo '<p><label class="screen-reader-text" for="wiki-space-select">' . esc_html__( 'Space of the page', 'otherguise' ) . '</label>';
		echo '<select name="' . esc_attr( PageStore::SPACE_META ) . '" id="wiki-space-select"><option value="">' . esc_html__( 'Choose a space', 'otherguise' ) . '</option>';

		foreach ( Spaces::all() as $space ) {
			echo '<option value="' . esc_attr( $space['name'] ) . '">' . esc_html( $space['label'] ) . '</option>';
		}

		echo '</select></p><p class="description">' . esc_html__( 'Chosen once, before the page is published.', 'otherguise' ) . '</p>';
	}

	/**
	 * Saves the space of a page that has none. Action `save_post_wiki_page`. The nonce and the capability are checked; the value is cleaned
	 * by the meta itself, and a space that is set cannot be changed.
	 *
	 * @param int $post_id The page.
	 * @return void
	 */
	public function save( $post_id ) {
		$nonce = ( $this->posted )( self::NONCE );
		$space = ( $this->posted )( PageStore::SPACE_META );

		if ( null === $nonce || null === $space || ! ( $this->verify )( $nonce, self::ACTION ) || ! ( $this->can )( 'edit_post', (int) $post_id ) ) {
			return;
		}

		if ( null !== Spaces::canonical( $space ) ) {
			update_post_meta( (int) $post_id, PageStore::SPACE_META, $space );
		}
	}
}
