<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A source of candidates.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * A source says what a post speaks of (slice 600: the categories and the tags; slice 601 adds the reading of a page).
 */
interface Source {
	/**
	 * The slug of the source, kept on every mention it makes.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Whether what it says is sure: a new entry is then approved at once.
	 *
	 * @return bool
	 */
	public function is_sure();

	/**
	 * What the post speaks of.
	 *
	 * @param int $post_id Post.
	 * @return Candidate[]
	 */
	public function candidates( $post_id );
}
