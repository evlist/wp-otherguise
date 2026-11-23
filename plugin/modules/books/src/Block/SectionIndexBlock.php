<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The block that stands for an index of the section.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Block;

use Otherguise\Books\Merge\MergedPrint;

defined( 'ABSPATH' ) || exit;

/**
 * Block `books/section-index` (slice 611): **an index of the posts of the section**, made by the module of the index (the filter `index_build`). It
 * leaves a marker where the index goes, and the merge replaces it when the units are known, as it does for the table of contents; it needs the block
 * that merges (`books/section-merge`) on the same page and the same access. In the print modes the locators are page numbers (placeholders until a
 * second pass of the job knows them); elsewhere they are links to the posts. Several blocks give several indexes of one book.
 *
 * Attributes: `include` and `exclude` (the tokens of the selection: `kind:place`, `source:image`, `visibility:print`; by default every kind, source
 * and visibility), `context` (the broader topic in parentheses), `title`, `attribution` (`auto`, `yes`, `no`: the one notice of the index) and
 * `modes` (the modes in which the block is shown, every mode when empty).
 */
final class SectionIndexBlock {
	/**
	 * Name of the block.
	 */
	public const NAME = 'books/section-index';

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
	 * Receives the slug of a mode; tells whether the locators are page numbers in it (a print mode).
	 *
	 * @var callable
	 */
	private $numbered;

	/**
	 * Builds the block.
	 *
	 * @param MergedPrint   $merged   The merge.
	 * @param callable|null $active   Slug of the mode of the request; defaults to the Modes module.
	 * @param callable|null $choices  The modes of the editor, `value` and `label`; defaults to none.
	 * @param callable|null $numbered Tells whether a mode is a print mode; defaults to the filter `books_preview_frame_modes`.
	 */
	public function __construct( MergedPrint $merged, $active = null, $choices = null, $numbered = null ) {
		$this->merged   = $merged;
		$this->active   = $active ?? array( ModeFilter::class, 'active' );
		$this->choices  = $choices ?? static fn() => array();
		$this->numbered = $numbered ?? static function ( $slug ) {
			$modes = apply_filters( 'books_preview_frame_modes', array( 'print' ) );

			return is_array( $modes ) && in_array( $slug, $modes, true );
		};
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
	 * @param array          $attributes Attributes.
	 * @param string         $content    Content (none).
	 * @param \WP_Block|null $block      The block, for its context.
	 * @return string The marker, or an empty string.
	 */
	public function render( $attributes = array(), $content = '', $block = null ) {
		unset( $content );

		$mode       = (string) ( $this->active )();
		$attributes = is_array( $attributes ) ? $attributes : array();

		if ( ! ModeFilter::allows( $attributes, $mode ) ) {
			return '';
		}

		$attribution = (string) ( $attributes['attribution'] ?? 'auto' );
		$options     = array(
			'include'     => (string) ( $attributes['include'] ?? '' ),
			'exclude'     => (string) ( $attributes['exclude'] ?? '' ),
			'rollup'      => (string) ( $attributes['rollup'] ?? '' ),
			'context'     => ! array_key_exists( 'context', $attributes ) || (bool) $attributes['context'],
			'title'       => (string) ( $attributes['title'] ?? '' ),
			'attribution' => in_array( $attribution, array( 'auto', 'yes', 'no' ), true ) ? $attribution : 'auto',
		);
		$post_id     = $block instanceof \WP_Block && isset( $block->context['postId'] ) ? absint( $block->context['postId'] ) : 0;

		return $this->merged->index_slot( $post_id, $options, (bool) ( $this->numbered )( $mode ) );
	}
}
