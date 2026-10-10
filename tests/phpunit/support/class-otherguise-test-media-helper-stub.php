<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A stand-in for the interface that Media Helper declares (contract version 1, slice 042 of that plugin).
 *
 * @package Otherguise
 */

namespace WP_Media_Helper\Attachment {

if ( ! interface_exists( __NAMESPACE__ . '\\Method' ) ) {
	/**
	 * How a media item is attached to a post, as Media Helper declares it. Copied from its `Attachment/Method.php`; if the contract
	 * changes there, this file and the method of the module change with it.
	 */
	interface Method {
		/**
		 * Identifier.
		 *
		 * @return string
		 */
		public function id(): string;

		/**
		 * Name.
		 *
		 * @return string
		 */
		public function label(): string;

		/**
		 * Capabilities and fields.
		 *
		 * @return array
		 */
		public function capabilities(): array;

		/**
		 * How each attachment stands for a post.
		 *
		 * @param int[] $attachment_ids Attachments.
		 * @param int   $post_id        Post.
		 * @return array
		 */
		public function describe( array $attachment_ids, int $post_id ): array;

		/**
		 * Links an attachment to a post.
		 *
		 * @param int   $attachment_id Attachment.
		 * @param int   $post_id       Post.
		 * @param array $data         Data.
		 * @return true|\WP_Error
		 */
		public function attach( int $attachment_id, int $post_id, array $data );

		/**
		 * Removes a link.
		 *
		 * @param int $attachment_id Attachment.
		 * @param int $post_id       Post.
		 * @return true|\WP_Error
		 */
		public function detach( int $attachment_id, int $post_id );

		/**
		 * Changes the data of a link.
		 *
		 * @param int   $attachment_id Attachment.
		 * @param int   $post_id       Post.
		 * @param array $data         Data.
		 * @return true|\WP_Error
		 */
		public function update( int $attachment_id, int $post_id, array $data );

		/**
		 * The attachments of a post.
		 *
		 * @param int   $post_id Post.
		 * @param array $args   Arguments.
		 * @return int[]
		 */
		public function attached( int $post_id, array $args ): array;

		/**
		 * The posts of each attachment.
		 *
		 * @param int[] $attachment_ids Attachments.
		 * @return array
		 */
		public function posts_of( array $attachment_ids ): array;

		/**
		 * Guard for removing an attachment.
		 *
		 * @param int    $attachment_id Attachment.
		 * @param string $context      `remove` or `trash`.
		 * @return true|\WP_Error
		 */
		public function may_remove( int $attachment_id, string $context = 'remove' );
	}
}
}
