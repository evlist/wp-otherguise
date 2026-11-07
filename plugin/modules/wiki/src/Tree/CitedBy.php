<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The block that lists the pages that cite the current page.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * The block `wiki/cited-by` (slice 506): the pages that have a link to this one, by address, only those the reader may see; nothing when there
 * is none. Server-rendered, with classes (`wiki-cited-by`) and no style.
 */
final class CitedBy {
	/**
	 * The index of the links.
	 *
	 * @var Citations
	 */
	private $citations;

	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * The router, for the address of a chain of titles.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Returns the id of the page being shown.
	 *
	 * @var callable
	 */
	private $current;

	/**
	 * Tells whether the current user may see a page.
	 *
	 * @var callable
	 */
	private $visible;

	/**
	 * Builds the block.
	 *
	 * @param Citations     $citations The index of the links.
	 * @param Keys          $keys      The index of the tree.
	 * @param Router        $router    The router.
	 * @param callable|null $current   Default `get_the_ID`.
	 * @param callable|null $visible   Receives a page id; default: published, or the user may read it.
	 */
	public function __construct( Citations $citations, Keys $keys, Router $router, $current = null, $visible = null ) {
		$this->citations = $citations;
		$this->keys      = $keys;
		$this->router    = $router;
		$this->current   = $current ?? 'get_the_ID';
		$this->visible   = $visible ?? static function ( $id ) {
			return 'publish' === get_post_status( (int) $id ) || current_user_can( 'read_post', (int) $id );
		};
	}

	/**
	 * Registers the block. Action `init`.
	 *
	 * @return void
	 */
	public function register_block() {
		register_block_type(
			'wiki/cited-by',
			array(
				'api_version'     => 3,
				'title'           => __( 'Wiki: cited by', 'otherguise' ),
				'description'     => __( 'The pages that have a link to this page.', 'otherguise' ),
				'category'        => 'widgets',
				'supports'        => array(
					'html'         => false,
					'autoRegister' => true,
				),
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * The markup of the block.
	 *
	 * @return string
	 */
	public function render() {
		$id    = (int) ( $this->current )();
		$items = '';

		foreach ( $this->citations->cited_by( $id ) as $from ) {
			$titles = $this->keys->path_of( $from );

			if ( null !== $titles && (bool) ( $this->visible )( $from ) ) {
				$items .= '<li><a href="' . esc_url( $this->router->canonical( $titles ) ) . '">' . esc_html( '/' . implode( '/', $titles ) ) . '</a></li>';
			}
		}

		if ( '' === $items ) {
			return '';
		}

		return '<div class="wiki-cited-by"><p>' . esc_html__( 'Cited by:', 'otherguise' ) . '</p><ul>' . $items . '</ul></div>';
	}
}
