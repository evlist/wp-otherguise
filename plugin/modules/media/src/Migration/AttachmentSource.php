<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Where the migration reads the attachments of a site.
 *
 * @package Otherguise
 */

namespace Otherguise\Media\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, by batches, the attachments that have a parent, with their MIME type and whether the flag `wpdfh.print` of `wp-pdf-helper` is
 * set. Only reads: the order (by id) is stable while the migration does not change `post_parent`.
 */
class AttachmentSource {
	/**
	 * Meta key of the flag of `wp-pdf-helper`.
	 */
	public const FLAG = 'wpdfh.print';

	/**
	 * Returns a batch of attachments that have a parent.
	 *
	 * @param int $offset Number of attachments already read.
	 * @param int $limit  Size of the batch.
	 * @return array<int, array{id:int, parent:int, mime:string, flagged:bool}>
	 */
	public function batch( $offset, $limit ) {
		$ids = get_posts(
			array(
				'post_type'           => 'attachment',
				'post_status'         => 'any',
				'post_parent__not_in' => array( 0 ),
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'offset'              => (int) $offset,
				'posts_per_page'      => (int) $limit,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'suppress_filters'    => true,
			)
		);
		$rows = array();

		foreach ( $ids as $id ) {
			$rows[] = array(
				'id'      => (int) $id,
				'parent'  => (int) wp_get_post_parent_id( $id ),
				'mime'    => (string) get_post_mime_type( $id ),
				'flagged' => '' !== (string) get_post_meta( $id, self::FLAG, true ),
			);
		}

		return $rows;
	}
}
