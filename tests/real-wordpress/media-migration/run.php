<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 211: the command `wp otherguise media migrate` on a scratch WordPress site (`wp eval-file run.php`). Needs Otherguise active and
 * the modes `web` and `print` declared. It creates posts and attachments (without files) and removes them. Never run it on a site that
 * matters.
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- run on a scratch WordPress, outside the quality rules of the plugin.

$pass = 0;
$fail = 0;

$check = static function ( $label, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	$ok ? ++$pass : ++$fail;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( '' !== $detail ? '  [' . $detail . ']' : '' ) . "\n";
};

$cleanup = static function () {
	foreach ( get_posts( array( 'post_type' => array( 'post', 'attachment' ), 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
		if ( 0 === strpos( $p->post_title, 'og211' ) ) {
			wp_delete_post( $p->ID, true );
		}
	}
};
$cleanup();

// The scratch site may hold other attachments with a parent: the command migrates them too, so the links it makes are removed at the end.
$before = array();
foreach ( triples_statements()->match( null, 'media/illustrated-by' ) as $statement ) {
	$before[ $statement->subject()->id() . ':' . $statement->object()->id() ] = true;
}

$run = static function ( $args ) {
	return WP_CLI::runcommand( 'otherguise media migrate ' . $args, array( 'return' => 'all', 'launch' => false, 'exit_error' => false ) );
};
$links = static function ( $post, $attachment ) {
	$statements = triples_statements();
	return $statements->find_by_triple( new \Otherguise\Triples\Entity\EntityRef( 'post', (string) $post ), 'media/illustrated-by', new \Otherguise\Triples\Entity\EntityRef( 'attachment', (string) $attachment ) );
};
$modes = static function ( $link ) {
	return null === $link ? null : implode( ',', array_map( static fn( $s ) => $s->object()->id(), triples_statements()->objects_of( $link, 'modes/mode' ) ) );
};

wp_set_current_user( 1 );

$post  = wp_insert_post( array( 'post_title' => 'og211 post', 'post_status' => 'publish' ) );
$flag  = wp_insert_attachment( array( 'post_title' => 'og211 flagged', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_parent' => $post ), 'og211/a.jpg' );
$plain = wp_insert_attachment( array( 'post_title' => 'og211 plain', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_parent' => $post ), 'og211/b.jpg' );
$gpx   = wp_insert_attachment( array( 'post_title' => 'og211 gpx', 'post_mime_type' => 'application/gpx+xml', 'post_status' => 'inherit', 'post_parent' => $post ), 'og211/c.gpx' );
$free  = wp_insert_attachment( array( 'post_title' => 'og211 unattached', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), 'og211/d.jpg' );
update_post_meta( $flag, 'wpdfh.print', 'always' );


$dry = $run( '--dry-run --verbose' );
$check( 'dry run: says nothing is written', false !== strpos( $dry->stdout, 'Dry run, nothing written' ), trim( $dry->stdout ) . $dry->stderr );
foreach ( array( $flag => 'web,print', $plain => 'web', $gpx => 'web,print' ) as $id => $expected ) {
	$check( "dry run: attachment $id would be linked in $expected", false !== strpos( $dry->stdout, "attachment $id, post $post: would be linked in $expected" ) );
}
$check( 'dry run: writes nothing', null === $links( $post, $flag ) && null === $links( $post, $plain ) );

$real = $run( '--batch-size=2' );
$check( 'run: done, nothing skipped', 1 === preg_match( '/^Done: \d+ link\(s\) created, \d+ already linked, 0 skipped\./m', $real->stdout ), trim( $real->stdout ) . $real->stderr );
$check( 'the flagged image is in every mode', 'web,print' === $modes( $links( $post, $flag ) ), (string) $modes( $links( $post, $flag ) ) );
$check( 'the plain image is in web only', 'web' === $modes( $links( $post, $plain ) ), (string) $modes( $links( $post, $plain ) ) );
$check( 'the GPX file is in every mode', 'web,print' === $modes( $links( $post, $gpx ) ) );
$check( 'an unattached image gets nothing', null === $links( $post, $free ) );
$check( 'post_parent and the flag are untouched', $post === (int) get_post_field( 'post_parent', $flag ) && 'always' === get_post_meta( $flag, 'wpdfh.print', true ) );

$again = $run( '' );
$check( 'running again creates nothing', false !== strpos( $again->stdout, 'Done: 0 link(s) created' ), trim( $again->stdout ) );

$cleanup();
foreach ( triples_statements()->match( null, 'media/illustrated-by' ) as $statement ) {
	if ( ! isset( $before[ $statement->subject()->id() . ':' . $statement->object()->id() ] ) ) {
		triples_statements()->remove( $statement->subject(), 'media/illustrated-by', $statement->object() );
	}
}
echo "\n$pass passed, $fail failed\n";
