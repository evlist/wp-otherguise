<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Run by tools/staging/templates.sh through `wp eval`, on the site: it looks at the templates and the template parts that the site
 * editor stored in the database. Not a plugin; the action comes from the variable $otherguise_action that templates.sh sets first.
 *
 * - check: one line per template of the database, "STATUS<TAB>type<TAB>slug<TAB>ID<TAB>theme<TAB>title", STATUS being
 *   SHADOWS (attached to the active theme, which also has a file for it: the database copy is used and the file is ignored),
 *   DATABASE-ONLY (attached to the active theme, no file), or UNUSED (attached to another theme: the active theme does not use it).
 * - export-all, export-shadowing: the same templates as files, "@@FILE path" then the content, for templates.sh to write, with
 *   customTemplates.json for the custom ones; the "theme" attribute of the template parts is removed (see the theme's README).
 * - delete-shadowing: deletes the database copies that shadow a file (for good, not to the trash).
 *
 * @package Otherguise
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the templates and parts of the database with what they are to the active theme.
 *
 * @return array<int, array<string, mixed>>
 */
function otherguise_templates_inventory() {
	$active = get_stylesheet();
	$found  = array();

	foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'auto-draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'name',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$themes = wp_get_object_terms( $post->ID, 'wp_theme', array( 'fields' => 'names' ) );
			$theme  = is_array( $themes ) && ! empty( $themes ) ? (string) $themes[0] : '';

			if ( $theme === $active ) {
				$status = null !== _get_block_template_file( $type, $post->post_name ) ? 'SHADOWS' : 'DATABASE-ONLY';
			} else {
				$status = 'UNUSED';
			}

			$found[] = array(
				'status'  => $status,
				'type'    => $type,
				'id'      => $post->ID,
				'slug'    => $post->post_name,
				'theme'   => $theme,
				'title'   => $post->post_title,
				'content' => $post->post_content,
			);
		}
	}

	return $found;
}

/**
 * Prints the templates as files for templates.sh to write.
 *
 * @param array<int, array<string, mixed>> $items Items of the inventory.
 * @return void
 */
function otherguise_templates_print_files( array $items ) {
	$standard = array_keys( get_default_block_template_types() );
	$custom   = array();

	foreach ( $items as $item ) {
		$dir     = 'wp_template' === $item['type'] ? 'templates' : 'parts';
		$content = preg_replace( '/(<!-- wp:template-part \{[^}]*?),"theme":"[^"]*"/', '$1', $item['content'] );

		WP_CLI::line( '@@FILE ' . $dir . '/' . $item['slug'] . '.html' );
		WP_CLI::line( $content );
		WP_CLI::line( '@@END' );

		if ( 'wp_template' === $item['type'] && ! in_array( $item['slug'], $standard, true ) ) {
			$custom[] = array(
				'name'      => $item['slug'],
				'title'     => $item['title'],
				'postTypes' => array( 'post' ),
			);
		}
	}

	WP_CLI::line( '@@FILE customTemplates.json' );
	WP_CLI::line( wp_json_encode( $custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	WP_CLI::line( '@@END' );
}

/**
 * Runs an action.
 *
 * @param string $action check, export-all, export-shadowing or delete-shadowing.
 * @return void
 */
function otherguise_templates_run( $action ) {
	$items = otherguise_templates_inventory();

	switch ( $action ) {
		case 'check':
			WP_CLI::line( "ACTIVE\t" . get_stylesheet() . ' (parent: ' . get_template() . ')' );
			foreach ( $items as $item ) {
				WP_CLI::line( implode( "\t", array( $item['status'], $item['type'], $item['slug'], $item['id'], $item['theme'], $item['title'] ) ) );
			}
			break;
		case 'export-all':
			otherguise_templates_print_files( $items );
			break;
		case 'export-shadowing':
			otherguise_templates_print_files(
				array_values(
					array_filter(
						$items,
						static function ( $item ) {
							return 'SHADOWS' === $item['status'];
						}
					)
				)
			);
			break;
		case 'delete-shadowing':
			foreach ( $items as $item ) {
				if ( 'SHADOWS' === $item['status'] ) {
					wp_delete_post( $item['id'], true );
					WP_CLI::line( implode( "\t", array( 'DELETED', $item['type'], $item['slug'], $item['id'] ) ) );
				}
			}
			break;
	}
}

otherguise_templates_run( $otherguise_action );
