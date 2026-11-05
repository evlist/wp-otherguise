<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The block that stands for the table of contents of a section.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Block;

use Otherguise\Books\Merge\MergedPrint;

defined( 'ABSPATH' ) || exit;

/**
 * Block `books/section-toc` (slice 228): the table of contents of the section, made from the headings of its page and of its units. It leaves a marker
 * where the table goes; the merge replaces it when it has the headings (the units are rendered after the template). It needs the block that merges
 * (`books/section-merge`) on the same page, and the same access: an editor, or the PDF job with its token. In the print modes the entries have the
 * number of their page (a placeholder until a second pass of the job knows them); elsewhere they are links. The attribute `levels` limits the number of
 * levels listed (0: all), the attribute `modes` the modes in which the table is shown (every mode when empty).
 */
final class SectionTocBlock {
	/**
	 * Name of the block.
	 */
	public const NAME = 'books/section-toc';

	/**
	 * Levels listed by default.
	 */
	public const LEVELS = 2;

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
	 * Receives the slug of a mode; tells whether the entries are numbered in it (a print mode).
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
	 * @param array          $attributes Attributes: `levels`, `modes`.
	 * @param string         $content    Content (none).
	 * @param \WP_Block|null $block      The block, for its context.
	 * @return string The marker, or an empty string.
	 */
	public function render( $attributes = array(), $content = '', $block = null ) {
		unset( $content );

		$mode = (string) ( $this->active )();

		if ( ! ModeFilter::allows( $attributes, $mode ) ) {
			return '';
		}

		$levels  = is_array( $attributes ) && isset( $attributes['levels'] ) ? (int) $attributes['levels'] : self::LEVELS;
		$post_id = $block instanceof \WP_Block && isset( $block->context['postId'] ) ? absint( $block->context['postId'] ) : 0;

		return $this->merged->toc_slot( $post_id, $levels, (bool) ( $this->numbered )( $mode ) );
	}
}
