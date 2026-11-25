<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The sources of the mentions, as a hierarchy.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The sources of the mentions (slice 605): a **fixed list in the code** (each harvester declares its own), as **paths**: `post/tag`, `image/address`,
 * `trace/start`. The parent of a source is the path without its last part; `is_a()` and `descendants()` work as they do for the kinds, so that
 * `source:post` stands for `post/category`, `post/tag`, `post/link`, `post/mark`. The filter `index_sources_registry` adds the sources of a plugin
 * of another author. A source may declare a **default visibility** (the modes in which what it finds is shown); with none, every mode.
 */
final class Sources {
	public const CATEGORY = 'post/category';
	public const TAG      = 'post/tag';
	public const LINK     = 'post/link';
	public const MARK     = 'post/mark';
	public const HAND     = 'hand';

	/**
	 * The sources: path => array( label, visibility ), the visibility being a list of modes, empty for every mode.
	 *
	 * @return array<string, array{label:string,visible:string[]}>
	 */
	public static function registry() {
		$registry = array(
			'post'          => array( __( 'A post', 'otherguise' ), array() ),
			self::CATEGORY  => array( __( 'A category of the post', 'otherguise' ), array() ),
			self::TAG       => array( __( 'A tag of the post', 'otherguise' ), array() ),
			self::LINK      => array( __( 'A link in the contents', 'otherguise' ), array() ),
			self::MARK      => array( __( 'A mark in the text', 'otherguise' ), array() ),
			'image'         => array( __( 'An image shown in a post', 'otherguise' ), array() ),
			'image/address' => array( __( 'Where an image was taken', 'otherguise' ), array() ),
			'image/title'   => array( __( 'The title of an image', 'otherguise' ), array() ),
			'image/category' => array( __( 'A category of an image', 'otherguise' ), array() ),
			'image/tag'     => array( __( 'A tag of an image', 'otherguise' ), array() ),
			'trace'         => array( __( 'A GPX track named in a post', 'otherguise' ), array() ),
			'trace/start'   => array( __( 'The start of a track', 'otherguise' ), array() ),
			'trace/end'     => array( __( 'The end of a track', 'otherguise' ), array() ),
			self::MARK . '/whobird' => array( __( 'An observation of whoBIRD (a mark that says so)', 'otherguise' ), array() ),
			self::HAND      => array( __( 'Made by a person', 'otherguise' ), array() ),
		);

		$extra = apply_filters( 'index_sources_registry', array() );

		foreach ( is_array( $extra ) ? $extra : array() as $path => $definition ) {
			$label   = is_array( $definition ) ? (string) ( $definition['label'] ?? $path ) : (string) $definition;
			$visible = is_array( $definition ) && is_array( $definition['visible'] ?? null ) ? array_values( array_map( 'strval', $definition['visible'] ) ) : array();

			if ( is_string( $path ) && self::valid( $path ) && ! isset( $registry[ $path ] ) ) {
				$registry[ $path ] = array( $label, $visible );
			}
		}

		$list = array();

		foreach ( $registry as $path => $definition ) {
			$list[ $path ] = array(
				'label'   => $definition[0],
				'visible' => $definition[1],
			);
		}

		return $list;
	}

	/**
	 * Tells whether a path is well formed: lower-case parts of letters, digits, hyphens, separated by slashes, at most three levels.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function valid( $path ) {
		return 1 === preg_match( '#^[a-z][a-z0-9-]{0,30}(/[a-z][a-z0-9-]{0,30}){0,2}$#', (string) $path );
	}

	/**
	 * Tells whether a path is a source.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_source( $path ) {
		return isset( self::registry()[ (string) $path ] );
	}

	/**
	 * The label of a source (its path when it is unknown).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function label( $path ) {
		return self::registry()[ (string) $path ]['label'] ?? (string) $path;
	}

	/**
	 * The modes in which a source shows what it finds, empty for every mode.
	 *
	 * @param string $path Path.
	 * @return string[]
	 */
	public static function default_visibility( $path ) {
		return self::registry()[ (string) $path ]['visible'] ?? array();
	}

	/**
	 * The source above a source, '' for a first-level source.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function parent( $path ) {
		$position = strrpos( (string) $path, '/' );

		return false === $position ? '' : substr( (string) $path, 0, $position );
	}

	/**
	 * Whether a source is another or below it.
	 *
	 * @param string $path Path.
	 * @param string $of   The broader source.
	 * @return bool
	 */
	public static function is_a( $path, $of ) {
		$path = (string) $path;
		$of   = (string) $of;

		return $path === $of || 0 === strpos( $path, $of . '/' );
	}

	/**
	 * A source and the sources below it that are in the registry.
	 *
	 * @param string $path Path.
	 * @return string[]
	 */
	public static function descendants( $path ) {
		return array_values( array_filter( array_keys( self::registry() ), static fn( $other ) => self::is_a( $other, $path ) ) );
	}

	/**
	 * The sources as a tree, parents first: a list of `path`, `label`, `depth`, `visible`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function tree() {
		$paths = array_keys( self::registry() );

		sort( $paths );

		$rows = array();

		foreach ( $paths as $path ) {
			$rows[] = array(
				'path'    => $path,
				'label'   => self::label( $path ),
				'depth'   => substr_count( $path, '/' ),
				'visible' => self::default_visibility( $path ),
			);
		}

		return $rows;
	}
}
