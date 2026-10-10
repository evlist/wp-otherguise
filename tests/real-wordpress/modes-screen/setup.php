<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 204, scratch site: creates or removes the templates and the variants used by flow.js. Usage: `wp eval-file setup.php create|cleanup`.
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- run on a scratch WordPress, outside the quality rules of the plugin.

$action = $args[0] ?? 'create';
$theme  = get_stylesheet();

foreach ( get_posts( array( 'post_type' => array( 'wp_template', 'wp_template_part' ), 'post_status' => 'any', 'numberposts' => -1 ) ) as $post ) {
	if ( 0 === strpos( $post->post_name, 'og204-' ) ) {
		wp_delete_post( $post->ID, true );
	}
}

foreach ( array( 'modes/has-variant', 'modes/has-part-variant' ) as $predicate ) {
	foreach ( triples_statements()->match( null, $predicate ) as $statement ) {
		triples_statements()->delete( $statement );
	}
}

if ( 'create' !== $action ) {
	echo "cleaned\n";

	return;
}

$p = static fn( $text ) => "<!-- wp:paragraph --><p>$text</p><!-- /wp:paragraph -->";

foreach ( array(
	array( 'wp_template', 'og204-single-print', 'Single for print', $p( 'OG204-SINGLE-PRINT' ) ),
	array( 'wp_template', 'og204-other', 'Another single', $p( 'OG204-OTHER' ) ),
	array( 'wp_template_part', 'og204-header-print', 'Header for print', $p( 'OG204-HEADER-PRINT' ) ),
) as list( $type, $slug, $title, $content ) ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish' ) );
	wp_set_object_terms( $id, $theme, 'wp_theme' );
}

echo "created\n";
