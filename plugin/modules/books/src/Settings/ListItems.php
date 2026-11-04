<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The template parts that the block that lists the units of a section may use for its items.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The template parts of the theme that an editor may choose as the **item** of the block `books/section-list` (slice 235). The list is set on the
 * screen **Otherguise → Books** (option `books_list_items`, the slugs) and completed by the filter `books_list_items`; the block keeps the
 * choice of the editor only if it is on this list **and** the theme still has the part, else it shows the title and the excerpt.
 */
final class ListItems {
	/**
	 * Name of the option.
	 */
	public const OPTION = 'books_list_items';

	/**
	 * Returns the template parts of the active theme: slug => title.
	 *
	 * @var callable
	 */
	private $parts;

	/**
	 * Builds the settings.
	 *
	 * @param callable|null $parts Returns the template parts of the active theme as slug => title; defaults to `get_block_templates()`.
	 */
	public function __construct( $parts = null ) {
		$this->parts = $parts ?? static function () {
			$found = array();

			foreach ( (array) get_block_templates( array(), 'wp_template_part' ) as $part ) {
				if ( is_object( $part ) && isset( $part->slug ) ) {
					$found[ (string) $part->slug ] = (string) ( $part->title ?? $part->slug );
				}
			}

			return $found;
		};
	}

	/**
	 * The slugs saved on the screen of the settings.
	 *
	 * @return string[]
	 */
	public function stored() {
		$value = get_option( self::OPTION, array() );

		return is_array( $value ) ? array_values( array_filter( $value, static fn( $slug ) => is_string( $slug ) && '' !== $slug ) ) : array();
	}

	/**
	 * The slugs that an editor may choose: the saved ones, then those of the filter `books_list_items`.
	 *
	 * @return string[]
	 */
	public function allowed() {
		$extra = apply_filters( 'books_list_items', array() );
		$extra = is_array( $extra ) ? array_filter( $extra, static fn( $slug ) => is_string( $slug ) && '' !== $slug ) : array();

		return array_values( array_unique( array_merge( $this->stored(), array_map( 'sanitize_title', $extra ) ) ) );
	}

	/**
	 * The template parts of the active theme.
	 *
	 * @return array<string, string> Slug => title.
	 */
	public function parts() {
		return ( $this->parts )();
	}

	/**
	 * Tells whether a slug may be the item of a list: it is on the list and the theme has the part.
	 *
	 * @param string $slug Slug of a template part.
	 * @return bool
	 */
	public function usable( $slug ) {
		return '' !== $slug && in_array( $slug, $this->allowed(), true ) && array_key_exists( $slug, $this->parts() );
	}

	/**
	 * The choices of the editor: the parts that are on the list and that the theme has.
	 *
	 * @return array<int, array{value:string,label:string}>
	 */
	public function choices() {
		$parts   = $this->parts();
		$choices = array();

		foreach ( $this->allowed() as $slug ) {
			if ( array_key_exists( $slug, $parts ) ) {
				$choices[] = array(
					'value' => $slug,
					'label' => '' !== $parts[ $slug ] ? $parts[ $slug ] : $slug,
				);
			}
		}

		return $choices;
	}

	/**
	 * Cleans what the screen of the settings posts: a list of slugs, once each.
	 *
	 * @param mixed $input The posted value.
	 * @return string[]
	 */
	public function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$slugs = array();

		foreach ( $input as $slug ) {
			$slug = is_string( $slug ) ? sanitize_title( $slug ) : '';

			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}
}
