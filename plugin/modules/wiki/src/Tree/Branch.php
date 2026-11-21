<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the rules of a branch do to the page that is shown.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the rules of a branch (slice 500) when a page is shown: the **template** (put first in the hierarchy of the templates of a page,
 * unless the page chose its own), the **classes** (added to the body) and the **status line** (given to the block `wiki/branch-status`). The
 * look stays the theme's: the module gives a class and a text, no style.
 */
final class Branch {
	/**
	 * The rules.
	 *
	 * @var Rules
	 */
	private $rules;

	/**
	 * Returns the id of the page being shown, or 0 when it is not a page.
	 *
	 * @var callable
	 */
	private $current;

	/**
	 * Returns the template that a page chose for itself.
	 *
	 * @var callable
	 */
	private $own_template;

	/**
	 * Builds the hooks.
	 *
	 * @param Rules         $rules        The rules.
	 * @param callable|null $current      Default: the queried page.
	 * @param callable|null $own_template Receives a page id; default the meta `_wp_page_template`.
	 */
	public function __construct( Rules $rules, $current = null, $own_template = null ) {
		$this->rules        = $rules;
		$this->current      = $current ?? static function () {
			return is_page() ? (int) get_queried_object_id() : 0;
		};
		$this->own_template = $own_template ?? static function ( $id ) {
			return (string) get_post_meta( (int) $id, '_wp_page_template', true );
		};
	}

	/**
	 * Adds the classes of the rules to the body. Filter `body_class`.
	 *
	 * @param mixed $classes The classes.
	 * @return mixed
	 */
	public function body_class( $classes ) {
		$id = (int) ( $this->current )();

		if ( $id <= 0 || ! is_array( $classes ) ) {
			return $classes;
		}

		foreach ( array_filter( explode( ' ', $this->rules->value( $id, 'class' ) ) ) as $class ) {
			$classes[] = $class;
		}

		return $classes;
	}

	/**
	 * Puts the template of the rules first in the hierarchy of the templates of the page, **unless the page chose a template of its own**.
	 * A template that does not exist is passed over by WordPress. Filter `page_template_hierarchy`.
	 *
	 * @param mixed $templates The templates, most specific first.
	 * @return mixed
	 */
	public function templates( $templates ) {
		$id = (int) ( $this->current )();

		if ( $id <= 0 || ! is_array( $templates ) ) {
			return $templates;
		}

		$own = ( $this->own_template )( $id );
		$slug = $this->rules->value( $id, 'template' );

		if ( '' !== $slug && ( '' === $own || 'default' === $own ) ) {
			array_unshift( $templates, $slug . '.php' );
		}

		return $templates;
	}

	/**
	 * The markup of the block `wiki/branch-status`: the status line of the page that is shown, nothing when there is none.
	 *
	 * @return string
	 */
	public function render_status() {
		$id = (int) ( $this->current )();

		if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
			return '<p class="wiki-branch-status">' . esc_html__( 'The status line of the branch is shown here.', 'otherguise' ) . '</p>';
		}

		$status = $id > 0 ? $this->rules->value( $id, 'status' ) : '';

		return '' === $status ? '' : '<p class="wiki-branch-status">' . esc_html( $status ) . '</p>';
	}

	/**
	 * Registers the block `wiki/branch-status`. Action `init`.
	 *
	 * @return void
	 */
	public function register_block() {
		register_block_type(
			'wiki/branch-status',
			array(
				'api_version'     => 3,
				'title'           => __( 'Wiki: status line of the branch', 'otherguise' ),
				'description'     => __( 'The status of the information of the branch the page is in (fact, source, interpretation), set in the rules of the branch.', 'otherguise' ),
				'category'        => 'widgets',
				'supports'        => array(
					'html'         => false,
					'autoRegister' => true,
				),
				'render_callback' => array( $this, 'render_status' ),
			)
		);
	}
}
