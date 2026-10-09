<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Scratch site: empties the table of the statements and creates a small, realistic set (a book, two days, four photos with their modes and a position).
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- run on a scratch WordPress, outside the quality rules of the plugin.
// Scratch: empties the table and creates a small, realistic set of statements.
use Otherguise\Triples\Module;
use Otherguise\Triples\Entity\EntityRef;
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->prefix}triples_statements" );
foreach ( get_posts( array( 'post_type' => array( 'post', 'attachment' ), 'numberposts' => -1, 'post_status' => 'any', 'meta_key' => '_og_seed' ) ) as $p ) { wp_delete_post( $p->ID, true ); }
$module = new Module();
$s      = $module->statements();
$mk = fn( $title ) => get_post( wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'meta_input' => array( '_og_seed' => 1 ) ) ) );
$ph = fn( $title, $parent ) => get_post( wp_insert_attachment( array( 'post_title' => $title, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'meta_input' => array( '_og_seed' => 1 ) ), $title, $parent ) );
$book = $mk( 'Book 2026' ); $day1 = $mk( 'Day 1 — Col du Tourmalet' ); $day2 = $mk( 'Day 2 — Col d’Aubisque' );
$a = $ph( 'a.jpg', $day1->ID ); $b = $ph( 'b.jpg', $day1->ID ); $c = $ph( 'c.jpg', $day2->ID ); $d = $ph( 'd.jpg', $day2->ID );
foreach ( array( $day1, $day2 ) as $day ) { $s->triple( $book, 'books/contains', $day ); }
$l = array();
foreach ( array( array( $day1, $a, array( 'web', 'print' ) ), array( $day1, $b, array( 'web' ) ), array( $day2, $c, array( 'print' ) ), array( $day2, $d, array() ) ) as list( $post, $photo, $modes ) ) {
	$l[ $photo->ID ] = $s->triple( $post, 'media/illustrated-by', $photo );
	foreach ( $modes as $m ) { $s->triple( $l[ $photo->ID ], 'modes/mode', new EntityRef( 'mode', $m ) ); }
}
$s->triple( $l[ $a->ID ], 'triples/position', 1 );
echo wp_json_encode( array( 'book' => $book->ID, 'day1' => $day1->ID, 'day2' => $day2->ID, 'photos' => array( $a->ID, $b->ID, $c->ID, $d->ID ) ) ), "\n";
echo 'statements: ', count( $s->match() ), "\n";
