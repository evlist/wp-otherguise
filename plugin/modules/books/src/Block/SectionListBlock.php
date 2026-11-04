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

use Otherguise\Books\Settings\ListItems;

defined( 'ABSPATH' ) || exit;

/**
 * Block `books/section-list` (slice 235): the list of the units of the section, with links (a list on the web, a table of contents in print). It has
 * no inner blocks: the server builds the Query Loop of WordPress in its variation "Units of the section" (see `UnitsQuery`) each time, and puts in
 * its post template **the template part chosen** in the attribute `item` (a part of the theme that the screen of the settings allows, see
 * `ListItems`; a part can have a variant in a mode, print for instance), or, without a choice, the title with a link and the excerpt. The attribute
 * `modes` (every mode when empty) says in which modes the list is shown.
 */
final class SectionListBlock {
	/**
	 * Name of the block.
	 */
	public const NAME = 'books/section-list';

	/**
	 * The units of one page of the list, when the page size is not given: the theme decides what a page of a loop holds.
	 */
	public const PER_PAGE = 100;

	/**
	 * The identifiers given to the loops of the page (the pagination of WordPress tells them apart).
	 *
	 * @var int
	 */
	private static $loops = 0;

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
	 * The template parts that may be the item of the list.
	 *
	 * @var ListItems
	 */
	private $items;

	/**
	 * Renders blocks given as a string: receives the markup and the context.
	 *
	 * @var callable
	 */
	private $renderer;

	/**
	 * Builds the block.
	 *
	 * @param callable|null  $active   Slug of the mode of the request; defaults to the Modes module.
	 * @param callable|null  $wrapper  Attributes of the wrapper; defaults to `get_block_wrapper_attributes()`.
	 * @param callable|null  $choices  The modes of the editor, `value` and `label`; defaults to none.
	 * @param ListItems|null $items    The template parts allowed for the items; defaults to those of the theme and of the settings.
	 * @param callable|null  $renderer Renders the blocks of a string with a context; defaults to `WP_Block`.
	 */
	public function __construct( $active = null, $wrapper = null, $choices = null, $items = null, $renderer = null ) {
		$this->active   = $active ?? array( ModeFilter::class, 'active' );
		$this->wrapper  = $wrapper ?? static fn() => get_block_wrapper_attributes();
		$this->choices  = $choices ?? static fn() => array();
		$this->items    = $items ?? new ListItems();
		$this->renderer = $renderer ?? static function ( $markup, $context ) {
			$html = '';

			foreach ( parse_blocks( $markup ) as $parsed ) {
				if ( ! empty( $parsed['blockName'] ) ) {
					$html .= ( new \WP_Block( $parsed, $context ) )->render();
				}
			}

			return $html;
		};
	}

	/**
	 * Registers the block, with the modes and the items its editor offers. Action `init`.
	 *
	 * @param string $directory Directory of `block.json`.
	 * @return void
	 */
	public function register( $directory ) {
		$type = register_block_type( $directory, array( 'render_callback' => array( $this, 'render' ) ) );

		if ( $type && ! empty( $type->editor_script_handles ) ) {
			wp_add_inline_script(
				$type->editor_script_handles[0],
				'window.booksModes = ' . wp_json_encode( ( $this->choices )() ) . '; window.booksItems = ' . wp_json_encode( $this->items->choices() ) . ';',
				'before'
			);
		}
	}

	/**
	 * The blocks of the list, as the markup that WordPress parses: a Query Loop that lists the units, with the part chosen or the title and the
	 * excerpt in its post template.
	 *
	 * @param string $item Slug of the template part, an empty string for the default.
	 * @return string
	 */
	public static function markup( $item ) {
		++self::$loops;

		$inner = '' === $item
			? '<!-- wp:post-title {"level":3,"isLink":true} /--><!-- wp:post-excerpt /-->'
			: '<!-- wp:template-part ' . wp_json_encode( array( 'slug' => $item ) ) . ' /-->';
		$query = array(
			'queryId'   => self::$loops,
			'query'     => array(
				'perPage'  => (int) apply_filters( 'books_section_list_per_page', self::PER_PAGE ),
				'pages'    => 0,
				'offset'   => 0,
				'postType' => 'post',
				'order'    => 'asc',
				'orderBy'  => 'date',
				'author'   => '',
				'search'   => '',
				'exclude'  => array(),
				'sticky'   => '',
				'inherit'  => false,
				UnitsQuery::KEY => true,
			),
			'namespace' => 'books-section-units',
		);

		return '<!-- wp:query ' . wp_json_encode( $query ) . ' --><div class="wp-block-query"><!-- wp:post-template -->' . $inner . '<!-- /wp:post-template --></div><!-- /wp:query -->';
	}

	/**
	 * Renders the block.
	 *
	 * @param array          $attributes Attributes: `modes`, `item`.
	 * @param string         $content    Content (none).
	 * @param \WP_Block|null $block      The block, for its context.
	 * @return string
	 */
	public function render( $attributes = array(), $content = '', $block = null ) {
		unset( $content );

		if ( ! ModeFilter::allows( $attributes, (string) ( $this->active )() ) ) {
			return '';
		}

		$item    = is_array( $attributes ) && isset( $attributes['item'] ) && is_string( $attributes['item'] ) ? $attributes['item'] : '';
		$item    = $this->items->usable( $item ) ? $item : '';
		$context = $block instanceof \WP_Block ? $block->context : array();
		$html    = (string) ( $this->renderer )( self::markup( $item ), $context );

		return '' === trim( $html ) ? '' : '<div ' . ( $this->wrapper )() . '>' . $html . '</div>';
	}
}
