<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the sections need to know about the posts.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Section;

defined( 'ABSPATH' ) || exit;

/**
 * The posts as the sections see them (slice 223): the WordPress implementation is `WordPressPostStore`, the tests use a double.
 */
interface PostStore {
	/**
	 * The post types that can be a section and can be put in one.
	 *
	 * @return string[]
	 */
	public function types();

	/**
	 * Describes a post, or returns null when it does not exist. With `$detailed` the description also has `view` (the permalink, or the
	 * preview link of a post that is not published).
	 *
	 * @param int  $id       Id of the post.
	 * @param bool $detailed Whether to add `view`.
	 * @return array{id:int,title:string,status:string,type:string,date:string,edit:string,view?:string}|null
	 */
	public function info( $id, $detailed = false );

	/**
	 * Tells whether the user may edit a post.
	 *
	 * @param int $id Id of the post.
	 * @return bool
	 */
	public function can_edit( $id );

	/**
	 * Tells whether the user may read a post.
	 *
	 * @param int $id Id of the post.
	 * @return bool
	 */
	public function can_read( $id );

	/**
	 * Loads several posts at once, before `info()` is asked for each of them.
	 *
	 * @param int[] $ids Ids.
	 * @return void
	 */
	public function prime( array $ids );

	/**
	 * The addresses of the featured images of posts (the size `medium`), by post id; an empty string for a post that has none. Asking a
	 * size that does not exist yet can make it, which is why this is not part of `info()`: the list of a section must not wait for it.
	 *
	 * @param int[] $ids Ids of the posts.
	 * @return array<int,string>
	 */
	public function thumbs( array $ids );

	/**
	 * Searches among given posts (the sections), the most recently modified first.
	 *
	 * @param int[]  $ids      Ids of the posts to search among.
	 * @param string $text     Text to search, possibly empty.
	 * @param int    $page     Page, from 1.
	 * @param int    $per_page Results per page.
	 * @return array{ids:int[],total:int}
	 */
	public function search_among( array $ids, $text, $page, $per_page );

	/**
	 * Searches the posts that can be added to a section, the oldest first.
	 *
	 * Arguments: `search` (text), `after` and `before` (dates `Y-m-d`, inclusive, or empty), `statuses` (post statuses), `page`, `per_page`.
	 *
	 * @param array $arguments Criteria.
	 * @return array{ids:int[],total:int}
	 */
	public function candidates( array $arguments );
}
