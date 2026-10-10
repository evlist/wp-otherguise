<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the modes ask WordPress about stylesheet files of the Media Library.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Stylesheet;

defined( 'ABSPATH' ) || exit;

/**
 * The files of the Media Library that are stylesheets: describing one, listing them, and receiving an upload. The only class of the
 * module that asks WordPress about attachments (the tests put a double in its place).
 */
class StylesheetFiles {
	/**
	 * Size limit of an uploaded stylesheet, in bytes (filter `modes_stylesheet_max_bytes`).
	 */
	public const MAX_BYTES = 524288;

	/**
	 * Describes an attachment that is a stylesheet.
	 *
	 * @param int $id Attachment id.
	 * @return array{id: int, label: string, url: string, bytes: int, version: string}|null Null when it is not an attachment, not a
	 *                                                                                      stylesheet, or has no file.
	 */
	public function describe( $id ) {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}

		$path = get_attached_file( $post->ID );
		$url  = wp_get_attachment_url( $post->ID );

		if ( ! is_string( $path ) || ! is_string( $url ) || 'css' !== wp_check_filetype( $path )['ext'] ) {
			return null;
		}

		$time  = is_readable( $path ) ? filemtime( $path ) : false;
		$bytes = is_readable( $path ) ? filesize( $path ) : 0;

		return array(
			'id'      => (int) $post->ID,
			'label'   => '' !== (string) $post->post_title ? $post->post_title : basename( $path ),
			'url'     => $url,
			'bytes'   => false === $bytes ? 0 : (int) $bytes,
			'version' => false === $time ? '' : (string) $time,
		);
	}

	/**
	 * Lists the stylesheets of the Media Library.
	 *
	 * @return array<int, string> Label by attachment id, in the order of the ids.
	 */
	public function choices() {
		$ids     = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'text/css',
				'posts_per_page' => 200,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$choices = array();

		foreach ( $ids as $id ) {
			$file = $this->describe( (int) $id );

			if ( null !== $file ) {
				$choices[ $file['id'] ] = $file['label'];
			}
		}

		return $choices;
	}

	/**
	 * Receives the uploaded file of a form field into the Media Library. Only `.css` is accepted, whatever else the site allows.
	 *
	 * @param string $field Name of the file field of the posted form.
	 * @return int The id of the attachment.
	 * @throws StylesheetException When the file is refused or too large.
	 */
	public function upload( $field ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$only_css = static function () {
			return array( 'css' => 'text/css' );
		};

		add_filter( 'upload_mimes', $only_css, 99 );
		// A stylesheet is plain text: the content sniffing of the server may call it text/plain; the extension is what counts here.
		$keep_css = static function ( $data, $file, $filename ) {
			return 'css' === strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) )
				? array(
					'ext'             => 'css',
					'type'            => 'text/css',
					'proper_filename' => false,
				)
				: $data;
		};

		add_filter( 'wp_check_filetype_and_ext', $keep_css, 99, 3 );
		$id = media_handle_upload( $field, 0 );
		remove_filter( 'wp_check_filetype_and_ext', $keep_css, 99 );
		remove_filter( 'upload_mimes', $only_css, 99 );

		if ( is_wp_error( $id ) ) {
			StylesheetException::refuse( StylesheetException::UPLOAD_FAILED, 'The upload failed: ' . $id->get_error_message() );
		}

		/**
		 * Filters the size limit of an uploaded stylesheet.
		 *
		 * @param int $bytes Limit in bytes.
		 */
		$limit = (int) apply_filters( 'modes_stylesheet_max_bytes', self::MAX_BYTES );
		$path  = get_attached_file( $id );

		if ( is_string( $path ) && is_readable( $path ) && filesize( $path ) > $limit ) {
			wp_delete_attachment( $id, true );
			StylesheetException::refuse( StylesheetException::TOO_LARGE, 'The stylesheet is larger than ' . $limit . ' bytes.' );
		}

		return (int) $id;
	}
}
