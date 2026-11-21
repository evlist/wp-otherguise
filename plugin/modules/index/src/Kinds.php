<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The kinds of entries.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The kinds of entries (slice 603): data of the site, with a hierarchy that means *is a sort of* (a waterway is a place).
 *
 * - **The starter kinds** are created once and are **protected**: they cannot be deleted or moved, and their slug does not change; their label
 *   and CSS class can be edited. The kinds that a site adds are free.
 * - **Kinds given by code** (the filter `index_kinds`, `slug => label`) are added as roots when no kind of the site has the slug; they are
 *   read-only.
 * - The slug of a kind is its identifier: it is what the terms and the entries keep.
 *
 * The store is a static piece (the kinds are read everywhere, in loops): `use_store()` replaces it, for the tests.
 */
final class Kinds {
	public const OTHER     = 'other';
	public const MAX_DEPTH = 4;
	public const SEED      = 'index_kinds_seeded';
	public const VERSION   = '1';

	/**
	 * The store, or null for the one of WordPress.
	 *
	 * @var KindStore|null
	 */
	private static $store = null;

	/**
	 * The records read, until something is written.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $cache = null;

	/**
	 * Replaces the store (null for the one of WordPress).
	 *
	 * @param KindStore|null $store The store.
	 * @return void
	 */
	public static function use_store( ?KindStore $store ) {
		self::$store = $store;
		self::$cache = null;
	}

	/**
	 * The starter kinds, in the language of the site: slug => array( label, parent ).
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function starters() {
		return array(
			'place'    => array( __( 'Place', 'otherguise' ), '' ),
			'waterway' => array( __( 'Waterway', 'otherguise' ), 'place' ),
			'species'  => array( __( 'Species', 'otherguise' ), '' ),
			'route'    => array( __( 'Route', 'otherguise' ), '' ),
			'activity' => array( __( 'Activity', 'otherguise' ), '' ),
			'season'   => array( __( 'Season (a series)', 'otherguise' ), '' ),
			'person'   => array( __( 'Person', 'otherguise' ), '' ),
			'work'     => array( __( 'Work', 'otherguise' ), '' ),
			'product'  => array( __( 'Product', 'otherguise' ), '' ),
			'event'    => array( __( 'Event', 'otherguise' ), '' ),
			self::OTHER => array( __( 'Other', 'otherguise' ), '' ),
		);
	}

	/**
	 * Creates the starter kinds that are missing (a parent before its children). Cheap when it was done: one option.
	 *
	 * @param bool $force Check even when the option says it was done.
	 * @return int The number of kinds created.
	 */
	public static function seed( $force = false ) {
		if ( ! $force && self::VERSION === (string) get_option( self::SEED, '' ) ) {
			return 0;
		}

		$created = 0;
		$have    = self::store()->all();

		foreach ( self::starters() as $slug => $starter ) {
			if ( isset( $have[ $slug ] ) ) {
				continue;
			}

			if ( true === self::store()->add( $slug, $starter[0], $starter[1], $slug, true ) ) {
				++$created;
			}
		}

		self::$cache = null;
		update_option( self::SEED, self::VERSION );

		return $created;
	}

	/**
	 * Every kind, with the kinds of code: slug => record (`slug`, `label`, `parent`, `css`, `builtin`, `source`).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function records() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$records = array();

		foreach ( self::store()->all() as $slug => $record ) {
			$records[ $slug ] = $record + array( 'source' => 'data' );
		}

		$code = apply_filters( 'index_kinds', array() );

		foreach ( is_array( $code ) ? $code : array() as $slug => $label ) {
			if ( is_string( $slug ) && self::valid_slug( $slug ) && ! isset( $records[ $slug ] ) ) {
				$records[ $slug ] = array(
					'slug'    => $slug,
					'label'   => (string) $label,
					'parent'  => '',
					'css'     => $slug,
					'builtin' => false,
					'source'  => 'code',
				);
			}
		}

		self::$cache = $records;

		return $records;
	}

	/**
	 * The kinds as a tree, parents first, siblings by label: a list of records with their `depth`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function tree() {
		$records  = self::records();
		$children = array();

		foreach ( $records as $slug => $record ) {
			$parent                = isset( $records[ $record['parent'] ] ) ? $record['parent'] : '';
			$children[ $parent ][] = $slug;
		}

		$rows = array();
		$walk = static function ( $parent, $depth ) use ( &$walk, &$rows, $records, $children ) {
			$slugs = $children[ $parent ] ?? array();

			usort(
				$slugs,
				static fn( $a, $b ) => strcmp( Folding::key( $records[ $a ]['label'] ), Folding::key( $records[ $b ]['label'] ) )
			);

			foreach ( $slugs as $slug ) {
				$rows[] = $records[ $slug ] + array( 'depth' => $depth );

				if ( $depth < 20 ) {
					$walk( $slug, $depth + 1 );
				}
			}
		};

		$walk( '', 0 );

		return $rows;
	}

	/**
	 * The kinds for a selector: slug => label, in tree order (the label of a sub-kind is indented with dashes).
	 *
	 * @return array<string, string>
	 */
	public static function all() {
		$list = array();

		foreach ( self::tree() as $row ) {
			$list[ $row['slug'] ] = str_repeat( '— ', (int) $row['depth'] ) . $row['label'];
		}

		return $list;
	}

