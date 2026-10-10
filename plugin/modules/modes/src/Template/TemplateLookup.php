<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the modes ask WordPress about templates.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Template;

defined( 'ABSPATH' ) || exit;

/**
 * The only place of the module that calls the WordPress functions that find templates, so that the tests replace it. Not tried here
 * except on a real site (slice 202, see the document of the slice).
 */
class TemplateLookup {
	/**
	 * Returns the stylesheet (the directory name) of the active theme: the theme part of the ids.
	 *
	 * @return string
	 */
	public function stylesheet() {
		return (string) get_stylesheet();
	}

	/**
	 * Finds a block template, from the database or from a file of the theme.
	 *
	 * @param string $id   Id (`stylesheet//slug`).
	 * @param string $type `wp_template` or `wp_template_part`.
	 * @return \WP_Block_Template|null
	 */
	public function block_template( $id, $type ) {
		$template = get_block_template( $id, $type );

		return $template instanceof \WP_Block_Template ? $template : null;
	}

	/**
	 * Tells whether the active theme (or its parent) has the PHP template `slug.php`: the templates of a classic theme.
	 *
	 * `locate_template()` also falls back on the files of `wp-includes/theme-compat/` (`header.php`, `footer.php`, `sidebar.php`,
	 * `comments.php`), which belong to no theme (seen on WordPress 7.1.3): only a file inside the theme directories counts.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function php_template_exists( $slug ) {
		$path = locate_template( $slug . '.php' );

		return '' !== $path && ( 0 === strpos( $path, get_stylesheet_directory() . '/' ) || 0 === strpos( $path, get_template_directory() . '/' ) );
	}

	/**
	 * Lists the types of content that are edited in the block editor and shown in the administration: the types that can have a template
	 * of their own.
	 *
	 * @return array<string, string> Label by slug of the type, `attachment` excluded.
	 */
	public function post_types() {
		$types = array();

		foreach ( get_post_types(
			array(
				'public' => true,
				'show_ui' => true,
			),
			'objects'
		) as $slug => $type ) {
			if ( 'attachment' !== $slug && post_type_supports( $slug, 'editor' ) ) {
				$types[ $slug ] = (string) $type->labels->singular_name;
			}
		}

		return $types;
	}

	/**
	 * Returns the link that opens a template in the site editor.
	 *
	 * @param string $id   Id.
	 * @param string $type `wp_template` or `wp_template_part`.
	 * @return string
	 */
	public function edit_url( $id, $type ) {
		return admin_url( 'site-editor.php?postType=' . rawurlencode( $type ) . '&postId=' . rawurlencode( $id ) . '&canvas=edit' );
	}

	/**
	 * Lists the templates or the template parts of the active theme, for the lists of the screens: block templates (database and files),
	 * and, for a classic theme and for templates, the PHP files at the root of the theme.
	 *
	 * @param string $type `wp_template` or `wp_template_part`.
	 * @return array<string, string> Label by id (`stylesheet//slug`), sorted by label.
	 */
	public function templates( $type ) {
		$list = array();

		foreach ( get_block_templates( array(), $type ) as $template ) {
			$list[ $template->id ] = '' !== (string) $template->title ? $template->title . ' (' . $template->slug . ')' : $template->slug;
		}

		if ( 'wp_template' === $type && ! wp_is_block_theme() ) {
			foreach ( array_keys( wp_get_theme()->get_files( 'php', 0, false ) ) as $file ) {
				$slug = preg_replace( '/\.php\z/', '', $file );

				if ( 'functions' !== $slug && 1 === preg_match( '/^[A-Za-z0-9_.\-]+\z/', $slug ) ) {
					$list[ $this->stylesheet() . '//' . $slug ] = $slug;
				}
			}
		}

		asort( $list, SORT_NATURAL | SORT_FLAG_CASE );

		return $list;
	}
}
