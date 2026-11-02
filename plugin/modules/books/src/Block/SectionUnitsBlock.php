<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The block that stands for the units of a section.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Block;

use Otherguise\Books\Merge\MergedPrint;
use Otherguise\Books\Section\Sections;

defined( 'ABSPATH' ) || exit;

/**
 * Block `books/section-units` (slice 235), to put in the template of a section.
 *
 * - In the **print** mode, for who may have the section merged (slice 231), it leaves the marker where the pages of the units go; the merge
 *   puts them there instead of after the whole page. Put it at the root of the template: a group in a column would squeeze the units.
 * - Everywhere else (the web mode, a visitor, the editor) it shows the **list of the units** of the section, in order, published and without
 *   a password: for each one, its inner blocks (the post of the unit is their context, as in a post template), or its title with a link
 *   when there is none.
 * - While a merge is going on (a unit that is a section renders this same template) it shows nothing: the merge follows the sub-sections.
 */
final class SectionUnitsBlock {
	/**
	 * Name of the block.
	 */
	public const NAME = 'books/section-units';

	/**
	 * The sections.
	 *
	 * @var Sections
	 */
	private $sections;

	/**
	 * The merge.
	 *
	 * @var MergedPrint
	 */
	private $merged;

	/**
	 * Receives a post id; returns `status` and `password` of the post, or null when it does not exist.
	 *
	 * @var callable
	 */
	private $post_of;

	/**
	 * Receives a post id and the block; returns the HTML of the item of that unit.
	 *
	 * @var callable
	 */
	private $item;

	/**
	 * Returns the attributes of the wrapper, as a string.
	 *
	 * @var callable
	 */
	private $wrapper;

	/**
	 * Returns the id of the section the page shows.
	 *
	 * @var callable
	 */
	private $queried;

	/**
	 * Builds the block.
	 *
	 * @param Sections      $sections The sections.
	 * @param MergedPrint   $merged   The merge.
	 * @param callable|null $post_of  Reads a post; defaults to `get_post()`.
	 * @param callable|null $item     Renders an item; defaults to the inner blocks, else the linked title.
	 * @param callable|null $wrapper  Attributes of the wrapper; defaults to `get_block_wrapper_attributes()`.
	 * @param callable|null $queried  Id of the post the page shows; defaults to `get_queried_object_id()`.
	 */
	public function __construct( Sections $sections, MergedPrint $merged, $post_of = null, $item = null, $wrapper = null, $queried = null ) {
		$this->sections = $sections;
		$this->merged   = $merged;
		$this->post_of  = $post_of ?? static function ( $id ) {
			$post = get_post( (int) $id );

			return $post instanceof \WP_Post ? array(
				'status'   => (string) $post->post_status,
				'password' => (string) $post->post_password,
			) : null;
		};
		$this->item     = $item ?? array( $this, 'default_item' );
		$this->wrapper  = $wrapper ?? static fn() => get_block_wrapper_attributes();
		$this->queried  = $queried ?? static fn() => (int) get_queried_object_id();
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
	 * @param array          $attributes Attributes: `limit` (0: all the units).
	 * @param string         $content    Rendered inner blocks (not used: they are rendered once for each unit).
	 * @param \WP_Block|null $block      The block, for its context.
	 * @return string The marker, the list, or an empty string.
	 */
	public function render( $attributes, $content = '', $block = null ) {
		unset( $content );

		if ( $this->merged->is_busy() ) {
			return '';
		}

		$section = $block instanceof \WP_Block && isset( $block->context['postId'] ) ? absint( $block->context['postId'] ) : 0;
		$marker  = $this->merged->slot( $section );

		if ( '' !== $marker ) {
			return $marker;
		}

		$section = $section > 0 ? $section : (int) ( $this->queried )();

		return $section > 0 ? $this->list_of( $section, is_array( $attributes ) ? $attributes : array(), $block ) : '';
	}

	/**
	 * The list of the published units of a section that have no password.
	 *
	 * @param int            $section    Id of the section.
	 * @param array          $attributes Attributes of the block.
	 * @param \WP_Block|null $block      The block.
	 * @return string
	 */
	private function list_of( $section, array $attributes, $block ) {
		$limit = isset( $attributes['limit'] ) ? max( 0, (int) $attributes['limit'] ) : 0;
		$items = '';
		$count = 0;

		foreach ( $this->sections->order( $section ) as $id ) {
			$post = ( $this->post_of )( (int) $id );

			if ( null === $post || 'publish' !== $post['status'] || '' !== $post['password'] ) {
				continue;
			}

			if ( $limit > 0 && $count >= $limit ) {
				break;
			}

			++$count;
			$items .= '<li class="books-section-units__item">' . ( $this->item )( (int) $id, $block ) . '</li>';
		}

		return '' === $items ? '' : '<ul ' . ( $this->wrapper )() . '>' . $items . '</ul>';
	}

	/**
	 * The item of a unit: the inner blocks with the unit as their post, else its title with a link.
	 *
	 * @param int            $id    Id of the unit.
	 * @param \WP_Block|null $block The block.
	 * @return string
	 */
	public function default_item( $id, $block ) {
		if ( $block instanceof \WP_Block && ! empty( $block->parsed_block['innerBlocks'] ) ) {
			$instance              = $block->parsed_block;
			$instance['blockName'] = 'core/null';
			$html                  = '';

			// As the post template does: the blocks that read "the current post" (the title) find the unit, not the section.
			$query = new \WP_Query(
				array(
					'p'                   => $id,
					'post_type'           => 'any',
					'posts_per_page'      => 1,
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
				)
			);

			while ( $query->have_posts() ) {
				$query->the_post();

				$inner = new \WP_Block(
					$instance,
					array(
						'postType' => get_post_type( $id ),
						'postId'   => $id,
					)
				);
				$html  = $inner->render( array( 'dynamic' => false ) );
			}

			wp_reset_postdata();

			return $html;
		}

		return '<a href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
	}
}
