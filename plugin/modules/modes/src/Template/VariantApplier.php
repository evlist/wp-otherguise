<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Application of the variants to the template of the request.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Makes WordPress use the variant of a template, and of a template part, in the mode of the request (the hooks decided in slice 201).
 *
 * - **Templates**: the filter `{$type}_template_hierarchy` of `get_query_template()` gets the slug of the variant just before the slug of
 *   the template it replaces. WordPress then finds it like any other candidate, in the database, in the files of a block theme or among
 *   the PHP files of a classic theme; a variant that does not exist is ignored and the template is used. A variant declared for `single`
 *   applies when `single` is the template WordPress would have chosen.
 * - **Template parts**: the filter `render_block_data` replaces the `slug` of a `core/template-part` block.
 *
 * Nothing is touched in the administration or in REST requests (the site editor, the block renderer): the template hierarchy is only
 * used to find the template of a page, and the parts are only replaced when the request is a page of the site.
 */
final class VariantApplier {
	/**
	 * The types of template that core documents for `get_query_template()` (WordPress 7.1, `template.php`).
	 */
	public const TYPES = array(
		'404',
		'archive',
		'attachment',
		'author',
		'category',
		'date',
		'embed',
		'frontpage',
		'home',
		'index',
		'page',
		'paged',
		'privacypolicy',
		'search',
		'single',
		'singular',
		'tag',
		'taxonomy',
	);

	/**
	 * Priority of the filters on the template hierarchy: late, to see what other plugins add.
	 */
	public const HIERARCHY_PRIORITY = 90;

	/**
	 * Returns the variants of the mode of the request: receives `template` or `template_part`, returns the id of the variant by id of
	 * the template.
	 *
	 * @var callable
	 */
	private $maps;

	/**
	 * Returns the stylesheet of the active theme.
	 *
	 * @var callable
	 */
	private $theme;

	/**
	 * Tells whether the request is a page of the site (not the administration, not REST).
	 *
	 * @var callable
	 */
	private $is_front;

	/**
	 * Maps by kind, read once per request: slug of the template by slug of the variant.
	 *
	 * @var array<string, array<string, string>>
	 */
	private $slugs = array();

	/**
	 * Builds the applier.
	 *
	 * @param callable $maps     Returns the variants of the mode of the request for a kind (`template` or `template_part`).
	 * @param callable $theme    Returns the stylesheet of the active theme.
	 * @param callable $is_front Tells whether the request is a page of the site.
	 */
	public function __construct( $maps, $theme, $is_front ) {
		$this->maps     = $maps;
		$this->theme    = $theme;
		$this->is_front = $is_front;
	}

	/**
	 * Adds the filters to WordPress. Called on `init`, so that the plugins that add types have run.
	 *
	 * @param callable $add_filter Adds a filter: `add_filter`.
	 * @param string[] $types      Types of template to cover.
	 * @return void
	 */
	public function register( $add_filter, array $types = self::TYPES ) {
		foreach ( $types as $type ) {
			if ( is_string( $type ) && 1 === preg_match( '/^[a-z0-9]+\z/', $type ) ) {
				$add_filter( $type . '_template_hierarchy', array( $this, 'hierarchy' ), self::HIERARCHY_PRIORITY, 1 );
			}
		}

		$add_filter( 'render_block_data', array( $this, 'block_data' ), 10, 1 );
	}

	/**
	 * Puts the variants in a template hierarchy. Filter `{$type}_template_hierarchy`.
	 *
	 * @param string[] $templates Candidates, in decreasing order of specificity (`single-post.php`, `single.php`).
	 * @return string[]
	 */
	public function hierarchy( $templates ) {
		if ( ! is_array( $templates ) || ! ( $this->is_front )() ) {
			return $templates;
		}

		$variants = $this->variants( TemplateRef::TEMPLATE );

		if ( array() === $variants ) {
			return $templates;
		}

		$result = array();

		foreach ( $templates as $candidate ) {
			$slug = is_string( $candidate ) ? preg_replace( '/\.(php|html)\z/', '', $candidate ) : '';

			if ( isset( $variants[ $slug ] ) && ! in_array( $variants[ $slug ] . substr( $candidate, strlen( $slug ) ), $templates, true ) ) {
				$result[] = $variants[ $slug ] . substr( $candidate, strlen( $slug ) );
			}

			$result[] = $candidate;
		}

		return $result;
	}

	/**
	 * Replaces the slug of a template part block by the one of its variant. Filter `render_block_data`.
	 *
	 * @param array $parsed_block Block as parsed.
	 * @return array
	 */
	public function block_data( $parsed_block ) {
		if ( ! is_array( $parsed_block ) || 'core/template-part' !== ( $parsed_block['blockName'] ?? null ) || ! isset( $parsed_block['attrs']['slug'] ) || ! is_string( $parsed_block['attrs']['slug'] ) ) {
			return $parsed_block;
		}

		$theme = $parsed_block['attrs']['theme'] ?? ( $this->theme )();

		if ( ! is_string( $theme ) || ( $this->theme )() !== $theme || ! ( $this->is_front )() ) {
			return $parsed_block;
		}

		$variants = $this->variants( TemplateRef::PART );

		if ( isset( $variants[ $parsed_block['attrs']['slug'] ] ) ) {
			$parsed_block['attrs']['slug'] = $variants[ $parsed_block['attrs']['slug'] ];
		}

		return $parsed_block;
	}

	/**
	 * Returns the variants of the active theme for the mode of the request, by slug: slug of the variant by slug of the template.
	 * Read once per request and kind. The relations of another theme (stored before a switch of theme) do not apply.
	 *
	 * @param string $kind `template` or `template_part`.
	 * @return array<string, string>
	 */
	private function variants( $kind ) {
		if ( isset( $this->slugs[ $kind ] ) ) {
			return $this->slugs[ $kind ];
		}

		$theme               = ( $this->theme )();
		$this->slugs[ $kind ] = array();

		foreach ( ( $this->maps )( $kind ) as $source => $variant ) {
			if ( TemplateRef::is_valid_id( $source ) && TemplateRef::is_valid_id( $variant ) && TemplateRef::theme( $source ) === $theme && TemplateRef::theme( $variant ) === $theme ) {
				$this->slugs[ $kind ][ TemplateRef::slug( $source ) ] = TemplateRef::slug( $variant );
			}
		}

		return $this->slugs[ $kind ];
	}
}
