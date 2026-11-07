<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The meta that holds the rules of a branch.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the meta `wiki_rules` of the pages (slice 500): an object with the keys of `Rules::KEYS`, shown in REST (the panel of the block
 * editor saves it with the page), cleaned by `Rules::clean()`. Who may change it is the capability `edit_others_pages` of the pages, changed
 * by the filter `wiki_rules_capability` (the rules decide how a whole branch looks: not the right of every author).
 */
final class RulesMeta {
	/**
	 * The capability that changes the rules of a branch. Filter `wiki_rules_capability`.
	 *
	 * @return string
	 */
	public static function capability() {
		$capability = apply_filters( 'wiki_rules_capability', 'edit_others_pages' );

		return is_string( $capability ) && '' !== $capability ? $capability : 'edit_others_pages';
	}

	/**
	 * Registers the meta. Action `init`.
	 *
	 * @return void
	 */
	public function register() {
		register_post_meta(
			Nodes::TYPE,
			Rules::META,
			array(
				'type'              => 'object',
				'single'            => true,
				'default'           => array(),
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'properties'           => array(
							'template' => array( 'type' => 'string' ),
							'class'    => array( 'type' => 'string' ),
							'status'   => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'sanitize_callback' => array( Rules::class, 'clean' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					unset( $allowed, $meta_key );

					return current_user_can( 'edit_post', (int) $post_id ) && current_user_can( self::capability() );
				},
			)
		);
	}
}