	/**
	 * Tells whether a slug is a kind.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function is_kind( $slug ) {
		return isset( self::records()[ (string) $slug ] );
	}

	/**
	 * The label of a kind (the slug when it is unknown).
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function label( $slug ) {
		return self::records()[ (string) $slug ]['label'] ?? (string) $slug;
	}

	/**
	 * The broader kinds of a kind, the nearest first.
	 *
	 * @param string $slug Slug.
	 * @return string[]
	 */
	public static function ancestors( $slug ) {
		$records   = self::records();
		$ancestors = array();
		$current   = (string) $slug;

		// The guard against a loop in the data: no more than 20 levels.
		for ( $level = 0; $level < 20 && isset( $records[ $current ] ) && '' !== $records[ $current ]['parent']; ++$level ) {
			$current = $records[ $current ]['parent'];

			if ( in_array( $current, $ancestors, true ) ) {
				break;
			}

			$ancestors[] = $current;
		}

		return $ancestors;
	}

	/**
	 * A kind and its sub-kinds, at any depth.
	 *
	 * @param string $slug Slug.
	 * @return string[]
	 */
	public static function descendants( $slug ) {
		$found = array();

		foreach ( array_keys( self::records() ) as $other ) {
			if ( self::is_a( $other, $slug ) ) {
				$found[] = $other;
			}
		}

		return $found;
	}

	/**
	 * Whether a kind is another or one of its sub-kinds.
	 *
	 * @param string $kind Kind.
	 * @param string $of   The broader kind.
	 * @return bool
	 */
	public static function is_a( $kind, $of ) {
		return (string) $kind === (string) $of || in_array( (string) $of, self::ancestors( $kind ), true );
	}

	/**
	 * Whether two kinds can be one entry: one is a sort of the other. A kind that is unknown, empty or `other` conflicts with nothing.
	 *
	 * @param string $a Kind.
	 * @param string $b Kind.
	 * @return bool
	 */
	public static function compatible( $a, $b ) {
		foreach ( array( $a, $b ) as $kind ) {
			if ( '' === (string) $kind || self::OTHER === $kind || ! self::is_kind( $kind ) ) {
				return true;
			}
		}

		return self::is_a( $a, $b ) || self::is_a( $b, $a );
	}

	/**
	 * The CSS classes of a kind: its own and those of its ancestors, the most specific first.
	 *
	 * @param string $slug Slug.
	 * @return string[] For example `index-kind-waterway`, `index-kind-place`.
	 */
	public static function classes( $slug ) {
		$records = self::records();
		$classes = array();

		foreach ( array_merge( array( (string) $slug ), self::ancestors( $slug ) ) as $kind ) {
			if ( isset( $records[ $kind ] ) ) {
				$classes[] = 'index-kind-' . sanitize_html_class( $records[ $kind ]['css'] );
			}
		}

		return $classes;
	}

	/**
	 * How many terms and entries have a kind.
	 *
	 * @param string $slug Slug.
	 * @return array{terms:int,entries:int}
	 */
	public static function usage( $slug ) {
		return self::store()->usage( (string) $slug );
	}

	/**
	 * Tells whether a slug is well formed.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function valid_slug( $slug ) {
		return 1 === preg_match( '/^[a-z][a-z0-9-]{0,30}$/', (string) $slug );
	}

	/**
	 * Adds a kind.
	 *
	 * @param string $slug   Slug.
	 * @param string $label  Label.
	 * @param string $parent Broader kind, '' for none.
	 * @param string $css    CSS suffix, the slug by default.
	 * @return true|string
	 */
	public static function add( $slug, $label, $parent = '', $css = '' ) {
		$slug    = (string) $slug;
		$parent  = (string) $parent;
		$records = self::records();

		if ( ! self::valid_slug( $slug ) ) {
			return 'A slug is made of lower-case letters, digits and hyphens, and starts with a letter (31 characters at most).';
		}

		if ( isset( $records[ $slug ] ) ) {
			return 'The kind "' . $slug . '" exists.';
		}

		if ( '' === trim( (string) $label ) ) {
			return 'A kind needs a label.';
		}

		$problem = self::check_parent( $slug, $parent, 0 );

		if ( true !== $problem ) {
			return $problem;
		}

		return self::written( self::store()->add( $slug, trim( (string) $label ), $parent, self::css_of( $css, $slug ), false ) );
	}

