<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The command `wp otherguise modes variant list|retheme` on a scratch WordPress site (`wp eval-file run.php`). Needs Otherguise active, the
 * theme twentytwentyfive and a child theme of it named twentytwentyfive-printable (evlist/wp-twenty-twenty-five-printable). It declares
 * variants, moves them to the child theme and removes them again; the active theme is put back. Never run it on a site that matters.
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- run on a scratch WordPress, outside the quality rules of the plugin.

use Otherguise\Core\Modules;

$pass = 0;
$fail = 0;

$check = static function ( $label, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	$ok ? ++$pass : ++$fail;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( '' !== $detail ? '  [' . $detail . ']' : '' ) . "\n";
};

$run = static function ( $args ) {
	return WP_CLI::runcommand( 'otherguise modes variant ' . $args, array( 'return' => 'all', 'launch' => false, 'exit_error' => false ) );
};

$original = get_stylesheet();
$variants = Modules::get( 'modes' )->variants();
$print    = new \Otherguise\Triples\Entity\EntityRef( 'mode', 'print' );

$clean = static function () use ( $variants ) {
	foreach ( array( 'twentytwentyfive', 'twentytwentyfive-printable' ) as $theme ) {
		foreach ( array( 'template' => 'wp_template', 'template_part' => 'wp_template_part' ) as $kind => $type ) {
			foreach ( $variants->relations( $kind ) as $relation ) {
				if ( 0 === strpos( $relation['source']->id(), $theme . '//' ) ) {
					$variants->remove( $relation['source'], $relation['variant'] );
				}
			}
		}
	}
};

$check( 'the child theme is installed', wp_get_theme( 'twentytwentyfive-printable' )->exists() );

// The variants are declared while the parent theme is active, as they are on the blog.
switch_theme( 'twentytwentyfive' );
$clean();
$variants->declare( $variants->template( 'single' ), $print, $variants->template( 'page' ) );
$variants->declare( $variants->part( 'header' ), $print, $variants->part( 'header-large-title' ) );

$list = $run( 'list --theme=twentytwentyfive' );
$check( 'list shows the variants of the theme, tab separated, with their modes', false !== strpos( $list->stdout, "template\ttwentytwentyfive//single\ttwentytwentyfive//page\tprint" ) && false !== strpos( $list->stdout, "template_part\ttwentytwentyfive//header\ttwentytwentyfive//header-large-title\tprint" ), trim( $list->stdout ) );

$none = $run( 'retheme twentytwentyfive twentytwentyfive-printable' );
// With the parent still active the templates of the child do not exist for WordPress: nothing is moved and nothing is lost.
$check( 'before the theme is active the command refuses and loses nothing', 0 !== $none->return_code && false !== strpos( $none->stdout . $none->stderr, 'activate the theme first' ), trim( $none->stdout . $none->stderr ) );
$check( '...the relations are still there', false !== strpos( $run( 'list --theme=twentytwentyfive' )->stdout, 'twentytwentyfive//single' ) );

switch_theme( 'twentytwentyfive-printable' );
$variants = Modules::get( 'modes' )->variants();

$dry = $run( 'retheme twentytwentyfive twentytwentyfive-printable --dry-run' );
$check( 'dry run: says what would be moved and writes nothing', false !== strpos( $dry->stdout, 'Dry run, nothing written: 2 relation(s) to move' ) && false !== strpos( $run( 'list --theme=twentytwentyfive' )->stdout, 'twentytwentyfive//single' ), trim( $dry->stdout ) );

$real = $run( 'retheme twentytwentyfive twentytwentyfive-printable' );
$check( 'retheme moves the two relations', 0 === $real->return_code && false !== strpos( $real->stdout, 'Done: 2 relation(s) moved' ), trim( $real->stdout . $real->stderr ) );
$after = $run( 'list' )->stdout;
$check( 'they now point at the templates of the child theme, with the mode', false !== strpos( $after, "template\ttwentytwentyfive-printable//single\ttwentytwentyfive-printable//page\tprint" ) && false !== strpos( $after, "template_part\ttwentytwentyfive-printable//header\ttwentytwentyfive-printable//header-large-title\tprint" ), trim( $after ) );
$check( '...and none is left on the old theme', '' === trim( $run( 'list --theme=twentytwentyfive' )->stdout ) );

$again = $run( 'retheme twentytwentyfive twentytwentyfive-printable' );
$check( 'a second run has nothing to move', 0 === $again->return_code && false !== strpos( $again->stdout, 'Done: 0 relation(s) moved' ), trim( $again->stdout ) );

$map = $variants->map( $print );
$check( 'the variant of the request is read from the child theme templates', 'twentytwentyfive-printable//page' === ( $map['twentytwentyfive-printable//single'] ?? null ), wp_json_encode( $map ) );

$clean();
switch_theme( $original );

echo "\n$pass passed, $fail failed\n";
