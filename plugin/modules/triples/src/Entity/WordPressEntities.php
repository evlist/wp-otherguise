<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Existence checks, recognizers and loaders of the entity types provided by WordPress.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * The only place of the module where the built-in entity types meet the WordPress API.
 *
 * `EntityTypeRegistry::with_builtins()` takes the result of `behaviors()`, so that the registries and their tests stay free of WordPress
 * calls. Not tried on a real WordPress site yet: the tests use stubs of `WP_Post`, `WP_Term`, `WP_User`, `get_post()`, `get_term()` and
 * `get_userdata()`.
 */
final class WordPressEntities {
	/**
	 * Returns the behaviors of the built-in types, by slug: `exists`, `identify` and `load`.
	 *
	 * A post that is a media item is an `attachment`, any other post is a `post`; the two recognizers are exclusive.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function behaviors() {
		$behaviors = array();

		foreach ( array(
			'post'       => false,
			'attachment' => true,
		) as $slug => $attachment ) {
			$load = static function ( $id ) use ( $attachment ) {
				$post = get_post( (int) $id );

				return $post instanceof \WP_Post && ( 'attachment' === $post->post_type ) === $attachment ? $post : null;
			};

			$behaviors[ $slug ] = array(
				'exists'   => static function ( $id ) use ( $load ) {
					return null !== $load( $id );
				},
				'identify' => static function ( $value ) use ( $attachment ) {
					return $value instanceof \WP_Post && ( 'attachment' === $value->post_type ) === $attachment ? $value->ID : null;
				},
				'load'     => $load,
			);
		}

		$behaviors['term'] = array(
			'exists'   => static function ( $id ) {
				return get_term( (int) $id ) instanceof \WP_Term;
			},
			'identify' => static function ( $value ) {
				return $value instanceof \WP_Term ? $value->term_id : null;
			},
			'load'     => static function ( $id ) {
				$term = get_term( (int) $id );

				return $term instanceof \WP_Term ? $term : null;
			},
		);

		$behaviors['user'] = array(
			'exists'   => static function ( $id ) {
				return get_userdata( (int) $id ) instanceof \WP_User;
			},
			'identify' => static function ( $value ) {
				return $value instanceof \WP_User && $value->ID > 0 ? $value->ID : null;
			},
			'load'     => static function ( $id ) {
				$user = get_userdata( (int) $id );

				return $user instanceof \WP_User ? $user : null;
			},
		);

		return $behaviors;
	}
}