	/**
	 * Changes the label and the CSS class of a kind (also a starter kind).
	 *
	 * @param string      $slug  Slug.
	 * @param string      $label Label.
	 * @param string|null $css   CSS suffix, null to keep it.
	 * @return true|string
	 */
	public static function edit( $slug, $label, $css = null ) {
		$record = self::records()[ (string) $slug ] ?? null;

		if ( null === $record || 'data' !== $record['source'] ) {
			return null === $record ? 'No such kind: ' . $slug . '.' : 'A kind that comes from code is read-only.';
		}

		if ( '' === trim( (string) $label ) ) {
			return 'A kind needs a label.';
		}

		return self::written( self::store()->update( (string) $slug, trim( (string) $label ), null === $css ? $record['css'] : self::css_of( $css, (string) $slug ) ) );
	}

	/**
	 * Moves a kind under another (not a starter kind).
	 *
	 * @param string $slug   Slug.
	 * @param string $parent New broader kind, '' for none.
	 * @return true|string
	 */
	public static function move( $slug, $parent ) {
		$record = self::records()[ (string) $slug ] ?? null;

		if ( null === $record || 'data' !== $record['source'] ) {
			return null === $record ? 'No such kind: ' . $slug . '.' : 'A kind that comes from code is read-only.';
		}

		if ( $record['builtin'] ) {
			return 'The kind "' . $slug . '" is a starter kind: it cannot be moved.';
		}

		$problem = self::check_parent( (string) $slug, (string) $parent, self::height( (string) $slug ) );

		return true !== $problem ? $problem : self::written( self::store()->move( (string) $slug, (string) $parent ) );
	}

	/**
	 * Deletes a kind. A starter kind, a kind that has sub-kinds and a kind that is used (unless another kind takes over) are refused.
	 *
	 * @param string $slug     Slug.
	 * @param string $reassign The kind that takes over the terms and entries, '' for none.
	 * @return true|string
	 */
	public static function delete( $slug, $reassign = '' ) {
		$records = self::records();
		$record  = $records[ (string) $slug ] ?? null;

		if ( null === $record || 'data' !== $record['source'] ) {
			return null === $record ? 'No such kind: ' . $slug . '.' : 'A kind that comes from code is read-only.';
		}

		if ( $record['builtin'] ) {
			return 'The kind "' . $slug . '" is a starter kind: it cannot be deleted.';
		}

		foreach ( $records as $other ) {
			if ( $other['parent'] === $slug ) {
				return 'The kind "' . $slug . '" has sub-kinds: move or delete them first.';
			}
		}

		$usage = self::usage( (string) $slug );

		if ( $usage['terms'] + $usage['entries'] > 0 ) {
			if ( '' === (string) $reassign || (string) $reassign === $slug || ! isset( $records[ (string) $reassign ] ) ) {
				return 'The kind "' . $slug . '" is used by ' . $usage['terms'] . ' term(s) and ' . $usage['entries'] . ' entr' . ( 1 === $usage['entries'] ? 'y' : 'ies' ) . ': give another kind to take them over.';
			}

			self::store()->reassign( (string) $slug, (string) $reassign );
		}

		return self::written( self::store()->delete( (string) $slug ) );
	}

	/**
	 * The store.
	 *
	 * @return KindStore
	 */
	private static function store() {
		if ( null === self::$store ) {
			self::$store = new TermKindStore();
		}

		return self::$store;
	}

	/**
	 * Forgets what was read after a write.
	 *
	 * @param true|string $result The result of the store.
	 * @return true|string
	 */
	private static function written( $result ) {
		self::$cache = null;

		return $result;
	}

	/**
	 * The suffix of a CSS class: what was given, cleaned, or the slug.
	 *
	 * @param string $css  Given.
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function css_of( $css, $slug ) {
		$clean = trim( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $css ) ) );

		return '' === $clean ? $slug : $clean;
	}

	/**
	 * Checks a parent: it exists, it is not the kind or one of its descendants, and the tree stays within the depth.
	 *
	 * @param string $slug   The kind.
	 * @param string $parent The parent asked for.
	 * @param int    $height Levels below the kind that move with it (0 for a new kind).
	 * @return true|string
	 */
	private static function check_parent( $slug, $parent, $height ) {
		if ( '' === $parent ) {
			return true;
		}

		$records = self::records();

		if ( ! isset( $records[ $parent ] ) || 'data' !== $records[ $parent ]['source'] ) {
			return 'The parent "' . $parent . '" is not a kind of the site.';
		}

		if ( $parent === $slug || self::is_a( $parent, $slug ) ) {
			return 'A kind cannot go under itself or under one of its sub-kinds.';
		}

		if ( count( self::ancestors( $parent ) ) + 2 + $height > self::MAX_DEPTH ) {
			return 'The kinds go ' . self::MAX_DEPTH . ' levels deep at most.';
		}

		return true;
	}

	/**
	 * The number of levels below a kind.
	 *
	 * @param string $slug Slug.
	 * @return int
	 */
	private static function height( $slug ) {
		$height = 0;

		foreach ( self::records() as $other => $record ) {
			if ( $other !== $slug && self::is_a( $other, $slug ) ) {
				$height = max( $height, count( self::ancestors( $other ) ) - count( self::ancestors( $slug ) ) );
			}
		}

		return $height;
	}
}
