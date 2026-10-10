<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 205: creates (`create`) or removes (`remove`) the fixtures of flow.js: a post, and a `single` template of the active theme that
 * uses the block modes/link in place of the `javascript:` link. Run with `wp eval-file setup.php create|remove`.
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- run on a scratch WordPress, outside the quality rules of the plugin.

/**
 * Creates or removes the fixtures.
 *
 * @param string $action `create` or `remove`.
 * @return string What was done.
 */
function og205_setup( $action ) {
	$theme  = get_stylesheet();
	$title  = 'og205 single';

	foreach ( get_posts(
		array(
			'post_type' => 'wp_template',
			'name' => 'single',
			'post_status' => 'any',
			'numberposts' => -1,
		)
	) as $old ) {
		wp_delete_post( $old->ID, true );
	}
	foreach ( get_posts(
		array(
			'title' => 'og205 post',
			'post_type' => 'post',
			'post_status' => 'any',
			'numberposts' => -1,
		)
	) as $old ) {
		wp_delete_post( $old->ID, true );
	}

	if ( 'create' !== $action ) {
		return 'removed';
		return;
	}

	$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 8H5v6h14z"/></svg>';
	$tpl = '<!-- wp:post-title /-->' .
		'<!-- wp:modes/link {"mode":"print","label":"Print version"} --><!-- wp:html -->' . $svg . '<!-- /wp:html --><!-- /wp:modes/link -->' .
		'<!-- wp:modes/link {"mode":"web"} --><!-- /wp:modes/link -->' .
		'<!-- wp:post-content /-->';

	$id = wp_insert_post(
		array(
			'post_type'    => 'wp_template',
			'post_name'    => 'single',
			'post_title'   => 'Single (og205)',
			'post_status'  => 'publish',
			'post_content' => $tpl,
		)
	);
	wp_set_object_terms( $id, $theme, 'wp_theme' );
	$post = wp_insert_post(
		array(
			'post_title'   => 'og205 post',
			'post_status'  => 'publish',
			'post_content' => '<!-- wp:paragraph --><p>Body of the post.</p><!-- /wp:paragraph -->',
		)
	);
	return "template $id post $post";
}

WP_CLI::log( og205_setup( isset( $args[0] ) ? $args[0] : 'create' ) );
