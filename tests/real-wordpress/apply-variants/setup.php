<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 203, scratch site: creates or removes the templates and the variants of the checks of run.sh. Usage: `wp eval-file setup.php create`
 * or `wp eval-file setup.php cleanup` (the active theme must be Twenty Twenty-Five, or any block theme with `single`, `page`,
 * `archive` and the parts `header` and `footer`).
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- run on a scratch WordPress, outside the quality rules of the plugin.

use Otherguise\Core\Modules;

$action   = $args[0] ?? 'create';
$theme    = get_stylesheet();
$dir      = get_stylesheet_directory();
$modes    = Modules::get( 'modes' );
$variants = $modes->variants();
$print    = $modes->modes()->get( 'print' );
$web      = $modes->modes()->get( 'web' );

$cleanup = function () use ( $dir, $theme ) {
	foreach ( get_posts( array( 'post_type' => array( 'wp_template', 'wp_template_part' ), 'post_status' => 'any', 'numberposts' => -1 ) ) as $post ) {
		if ( 0 === strpos( $post->post_name, 'og203-' ) || 'single-post' === $post->post_name ) {
			wp_delete_post( $post->ID, true );
		}
	}

	foreach ( glob( "$dir/templates/og203-*.html" ) as $file ) {
		unlink( $file );
	}

	foreach ( glob( "$dir/parts/og203-*.html" ) as $file ) {
		unlink( $file );
	}

	foreach ( triples_statements()->match( null, 'modes/has-variant' ) as $statement ) {
		triples_statements()->delete( $statement );
	}

	foreach ( triples_statements()->match( null, 'modes/has-part-variant' ) as $statement ) {
		triples_statements()->delete( $statement );
	}
};

$cleanup();

if ( 'cleanup' === $action ) {
	echo "cleaned\n";

	return;
}

$make = function ( $type, $slug, $content ) use ( $theme ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_name' => $slug, 'post_title' => $slug, 'post_content' => $content, 'post_status' => 'publish' ) );
	wp_set_object_terms( $id, $theme, 'wp_theme' );

	return $id;
};
$p = static fn( $text ) => "<!-- wp:paragraph --><p>$text</p><!-- /wp:paragraph -->";

$make( 'wp_template', 'og203-single-print', $p( 'OG203-SINGLE-PRINT' ) );
$make( 'wp_template', 'og203-single-post-print', $p( 'OG203-SINGLE-POST-PRINT' ) );
$make( 'wp_template', 'og203-with-parts', '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->' . $p( 'OG203-WITH-PARTS' ) . '<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->' );
$make( 'wp_template_part', 'og203-header-print', $p( 'OG203-HEADER-PRINT' ) );
file_put_contents( "$dir/templates/og203-page-print.html", $p( 'OG203-PAGE-PRINT' ) );
file_put_contents( "$dir/templates/og203-archive-web.html", $p( 'OG203-ARCHIVE-WEB' ) );
file_put_contents( "$dir/parts/og203-footer-print.html", $p( 'OG203-FOOTER-PRINT' ) );

$variants->declare( $variants->template( 'single' ), $print, $variants->template( 'og203-single-print' ) );
$variants->declare( $variants->template( 'page' ), $print, $variants->template( 'og203-page-print' ) );
$variants->declare( $variants->template( 'archive' ), $web, $variants->template( 'og203-archive-web' ) );
$variants->declare( $variants->part( 'header' ), $print, $variants->part( 'og203-header-print' ) );
$variants->declare( $variants->part( 'footer' ), $print, $variants->part( 'og203-footer-print' ) );

echo "created; variants: " . count( triples_statements()->match( null, 'modes/has-variant' ) ) . ' templates, ' . count( triples_statements()->match( null, 'modes/has-part-variant' ) ) . " parts\n";
