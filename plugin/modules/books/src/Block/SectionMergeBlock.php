<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The block that stands for the pages of the units of a section.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Block;

use Otherguise\Books\Merge\MergedPrint;

defined( 'ABSPATH' ) || exit;

/**
 * Block `books/section-merge` (slice 235): **the only thing that causes a merge**. It leaves a marker where the pages of the units of the
 * section go, in the modes of its attribute `modes` (every mode when empty), for who may have the section merged (slice 231: an editor, or the
 * PDF job with its token; nobody else, whatever the mode). Elsewhere, and while a merge is going on (a unit that is a section renders the same
 * template), it shows nothing. The list of the units is the block `books/section-list`.
 */
final class SectionMergeBlock {
	/**
	 * Name of the block.
	 */
	public const NAME = 'books/section-merge';

	/**
	 * The merge.
	 *
	 * @var MergedPrint
	 */
	private $merged;

	/**
	 * Returns the slug of the mode of the request.
	 *
	 * @var callable
	 */
	private $active;

	/**
	 * Returns the modes to choose from in the editor.
	 *
	 * @var callable
	 */
	private $choices;

	/**
	 * Builds the block.
	 *
	 * @param MergedPrint   $merged  The merge.
	 * @param callable|null $active  Slug of the mode of the request; defaults to the Modes module.
	 * @param callable|null $choices The modes of the editor, `value` and `label`; defaults to none.
	 */
	public function __construct( MergedPrint $merged, $active = null, $choices = null ) {
		$this->merged  = $merged;
		$this->active  = $active ?? array( ModeFilter::class, 'active' );
		$this->choices = $choices ?? static fn() => array();
	}

	/**
	 * Registers the block, with the modes its editor offers. Action `init`.
	 *
	 * @param string $directory Directory of `block.json`.
	 * @return void
	 */
	public function register( $directory ) {
		$type = register_block_type( $directory, array( 'render_callback' => array( $this, 'render' ) ) );

		if ( $type && ! empty( $type->editor_script_handles ) ) {
			wp_add_inline_script( $type->editor_script_handles[0], 'window.booksModes = ' . wp_json_encode( ( $this->choices )() ) . ';', 'before' );
		}
	}

	/**
	 * Renders the block.
	 *
	 * @param array          $attributes Attributes: `modes`.
	 * @param string         $content    Content (none).
	 * @param \WP_Block|null $block      The block, for its context.
	 * @return string The marker, or an empty string.
	 */
	public function render( $attributes = array(), $content = '', $block = null ) {
		unset( $content );

		if ( $this->merged->is_busy() || ! ModeFilter::allows( $attributes, (string) ( $this->active )() ) ) {
			return '';
		}

		$post_id = $block instanceof \WP_Block && isset( $block->context['postId'] ) ? absint( $block->context['postId'] ) : 0;

		return $this->merged->slot( $post_id );
	}
}
