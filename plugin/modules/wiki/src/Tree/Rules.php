<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The rules of a branch of the tree.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * The rules of a branch (slice 500): a small set of settings put on **any page**, that applies to the page **and to all its descendants**.
 * The keys are the template a new page starts with and that shows the pages of the branch, a CSS class for the body, and a status line shown
 * on every page (the status of the information: fact, source, interpretation). The **effective** rule of a page, key by key, is the one of the
 * **nearest page, itself included**, that sets the key: a nearer page overrides a farther one. WordPress has no inheritance along the tree: the
 * ancestors are walked here, and the answer is kept for the request.
 */
final class Rules {
	/**
	 * Meta that holds the rules of a page.
	 */
	public const META = 'wiki_rules';

	/**
	 * The keys of a rule.
	 */
	public const KEYS = array( 'template', 'class', 'status' );

	/**
	 * Longest chain of ancestors that is walked (a loop of parents stops there).
	 */
	private const DEPTH = 64;

	/**
	 * Reads the rules stored on a page.
	 *
	 * @var callable
	 */
	private $get_meta;

	/**
	 * Returns the parent of a page.
	 *
	 * @var callable
	 */
	private $parent_of;

	/**
	 * What was computed in this request, by page.
	 *
	 * @var array<int, array<string, array{value: string, from: int}>>
	 */
	private $cache = array();

	/**
	 * Builds the service.
	 *
	 * @param callable|null $get_meta  Receives a page id; returns what is stored; default the meta `wiki_rules`.
	 * @param callable|null $parent_of Receives a page id; returns the id of its parent; default `wp_get_post_parent_id`.
	 */
	public function __construct( $get_meta = null, $parent_of = null ) {
		$this->get_meta  = $get_meta ?? static function ( $id ) {
			return get_post_meta( (int) $id, self::META, true );
		};
		$this->parent_of = $parent_of ?? static function ( $id ) {
			return (int) wp_get_post_parent_id( (int) $id );
		};
	}

	/**
	 * Cleans a value that was typed or sent: only the known keys, each cleaned, and what is empty left out. It is the sanitizing of the meta.
	 *
	 * - `template`: the slug of a template (lower case, letters, digits, hyphen, underscore and slash);
	 * - `class`: CSS classes separated by spaces (letters, digits, hyphen and underscore);
	 * - `status`: a short text, with no markup.
	 *
	 * @param mixed $value The value.
	 * @return array<string, string>
	 */
	public static function clean( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean    = array();
		$template = isset( $value['template'] ) && is_string( $value['template'] ) ? (string) preg_replace( '/[^a-z0-9_\/-]/', '', strtolower( trim( $value['template'] ) ) ) : '';
		$tokens   = isset( $value['class'] ) && is_string( $value['class'] ) ? preg_split( '/\s+/', trim( $value['class'] ) ) : array();
		$classes  = array_filter( array_map( static fn( $token ) => (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $token ), is_array( $tokens ) ? $tokens : array() ) );
		$status   = isset( $value['status'] ) && is_string( $value['status'] ) ? trim( sanitize_text_field( $value['status'] ) ) : '';

		if ( '' !== $template ) {
			$clean['template'] = $template;
		}

		if ( array() !== $classes ) {
			$clean['class'] = implode( ' ', $classes );
		}

		if ( '' !== $status ) {
			$clean['status'] = $status;
		}

		return $clean;
	}

	/**
	 * The rules set on a page itself.
	 *
	 * @param int $id The page.
	 * @return array<string, string>
	 */
	public function own( $id ) {
		return self::clean( ( $this->get_meta )( (int) $id ) );
	}

	/**
	 * The effective rules of a page: for each key that is set somewhere on the way up, the value and the page that sets it.
	 *
	 * @param int $id The page.
	 * @return array<string, array{value: string, from: int}>
	 */
	public function effective( $id ) {
		$id = (int) $id;

		if ( isset( $this->cache[ $id ] ) ) {
			return $this->cache[ $id ];
		}

		$found = array();
		$node  = $id;
		$left  = count( self::KEYS );

		for ( $depth = 0; $node > 0 && $depth < self::DEPTH && $left > 0; $depth++ ) {
			foreach ( $this->own( $node ) as $key => $value ) {
				if ( ! isset( $found[ $key ] ) ) {
					$found[ $key ] = array(
						'value' => $value,
						'from'  => $node,
					);
					--$left;
				}
			}

			$node = (int) ( $this->parent_of )( $node );
		}

		$this->cache[ $id ] = $found;

		return $found;
	}

	/**
	 * The effective value of a key for a page, or an empty string.
	 *
	 * @param int    $id  The page.
	 * @param string $key `template`, `class` or `status`.
	 * @return string
	 */
	public function value( $id, $key ) {
		return $this->effective( $id )[ $key ]['value'] ?? '';
	}

	/**
	 * Forgets what was computed (the rules of a page were saved).
	 *
	 * @return void
	 */
	public function forget() {
		$this->cache = array();
	}
}
