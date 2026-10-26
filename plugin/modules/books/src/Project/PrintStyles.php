<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Gives the rules of the printer to the page, in the print mode.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Project;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the CSS of the printer profile (`PageRules`) to a page of a post that a book project covers, **in the print mode only** (slice 230).
 * The theme is asked for nothing: it can use the variables `--books-margin`, `--books-gutter` and `--books-safe` with a fallback of its own.
 * Coexistence: the style is added to the page with `wp_add_inline_style()` at priority 20 of `wp_enqueue_scripts`, after the style sheets of
 * the theme and of the Modes module, so its `@page` rules come last; a theme that has its own `@page` rule is overridden for the margins and
 * the size, which is the purpose.
 */
final class PrintStyles {
	/**
	 * Handle of the style.
	 */
	public const HANDLE = 'books-page-rules';

	/**
	 * Name of the query argument that chooses a project when several cover the same post.
	 */
	public const ARGUMENT = 'books_project';

	/**
	 * The projects.
	 *
	 * @var Projects
	 */
	private $projects;

	/**
	 * Tells whether the page is in a print mode.
	 *
	 * @var callable
	 */
	private $is_print;

	/**
	 * Returns the id of the post of the page, 0 when the page is not a single post.
	 *
	 * @var callable
	 */
	private $post_id;

	/**
	 * Returns the id of the project asked for in the address, 0 for none.
	 *
	 * @var callable
	 */
	private $requested;

	/**
	 * Builds the service.
	 *
	 * @param Projects      $projects  The projects.
	 * @param callable      $is_print  Tells whether the mode of the page is a print mode.
	 * @param callable|null $post_id   Defaults to the queried post of a single page.
	 * @param callable|null $requested Defaults to a read of `books_project` in the query string.
	 */
	public function __construct( Projects $projects, $is_print, $post_id = null, $requested = null ) {
		$this->projects  = $projects;
		$this->is_print  = $is_print;
		$this->post_id   = $post_id ?? static function () {
			return is_singular() ? (int) get_queried_object_id() : 0;
		};
		$this->requested = $requested ?? static function () {
			$value = filter_input( INPUT_GET, self::ARGUMENT, FILTER_VALIDATE_INT );

			return is_int( $value ) && $value > 0 ? $value : 0;
		};
	}

	/**
	 * Adds the style. Action `wp_enqueue_scripts`, priority 20.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! ( $this->is_print )() ) {
			return;
		}

		$post_id = (int) ( $this->post_id )();
		$found   = $post_id > 0 ? $this->projects->for_post( $post_id, (int) ( $this->requested )() ) : null;

		if ( null === $found ) {
			return;
		}

		wp_register_style( self::HANDLE, false, array(), '230.1' );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, $found['css'] );
	}
}
