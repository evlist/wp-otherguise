<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Test double of the parents of attachments.
 *
 * @package Otherguise
 */

use Otherguise\Media\Method\PostParents;

/**
 * The `post_parent` of attachments, kept in an array.
 */
final class Otherguise_Test_Post_Parents extends PostParents {
	/**
	 * Parent by attachment id.
	 *
	 * @var array<int, int>
	 */
	public $parents = array();

	/**
	 * Returns the parent.
	 *
	 * @param int $attachment_id Attachment.
	 * @return int
	 */
	public function get( $attachment_id ) {
		return $this->parents[ $attachment_id ] ?? 0;
	}

	/**
	 * Sets the parent.
	 *
	 * @param int $attachment_id Attachment.
	 * @param int $post_id       Post.
	 * @return bool
	 */
	public function set( $attachment_id, $post_id ) {
		$this->parents[ $attachment_id ] = $post_id;

		return true;
	}
}
