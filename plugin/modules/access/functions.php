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
	 * @return string|false The address, false when the post has no link (or cannot have one) or the private links are switched off (slice 401).
	 */
	function access_url( $post, array $arguments = array() ) {
		$module = \Otherguise\Core\Modules::get( 'access' );

		if ( ! $module instanceof \Otherguise\Access\Module ) {
			return false;
		}

		if ( ! $module->settings()->links_enabled() ) {
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

if ( ! function_exists( 'access_is_unlisted' ) ) {
	/**
	 * Tells whether a post is unlisted (slice 402): published or not, its address works but it is left out of the lists of the site.
	 *
	 * @param int|object $post Id of the post, or the post.
	 * @return bool False when the module is not there; the state alone, whether the setting that applies it is on or not.
	 */
	function access_is_unlisted( $post ) {
		$module = \Otherguise\Core\Modules::get( 'access' );

		if ( ! $module instanceof \Otherguise\Access\Module ) {
			return false;
		}

		return $module->unlisted()->is_unlisted( is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post );
	}
}

if ( ! function_exists( 'access_unlisted_path' ) ) {
	/**
	 * The REST path under which the unlisted state of a post is read and changed (`<path>/<id>/unlisted`), for an editor panel of another
	 * module that offers the switch (slice 402).
	 *
	 * @return string|false False when the module is not there or the unlisted state is switched off.
	 */
	function access_unlisted_path() {
		$module = \Otherguise\Core\Modules::get( 'access' );

		if ( ! $module instanceof \Otherguise\Access\Module || ! $module->settings()->unlisted_enabled() ) {
			return false;
		}

		return '/' . \Otherguise\Access\Rest\LinkRoutes::REST_NAMESPACE . '/posts';
	}
}
