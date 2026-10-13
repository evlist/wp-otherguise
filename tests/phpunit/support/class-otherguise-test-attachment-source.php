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
	 * Returns a batch.
	 *
	 * @param int         $offset     Offset.
	 * @param int         $limit      Size.
	 * @param string      $meta_key   Meta of the criterion.
	 * @param string|null $meta_value Value of the criterion.
	 * @return array
	 */
	public function batch( $offset, $limit, $meta_key = self::FLAG, $meta_value = null ) {
		$this->criteria[] = array( $meta_key, $meta_value );

		return array_slice( $this->rows, $offset, $limit );
	}
}
