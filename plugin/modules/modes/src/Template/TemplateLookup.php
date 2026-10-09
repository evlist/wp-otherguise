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
	 * Returns the link that opens a template in the site editor.
	 *
	 * @param string $id   Id.
	 * @param string $type `wp_template` or `wp_template_part`.
	 * @return string
	 */
	public function edit_url( $id, $type ) {
		return admin_url( 'site-editor.php?postType=' . rawurlencode( $type ) . '&postId=' . rawurlencode( $id ) . '&canvas=edit' );
	}
}
