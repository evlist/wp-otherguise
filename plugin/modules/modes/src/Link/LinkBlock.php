<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The block that links to the page in another mode.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Link;

use Otherguise\Modes\Mode\ActiveMode;
use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Modes\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Block `modes/link`: a link, around the inner blocks, to the same page in the mode of the attribute `mode`.
 */
final class LinkBlock {
	/**
	 * Name of the block.
	 */
	public const NAME = 'modes/link';

	/**
	 * Modes.
	 *
	 * @var ModeRegistry
	 */
	private $modes;

	/**
	 * Mode of the request.
	 *
	 * @var ActiveMode
	 */
	private $active;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Returns the address of the page the block is on, for a post id or null.
	 *
	 * @var callable
	 */
	private $page_url;

	/**
	 * Returns the attributes of the wrapper (`class="..."`), as a string.
	 *
	 * @var callable
	 */
	private $wrapper;

	/**
	 * Builds the block.
	 *
	 * @param ModeRegistry  $modes    Modes.
	 * @param ActiveMode    $active   Mode of the request.
	 * @param Settings      $settings Settings.
	 * @param callable|null $page_url Receives the post id of the block context (0 when none) and returns an address; defaults to the permalink
	 *                                of the post, or the address of the request.
	 * @param callable|null $wrapper  Returns the attributes of the wrapper; defaults to `get_block_wrapper_attributes()`.
	 */
	public function __construct( ModeRegistry $modes, ActiveMode $active, Settings $settings, $page_url = null, $wrapper = null ) {
		$this->modes    = $modes;
		$this->active   = $active;
		$this->settings = $settings;
		$this->page_url = $page_url ?? array( $this, 'default_page_url' );
		$this->wrapper  = $wrapper ?? 'get_block_wrapper_attributes';
	}

	/**
	 * Registers the block, with its editor script and the list of modes the script offers.
	 *
	 * @param string $directory Directory of `block.json`.
	 * @return void
	 */
	public function register( $directory ) {
		$type = register_block_type( $directory, array( 'render_callback' => array( $this, 'render' ) ) );

		if ( $type && ! empty( $type->editor_script_handles ) ) {
			$choices = array();

			foreach ( $this->modes->all() as $mode ) {
				$choices[] = array(
					'value' => $mode->slug(),
					'label' => $mode->label(),
				);
			}

			wp_add_inline_script( $type->editor_script_handles[0], 'window.modesLink = ' . wp_json_encode( array( 'modes' => $choices ) ) . ';', 'before' );
		}
	}

	/**
	 * Renders the block.
	 *
	 * @param array          $attributes Attributes: `mode`, `label`.
	 * @param string         $content    Rendered inner blocks.
	 * @param \WP_Block|null $block      The block, for its context.
	 * @return string The link, or an empty string when there is nothing to link to.
	 */
	public function render( $attributes, $content = '', $block = null ) {
		$slug = is_array( $attributes ) && isset( $attributes['mode'] ) && is_string( $attributes['mode'] ) ? $attributes['mode'] : '';

		if ( ! $this->settings->is_enabled() || ! $this->modes->has( $slug ) ) {
			return '';
		}

		$target = $this->modes->get( $slug );

		if ( $this->active->mode()->slug() === $target->slug() ) {
			return '';
		}

		$post_id = $block instanceof \WP_Block && isset( $block->context['postId'] ) ? absint( $block->context['postId'] ) : 0;
		$url     = ( $this->page_url )( $post_id );

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$label = isset( $attributes['label'] ) && is_string( $attributes['label'] ) ? trim( $attributes['label'] ) : '';

		if ( '' === trim( (string) $content ) ) {
			$content = esc_html( '' !== $label ? $label : $target->label() );
		}

		$name = '' !== $label ? ' aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"' : '';

		return '<a ' . ( $this->wrapper )() . ' href="' . esc_url( ModeUrl::build( $url, $target, $this->modes ) ) . '" rel="nofollow"' . $name . '>' . $content . '</a>';
	}

	/**
	 * The permalink of the post of the context, or the address of the request.
	 *
	 * @param int $post_id Post id, 0 when none.
	 * @return string
	 */
	public function default_page_url( $post_id ) {
		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );

			return is_string( $permalink ) ? $permalink : '';
		}

		$input = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW );

		return is_string( $input ) ? home_url( $input ) : '';
	}
}
