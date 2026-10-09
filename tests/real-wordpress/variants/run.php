<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 202: the variants of templates and template parts on a real WordPress. Run with `wp eval-file` on a scratch site where the
 * plugin is active and a block theme (Twenty Twenty-Five) is the active theme. It creates and deletes templates and statements.
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- run on a scratch WordPress, outside the quality rules of the plugin.

use Otherguise\Core\Modules;
use Otherguise\Triples\Entity\EntityRef;

$GLOBALS['og202'] = array( 'pass' => 0, 'fail' => 0 );

function og202_check( $label, $ok, $detail = '' ) {
	$GLOBALS['og202'][ $ok ? 'pass' : 'fail' ]++;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( '' !== $detail ? "  [$detail]" : '' ) . "\n";
}

function og202_refusal( $call ) {
	try {
		$call();
	} catch ( \Otherguise\Modes\Variant\VariantException | \Otherguise\Triples\Service\InvalidStatementException $problem ) {
		return ( $problem instanceof \Otherguise\Triples\Service\InvalidStatementException ? 'triples:' : '' ) . $problem->error_code();
	}

	return null;
}

$theme    = get_stylesheet();
$modes    = Modules::get( 'modes' );
$statements = triples_statements();
$variants = $modes->variants();
$print    = $modes->modes()->get( 'print' );
$web      = $modes->modes()->get( 'web' );
$dir      = get_stylesheet_directory();

echo 'WordPress ' . get_bloginfo( 'version' ) . ", theme $theme\n";

// Fixtures: database templates and a part, and a file template and a part in the theme.
$make = function ( $type, $slug, $title, $content ) use ( $theme ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish' ) );
	wp_set_object_terms( $id, $theme, 'wp_theme' );

	return $id;
};
$ids   = array();
$ids[] = $make( 'wp_template', 'og202-single-print', 'Single for print', '<!-- wp:paragraph --><p>OG202</p><!-- /wp:paragraph -->' );
$ids[] = $make( 'wp_template_part', 'og202-header-print', 'Header for print', '<!-- wp:paragraph --><p>OG202H</p><!-- /wp:paragraph -->' );
file_put_contents( "$dir/templates/og202-page-print.html", '<!-- wp:paragraph --><p>OG202F</p><!-- /wp:paragraph -->' );
file_put_contents( "$dir/parts/og202-footer-print.html", '<!-- wp:paragraph --><p>OG202FP</p><!-- /wp:paragraph -->' );

