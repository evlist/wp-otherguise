<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Test double of the source of attachments of the migration.
 *
 * @package Otherguise
 */

use Otherguise\Media\Migration\AttachmentSource;

/**
 * Source of attachments kept in an array.
 */
final class Otherguise_Test_Attachment_Source extends AttachmentSource {
	/**
	 * Rows.
	 *
	 * @var array
	 */
	public $rows = array();

	/**
	 * The criterion that each batch was asked with: array( key, value ).
	 *
	 * @var array
	 */
	public $criteria = array();

	/**
	 * The list of posts that each batch was asked with.
	 *
	 * @var array
	 */
	public $posts = array();

	/**
	 * Returns a batch.
	 *
	 * @param int         $offset     Offset.
	 * @param int         $limit      Size.
	 * @param string      $meta_key   Meta of the criterion.
	 * @param string|null $meta_value Value of the criterion.
	 * @param int[]       $post_ids   Posts asked for; every post when empty.
	 * @return array
	 */
	public function batch( $offset, $limit, $meta_key = self::FLAG, $meta_value = null, array $post_ids = array() ) {
		$this->criteria[] = array( $meta_key, $meta_value );
		$this->posts[]    = $post_ids;
		$rows             = array() === $post_ids ? $this->rows : array_values(
			array_filter(
				$this->rows,
				static fn( $row ) => in_array( $row['parent'], $post_ids, true )
			)
		);

		return array_slice( $rows, $offset, $limit );
	}
}
