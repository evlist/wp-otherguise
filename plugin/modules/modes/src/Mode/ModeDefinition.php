<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A mode.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Mode;

defined( 'ABSPATH' ) || exit;

/**
 * A way of presenting the content: web, print, book... It has a slug (the value of `?mode=`), a label, and optionally an alias: a query
 * string key that selects the mode by its presence, so that existing links such as `?print` keep working.
 */
final class ModeDefinition {
	/**
	 * Pattern of a slug and of an alias.
	 */
	public const SLUG_PATTERN = '/^[a-z][a-z0-9_]{0,19}\z/';

	/**
	 * Query string keys that an alias cannot take: `mode` itself and the query variables WordPress understands.
	 */
	private const RESERVED_ALIASES = array(
		'mode',
		'p',
		'page_id',
		'page',
		'paged',
		'cpage',
		's',
		'm',
		'w',
		'cat',
		'tag',
		'name',
		'pagename',
		'author',
		'author_name',
		'attachment',
		'attachment_id',
		'feed',
		'embed',
		'preview',
		'post_type',
		'taxonomy',
		'term',
		'category_name',
		'year',
		'monthnum',
		'day',
		'hour',
		'minute',
		'second',
		'order',
		'orderby',
		'error',
		'lang',
		'robots',
	);

	/**
	 * Slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Alias, or null.
	 *
	 * @var string|null
	 */
	private $alias;

	/**
	 * Builds a mode.
	 *
	 * @param string      $slug  Slug: lower case letters, digits and underscores, 20 characters at most, starting with a letter.
	 * @param string      $label Label.
	 * @param string|null $alias Query string key that selects the mode by its presence, or null.
	 * @throws \InvalidArgumentException When the slug, the label or the alias is invalid.
	 */
	public function __construct( $slug, $label, $alias = null ) {
		if ( ! is_string( $slug ) || 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {
			throw new \InvalidArgumentException( 'A mode slug is made of lower case letters, digits and underscores, starts with a letter and has 20 characters at most.' );
		}

		if ( '' === trim( (string) $label ) ) {
			throw new \InvalidArgumentException( 'A mode needs a label.' );
		}

		if ( null !== $alias && ( ! is_string( $alias ) || 1 !== preg_match( self::SLUG_PATTERN, $alias ) || in_array( $alias, self::RESERVED_ALIASES, true ) ) ) {
			throw new \InvalidArgumentException( 'The alias of a mode must look like a slug and must not be a query variable of WordPress.' );
		}

		$this->slug  = $slug;
		$this->label = (string) $label;
		$this->alias = $alias;
	}

	/**
	 * Returns the slug.
	 *
	 * @return string
	 */
	public function slug() {
		return $this->slug;
	}

	/**
	 * Returns the label.
	 *
	 * @return string
	 */
	public function label() {
		return $this->label;
	}

	/**
	 * Returns the alias.
	 *
	 * @return string|null
	 */
	public function alias() {
		return $this->alias;
	}
}
