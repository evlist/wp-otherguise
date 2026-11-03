<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The block that lists the units of a section.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Block;

defined( 'ABSPATH' ) || exit;

/**
 * Block `books/section-list` (slice 235): a wrapper, with an attribute `modes` (every mode when empty), around what the editor puts inside. What
 * it holds by default is the Query Loop of WordPress in its variation "Units of the section" (see `UnitsQuery`), whose settings and style are the
 * theme's. It lists; it does not merge (`books/section-merge`). In a mode that is not one of its `modes` it shows nothing.
 */
final class SectionListBlock {
	/**
	 * Name of the block.
	 */
	public const NAME = 'books/section-list';

	/**
	 * Returns the slug of the mode of the request.
	 *
	 * @var callable
	 */
	private $active;

	/**
	 * Returns the attributes of the wrapper, as a string.
	 *
	 * @var callable
	 */
	private $wrapper;

	/**
	 * Returns the modes to choose from in the editor.
	 *
	 * @var callable
	 */
	private $choices;

	/**
	 * Builds the block.
	 *
	 * @param callable|null $active  Slug of the mode of the request; defaults to the Modes module.
	 * @param callable|null $wrapper Attributes of the wrapper; defaults to `get_block_wrapper_attributes()`.
	 * @param callable|null $choices The modes of the editor, `value` and `label`; defaults to none.
	 */
	public function __construct( $active = null, $wrapper = null, $choices = null ) {
		$this->active  = $active ?? array( ModeFilter::class, 'active' );
		$this->wrapper = $wrapper ?? static fn() => get_block_wrapper_attributes();
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
	 * @param array  $attributes Attributes: `modes`.
	 * @param string $content    The rendered inner blocks.
	 * @return string
	 */
	public function render( $attributes = array(), $content = '' ) {
		if ( ! ModeFilter::allows( $attributes, (string) ( $this->active )() ) || '' === trim( (string) $content ) ) {
			return '';
		}

		return '<div ' . ( $this->wrapper )() . '>' . $content . '</div>';
	}
}
