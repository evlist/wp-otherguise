<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 210: the attachment method of Otherguise through the real Media Helper, on a scratch WordPress site (`wp eval-file run.php`).
 * Needs Media Helper active (contract 1), Otherguise active and the modes enabled. It creates posts and attachments (without files) and
 * removes them. Never run it on a site that matters.
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- run on a scratch WordPress, outside the quality rules of the plugin.

use WP_Media_Helper\Attachment\Methods;

$pass = 0;
$fail = 0;

$check = static function ( $label, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	$ok ? ++$pass : ++$fail;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( '' !== $detail ? '  [' . $detail . ']' : '' ) . "\n";
};

$cleanup = static function () {
	foreach ( get_posts( array( 'post_type' => array( 'post', 'attachment' ), 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
		if ( 0 === strpos( $p->post_title, 'og210' ) ) {
			wp_delete_post( $p->ID, true );
		}
	}
	delete_option( 'wp_media_helper_attachment_method' );
};
$cleanup();
$baseline = count( triples_statements()->match( null, 'media/illustrated-by' ) );

wp_set_current_user( 1 );

$check( 'Media Helper offers the contract 1', defined( 'WP_MEDIA_HELPER_CONTRACT' ) && 1 === WP_MEDIA_HELPER_CONTRACT );
$check( 'the method of Otherguise is registered next to the native one', array( 'native', 'otherguise' ) === array_keys( Methods::all() ), implode( ',', array_keys( Methods::all() ) ) );
$check( 'by default the native method is in use', 'native' === Methods::active( 0 )->id() );

update_option( 'wp_media_helper_attachment_method', 'otherguise' );

$one = wp_insert_post( array( 'post_title' => 'og210 one', 'post_status' => 'publish' ) );
$two = wp_insert_post( array( 'post_title' => 'og210 two', 'post_status' => 'publish' ) );
$img = wp_insert_attachment( array( 'post_title' => 'og210 image', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), 'og210/image.jpg' );
$gpx = wp_insert_attachment( array( 'post_title' => 'og210 gpx', 'post_mime_type' => 'application/gpx+xml', 'post_status' => 'inherit' ), 'og210/track.gpx' );
$png = wp_insert_attachment( array( 'post_title' => 'og210 png', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), 'og210/other.png' );

$method = Methods::active( $one );
$check( 'the method of Otherguise is the one in use once chosen', 'otherguise' === $method->id() && $method->capabilities()['multiple_posts'] );
$fields = $method->capabilities()['fields'];
$check( 'it declares the fields modes (every mode written out) and position', array( 'modes', 'position' ) === array_column( $fields, 'key' ) && array( 'web', 'print' ) === $fields[0]['default'], wp_json_encode( $fields[0]['default'] ) );

// Attach.
$check( 'attach to the first post, with the default modes', true === $method->attach( $img, $one, array( 'modes' => array( 'web', 'print' ), 'position' => null ) ) );
$check( 'the first post is the primary parent', $one === (int) get_post_field( 'post_parent', $img ) );
$check( 'attach to a second post, in print only, at a rank', true === $method->attach( $img, $two, array( 'modes' => array( 'print' ), 'position' => 2 ) ) );
$check( 'the primary parent did not move', $one === (int) get_post_field( 'post_parent', $img ) );
$method->attach( $gpx, $one, array( 'modes' => array( 'web' ) ) );
$method->attach( $png, $one, array( 'modes' => array( 'print' ) ) );
$method->attach( $png, $two, array( 'modes' => array( 'web' ) ) );

// Reading, through the public function of Media Helper.
$ids = static fn( $post, $args = array() ) => wp_media_helper_get_attached_media( $post, $args );
$check( 'wp_media_helper_get_attached_media: web', array( $img, $gpx ) === $ids( $one, array( 'context' => 'web' ) ), wp_json_encode( $ids( $one, array( 'context' => 'web' ) ) ) );
$check( 'wp_media_helper_get_attached_media: print', array( $img, $png ) === $ids( $one, array( 'context' => 'print' ) ) );
$check( 'wp_media_helper_get_attached_media: no context, nothing filtered', array( $img, $gpx, $png ) === $ids( $one ) );
$check( 'wp_media_helper_get_attached_media: a family of MIME types', array( $img, $png ) === $ids( $one, array( 'mime_type' => 'image' ) ) );
$check( 'wp_media_helper_get_attached_media: a whole MIME type', array( $gpx ) === $ids( $one, array( 'mime_type' => 'application/gpx+xml' ) ) );
$check( 'the second post has its own links', array( $png ) === $ids( $two, array( 'context' => 'web' ) ) && array( $img ) === $ids( $two, array( 'context' => 'print' ) ) );

$posts = $method->posts_of( array( $img, $gpx ) );
$check( 'posts_of: every post of each attachment', array( $img => array( $one, $two ), $gpx => array( $one ) ) === $posts, wp_json_encode( $posts ) );
$described = $method->describe( array( $img ), $two )[ $img ];
$check( 'describe: here, elsewhere, nothing blocks, the data of the link', $described['attached_here'] && array( $one ) === $described['elsewhere'] && null === $described['protected'] && array( 'modes' => array( 'print' ), 'position' => 2 ) === $described['data'], wp_json_encode( $described ) );

// Update.
$check( 'update the rank and the modes of a link: it is now shown in web too, and first', true === $method->update( $img, $two, array( 'modes' => array( 'web', 'print' ), 'position' => 1 ) ) && array( $img, $png ) === $ids( $two, array( 'context' => 'web' ) ), wp_json_encode( $ids( $two, array( 'context' => 'web' ) ) ) );
$check( 'a refused value stores nothing', 'invalid_data' === $method->update( $img, $two, array( 'modes' => array( 'book' ) ) )->get_error_code() );

// Remove from the library, detach.
$check( 'remove from the library is refused while linked', is_wp_error( $method->may_remove( $img ) ) && true === $method->may_remove( $img, 'trash' ) );
$check( 'detach from the primary parent', true === $method->detach( $img, $one ) );
$check( 'the primary parent moved to the post that remains', $two === (int) get_post_field( 'post_parent', $img ) );
$check( 'detach from the last post clears the parent', true === $method->detach( $img, $two ) && 0 === (int) get_post_field( 'post_parent', $img ) );
$check( 'the item can then be removed from the library', true === $method->may_remove( $img ) );

// Deleting an attachment removes its links.
$before = count( triples_statements()->match( null, 'media/illustrated-by' ) );
wp_delete_attachment( $png, true );
$check( 'deleting an attachment removes its links', count( triples_statements()->match( null, 'media/illustrated-by' ) ) === $before - 2, ( $before ) . ' -> ' . count( triples_statements()->match( null, 'media/illustrated-by' ) ) );

// The native method again.
update_option( 'wp_media_helper_attachment_method', 'native' );
$check( 'back on the native method', 'native' === Methods::active( $one )->id() );

$cleanup();
$check( 'cleaned up: no link of this test is left', count( triples_statements()->match( null, 'media/illustrated-by' ) ) === $baseline );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
