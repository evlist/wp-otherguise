<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The block that stands for the pages of the units of a section in print.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Block;

use Otherguise\Books\Merge\MergedPrint;

defined( 'ABSPATH' ) || exit;

/**
 * Block `books/section-units` (slice 235), to put at the root of the print template of a section.
 *
 * In the print mode, for who may have the section merged (slice 231), it leaves a marker where the pages of the units go; the merge puts them
 * there instead of after the whole page. In every other case it shows **nothing**: the list of the units on the web is the Query Loop of
 * WordPress (see `UnitsQuery`). While a merge is going on (a unit that is a section renders the same template) it shows nothing either.
 */
final class SectionUnitsBlock {
	/**
	 * Name of the block.
	 */
	public const NAME = 'books/section-units';

	/**
	 * The merge.
	 *
	 * @var MergedPrint
	 */
	private $merged;

	/**
	 * Builds the block.
	 *
	 * @param MergedPrint $merged The merge.
	 */
	public function __construct( MergedPrint $merged ) {
		$this->merged = $merged;
	}

	/**
	 * Registers the block. Action `init`.
	 *
	 * @param string $directory Directory of `block.json`.
	 * @return void
	 */
	public function register( $directory ) {
		register_block_type( $directory, array( 'render_callback' => array( $this, 'render' ) ) );
	}

	/**
	 * Renders the block.
	 *
	 * @param array          $attributes Attributes (none).
	 * @param string         $content    Content (none).
	 * @param \WP_Block|null $block      The block, for its context.
	 * @return string The marker, or an empty string.
	 */
	public function render( $attributes = array(), $content = '', $block = null ) {
		unset( $attributes, $content );

		if ( $this->merged->is_busy() ) {
			return '';
		}

		$post_id = $block instanceof \WP_Block && isset( $block->context['postId'] ) ? absint( $block->context['postId'] ) : 0;

		return $this->merged->slot( $post_id );
	}
}
