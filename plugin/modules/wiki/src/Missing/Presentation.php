<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * How the page that does not exist reaches the theme.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Missing;

defined( 'ABSPATH' ) || exit;

/**
 * Gives the 404 of a wiki address its offer, **with any theme** (slice 504): the wiki is probably not on the instance of the blog and
 * not in its theme.
 *
 * - the **block `wiki/missing-page`** is the offer; a theme can put it in its own 404 template;
 * - for a **block theme** the module registers a template `wiki-missing` (header, the block, footer) and puts it first in the hierarchy of
 *   the 404 **of a wiki address** (`register_block_template()`, WordPress 6.7); a theme that has the block in its 404 is not disturbed: it
 *   is a template of the plugin that a theme's template of the same slug replaces;
 * - for a **classic theme** the module answers with a template file of its own (`get_header()`, the offer, `get_footer()`).
 *
 * The status stays a 404.
 */
final class Presentation {
	/**
	 * Slug of the template of the block themes.
	 */
	public const TEMPLATE = 'wiki-missing';

	/**
	 * The page that does not exist.
	 *
	 * @var MissingPage
	 */
	private $missing;

	/**
	 * Tells whether the theme is a block theme.
	 *
	 * @var callable
	 */
	private $block_theme;

	/**
	 * Gives the path of the template file for the classic themes.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Builds the presentation.
	 *
	 * @param MissingPage   $missing     The page that does not exist.
	 * @param string        $file        Path of the template file of the classic themes.
	 * @param callable|null $block_theme Default `wp_is_block_theme`.
	 */
	public function __construct( MissingPage $missing, $file, $block_theme = null ) {
		$this->missing     = $missing;
		$this->file        = $file;
		$this->block_theme = $block_theme ?? 'wp_is_block_theme';
	}

	/**
	 * Registers the block. Action `init`.
	 *
	 * @return void
	 */
	public function register_block() {
		register_block_type(
			'wiki/missing-page',
			array(
				'api_version'     => 3,
				'title'           => __( 'Wiki: page that does not exist', 'otherguise' ),
				'description'     => __( 'On the 404 of a path of the tree: what was asked, the approaching paths, the same title elsewhere and the offer to create the page.', 'otherguise' ),
				'category'        => 'widgets',
				'supports'        => array(
					'html'         => false,
					'autoRegister' => true,
				),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Registers the template of the block themes. Action `init`.
	 *
	 * @return void
	 */
	public function register_template() {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}

		register_block_template(
			'otherguise//' . self::TEMPLATE,
			array(
				'title'       => __( 'Wiki page that does not exist', 'otherguise' ),
				'description' => __( 'The 404 of an address of the wiki: the offer to create the page.', 'otherguise' ),
				'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
					. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group"><!-- wp:wiki/missing-page /--></main><!-- /wp:group -->'
					. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
			)
		);
	}

	/**
	 * The markup of the block: the offer on the 404 of a wiki address, a note in the editor, nothing elsewhere.
	 *
	 * @return string
	 */
	public function render_block() {
		if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
			return '<div class="wiki-missing"><p>' . esc_html__( 'On the 404 of a wiki address, this block offers to create the page.', 'otherguise' ) . '</p></div>';
		}

		return $this->missing->render();
	}

	/**
	 * Puts the template of the plugin first in the hierarchy of the 404 of a wiki address, for a block theme. Filter `404_template_hierarchy`.
	 *
	 * @param mixed $templates The templates, most specific first.
	 * @return mixed
	 */
	public function hierarchy( $templates ) {
		if ( is_array( $templates ) && function_exists( 'register_block_template' ) && (bool) ( $this->block_theme )() && null !== $this->missing->asked() ) {
			array_unshift( $templates, self::TEMPLATE );
		}

		return $templates;
	}

	/**
	 * Answers the 404 of a wiki address with the template file of the module, for a classic theme. Filter `template_include`, late.
	 *
	 * @param mixed $template The template WordPress chose.
	 * @return mixed
	 */
	public function template( $template ) {
		return null !== $this->missing->asked() && ! (bool) ( $this->block_theme )() ? $this->file : $template;
	}
}
