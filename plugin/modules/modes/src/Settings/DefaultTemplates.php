<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The template that new posts of a type start with.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Settings;

use Otherguise\Modes\Template\TemplateLookup;

defined( 'ABSPATH' ) || exit;

/**
 * The option `modes_default_templates`: for a type of content, the slug of the template that a new post of that type starts with (the
 * value of its `_wp_page_template`). It is read when the editor opens a new post; nothing is changed for the posts that exist.
 */
final class DefaultTemplates {
	/**
	 * Name of the option.
	 */
	public const OPTION = 'modes_default_templates';

	/**
	 * Lookup of templates.
	 *
	 * @var TemplateLookup
	 */
	private $lookup;

	/**
	 * Builds the setting.
	 *
	 * @param TemplateLookup $lookup Lookup of templates.
	 */
	public function __construct( TemplateLookup $lookup ) {
		$this->lookup = $lookup;
	}

	/**
	 * Returns the settings: the slug of the template by type of content.
	 *
	 * @return array<string, string>
	 */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		$all    = array();

		foreach ( is_array( $stored ) ? $stored : array() as $type => $slug ) {
			if ( is_string( $type ) && is_string( $slug ) && self::is_slug( $slug ) ) {
				$all[ $type ] = $slug;
			}
		}

		return $all;
	}

	/**
	 * Returns the slug of the template of a type of content.
	 *
	 * @param string $post_type Type of content.
	 * @return string Empty when the type has none.
	 */
	public function for_type( $post_type ) {
		return $this->all()[ $post_type ] ?? '';
	}

	/**
	 * Tells whether a template of the active theme has this slug.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function exists( $slug ) {
		return self::is_slug( $slug )
			&& ( null !== $this->lookup->block_template( $this->lookup->stylesheet() . '//' . $slug, 'wp_template' ) || $this->lookup->php_template_exists( $slug ) );
	}

	/**
	 * Sanitizes the option when it is saved: only the types of content that exist, only templates that exist; an empty choice removes the
	 * type.
	 *
	 * @param mixed $input Value posted.
	 * @return array<string, string>
	 */
	public function sanitize( $input ) {
		$types = $this->lookup->post_types();
		$clean = array();

		foreach ( is_array( $input ) ? $input : array() as $type => $slug ) {
			if ( is_string( $type ) && isset( $types[ $type ] ) && is_string( $slug ) && $this->exists( $slug ) ) {
				$clean[ $type ] = $slug;
			}
		}

		return $clean;
	}

	/**
	 * Tells whether a string has the shape of a template slug.
	 *
	 * @param string $slug Value.
	 * @return bool
	 */
	private static function is_slug( $slug ) {
		return 1 === preg_match( '/^[A-Za-z0-9_.\-]{1,191}\z/', $slug );
	}
}
