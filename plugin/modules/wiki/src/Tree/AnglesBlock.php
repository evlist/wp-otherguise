<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The block of the other angles.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * The block `wiki/other-angles` (slice 507): "Other angles: /Opinion/Paris · /Biography/Paris", for the pages the reader may see; nothing when
 * there are none. Server-rendered, with classes (`wiki-angles`, `wiki-angle`) and no style.
 */
final class AnglesBlock {
	/**
	 * The angles.
	 *
	 * @var Angles
	 */
	private $angles;

	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * The router, for addresses.
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
	 * @param Angles        $angles  The angles.
	 * @param Keys          $keys    The index of the tree.
	 * @param Router        $router  The router.
	 * @param callable|null $current Default `get_the_ID`.
	 * @param callable|null $visible Receives a page id; default: published, or the user may read it.
	 */
	public function __construct( Angles $angles, Keys $keys, Router $router, $current = null, $visible = null ) {
		$this->angles  = $angles;
		$this->keys    = $keys;
		$this->router  = $router;
		$this->current = $current ?? 'get_the_ID';
		$this->visible = $visible ?? static function ( $id ) {
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
			'wiki/other-angles',
			array(
				'api_version'     => 3,
				'title'           => __( 'Wiki: other angles', 'otherguise' ),
				'description'     => __( 'The pages of the same title elsewhere in the hierarchy, and the pages declared as the same subject.', 'otherguise' ),
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
		$items = array();

		foreach ( $this->angles->of( (int) ( $this->current )() ) as $page ) {
			$titles = $this->keys->path_of( $page );

			if ( null !== $titles && (bool) ( $this->visible )( $page ) ) {
				$items[] = '<li class="wiki-angle"><a href="' . esc_url( $this->router->canonical( $titles ) ) . '">' . esc_html( '/' . implode( '/', $titles ) ) . '</a></li>';
			}
		}

		if ( array() === $items ) {
			return '';
		}

		return '<div class="wiki-angles"><p>' . esc_html__( 'Other angles:', 'otherguise' ) . '</p><ul>' . implode( '', $items ) . '</ul></div>';
	}
}