try {
	// The real WP_Block_Template objects.
	$single_print = get_block_template( "$theme//og202-single-print", 'wp_template' );
	$single       = get_block_template( "$theme//single", 'wp_template' );
	$header_print = get_block_template( "$theme//og202-header-print", 'wp_template_part' );
	$header       = get_block_template( "$theme//header", 'wp_template_part' );
	og202_check( 'WordPress gives WP_Block_Template objects for the fixtures', $single_print instanceof WP_Block_Template && $header_print instanceof WP_Block_Template && $single && $header );
	og202_check( 'their types are what the entity types expect', 'wp_template' === $single->type && 'wp_template_part' === $header->type );

	// Recognition.
	og202_check( 'a template object is the entity template:theme//slug', "template:$theme//single" === (string) $statements->entity( $single ) );
	og202_check( 'a template part object is the entity template_part:theme//slug', "template_part:$theme//header" === (string) $statements->entity( $header ) );

	// Existence: database, theme file, theme part file, and what does not exist.
	$types = Modules::get( 'triples' )->entity_types();
	og202_check( 'exists: a template of the theme (file)', $types->get( 'template' )->exists( "$theme//single" ) );
	og202_check( 'exists: a template of the database', $types->get( 'template' )->exists( "$theme//og202-single-print" ) );
	og202_check( 'exists: a template that is a file added to the theme', $types->get( 'template' )->exists( "$theme//og202-page-print" ) );
	og202_check( 'exists: a part that is a file added to the theme', $types->get( 'template_part' )->exists( "$theme//og202-footer-print" ) );
	og202_check( 'exists: not a missing template', false === $types->get( 'template' )->exists( "$theme//ghost" ) );
	og202_check( 'exists: not a template part given as a template (WordPress falls back on its theme-compat header.php: only files of the theme count)', false === $types->get( 'template' )->exists( "$theme//header" ) && false === $types->get( 'template' )->exists( "$theme//footer" ) );
	og202_check( 'exists: not a template of another theme', false === $types->get( 'template' )->exists( 'othertheme//single' ) );

	// Describe.
	$description = $types->get( 'template' )->describe( "$theme//single" );
	og202_check( 'describe: title and the link of the site editor', '' !== $description['label'] && false !== strpos( $description['url'], 'site-editor.php?postType=wp_template&postId=' ), $description['label'] . ' ' . $description['url'] );

	// Declare, with objects, references and files.
	$link = $variants->declare( $single, $print, $single_print );
	og202_check( 'declare: a template and its variant from the database, given as objects', 'modes/has-variant' === $link->predicate() );
	$variants->declare( $variants->template( 'page' ), $print, $variants->template( 'og202-page-print' ) );
	$variants->declare( $header, $print, $header_print );
	$variants->declare( $variants->part( 'footer' ), $print, $variants->part( 'og202-footer-print' ) );
	og202_check( 'declare: a variant that is a theme file, a part variant from the database, a part variant that is a file', 2 === count( $statements->match( null, 'modes/has-variant' ) ) && 2 === count( $statements->match( null, 'modes/has-part-variant' ) ) );

	$map = $variants->map( $print );
	og202_check( 'map: every template variant of the mode in one go', array( "$theme//single" => "$theme//og202-single-print", "$theme//page" => "$theme//og202-page-print" ) === $map, wp_json_encode( $map ) );
	$parts = $variants->map( $print, 'template_part' );
	og202_check( 'map: the template part variants', array( "$theme//header" => "$theme//og202-header-print", "$theme//footer" => "$theme//og202-footer-print" ) === $parts, wp_json_encode( $parts ) );
	og202_check( 'map: nothing for the web mode', array() === $variants->map( $web ) );

	// Rules on real objects.
	og202_check( 'refused: a template that does not exist', 'triples:object_missing' === og202_refusal( fn() => $variants->declare( $variants->template( 'index' ), $print, $variants->template( 'ghost' ) ) ) );
	og202_check( 'refused: a template and a part', 'kind_mismatch' === og202_refusal( fn() => $variants->declare( $single, $print, $header_print ) ) );
	og202_check( 'refused: another variant in the same mode', 'mode_already_served' === og202_refusal( fn() => $variants->declare( $single, $print, $variants->template( 'og202-page-print' ) ) ) );
	og202_check( 'refused: a post is not a template', 'not_a_template' === og202_refusal( fn() => $variants->declare( get_post( 1 ), $print, $single_print ) ) );

	// What is said about the relation is reachable through the generic API.
	$about = $statements->match( $link, 'modes/mode' );
	og202_check( 'the mode is a statement about the relation, and describes as a ModeDefinition', 1 === count( $about ) && $statements->resolve( $about[0]->object() ) === $print );

	// Withdraw and remove.
	og202_check( 'withdraw: the mode statement and the relation that serves no mode go', 2 === $variants->withdraw( $single, $print, $single_print ) && null === $variants->variant_of( $single, $print ) );
	og202_check( 'remove: the whole relation, with its mode statement', 2 === $variants->remove( $variants->template( 'page' ), $variants->template( 'og202-page-print' ) ) && 0 === count( $statements->match( $variants->template( 'page' ), 'modes/has-variant' ) ) );

	// A template that is deleted: its relations are not cleaned (templates are not an entity WordPress tells us about) but the screens show them.
	$variants->declare( $single, $print, $single_print );
	wp_delete_post( $ids[0], true );
	$orphans = ( new \Otherguise\Modes\Variant\Variants( $statements, new \Otherguise\Modes\Template\TemplateLookup() ) )->map( $print );
	og202_check( 'a deleted variant stays in the map: the relation is not cleaned up (a gap, see the document of the slice)', isset( $orphans[ "$theme//single" ] ) && false === $types->get( 'template' )->exists( "$theme//og202-single-print" ) );
} finally {
	foreach ( $statements->match() as $statement ) {
		if ( in_array( $statement->predicate(), array( 'modes/has-variant', 'modes/has-part-variant' ), true ) ) {
			$statements->delete( $statement );
		}
	}

	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}

	@unlink( "$dir/templates/og202-page-print.html" );
	@unlink( "$dir/parts/og202-footer-print.html" );
}

echo "{$GLOBALS['og202']['pass']} passed, {$GLOBALS['og202']['fail']} failed\n";
