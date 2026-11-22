<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A source that only knows some posts.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Source;

defined( 'ABSPATH' ) || exit;

/**
 * A source that cannot answer for every post at every moment (slice 606: the photographs are known only while the page is read, or while some
 * of them wait for a place). The indexer asks it, and **leaves the mentions of the post as they are** when it does not know the post: an answer of
 * "nothing" would withdraw them.
 */
interface Partial {
	/**
	 * Whether the source can say what a post speaks of now.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public function knows( $post_id );
}
