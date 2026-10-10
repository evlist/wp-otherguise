<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The `post_parent` of attachments.
 *
 * @package Otherguise
 */

namespace Otherguise\Media\Method;

defined( 'ABSPATH' ) || exit;

/**
 * The only class of the module that reads and writes `post_parent`, which WordPress and other plugins still read: it stays the primary
 * parent of an attachment, the first post it was attached to.
 */
class PostParents {
	/**
	 * Returns the parent of an attachment.
	 *
	 * @param int $attachment_id Attachment.
	 * @return int 0 when it has none.
	 */
	public function get( $attachment_id ) {
		return (int) get_post_field( 'post_parent', $attachment_id );
	}

	/**
	 * Sets the parent of an attachment.
	 *
	 * @param int $attachment_id Attachment.
	 * @param int $post_id       Post, 0 for none.
	 * @return bool
	 */
	public function set( $attachment_id, $post_id ) {
		$updated = wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_parent' => $post_id,
			),
			true
		);

		return ! is_wp_error( $updated ) && ! empty( $updated );
	}
}
