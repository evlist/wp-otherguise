<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Public functions of the Access module.
 *
 * @package Otherguise
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'access_url' ) ) {
	/**
	 * Returns the private link of a post: the address of the site with the token of the post, which shows the post to someone who is not
	 * logged in whatever its status (draft, pending, private, future). Nothing else is opened by it.
	 *
	 * @param int|object $post      Id of the post, or the post.
	 * @param array      $arguments `create`: make the link when the post has none (false by default); `mode`: the slug of a mode to open
	 *                              the page in.
	 * @return string|false The address, false when the post has no link (or cannot have one) or the module is not enabled.
	 */
	function access_url( $post, array $arguments = array() ) {
		$module = \Otherguise\Core\Modules::get( 'access' );

		if ( ! $module instanceof \Otherguise\Access\Module ) {
			return false;
		}

		$id    = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post;
		$links = $module->links();

		if ( ! empty( $arguments['create'] ) && $links->eligible( get_post( $id ) ) ) {
			$links->create( $id );
		}

		return $links->address( $id, $arguments );
	}
}
