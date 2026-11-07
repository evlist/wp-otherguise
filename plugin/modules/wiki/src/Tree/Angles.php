<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The other angles of a subject.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;

defined( 'ABSPATH' ) || exit;

/**
 * The same subject under several angles (slice 507): the other angles of a page are **the pages that have the same title elsewhere in the
 * hierarchy** (`Biography/Paris`, `Opinion/Paris`, at any level; the index of the exact title, slice 501), then **the pages declared as the
 * same subject** under another title (`wiki/same-subject`, symmetric, page to page: `Paris` and `Paris (ville)`). A page is listed once.
 * The block `wiki/other-angles` prints them for the pages the reader may see, and nothing when there are none.
 */
final class Angles {
	public const SAME = 'wiki/same-subject';

	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * Returns the service of the statements; called when needed.
	 *
	 * @var callable
	 */
	private $statements;

	/**
	 * Builds the service.
	 *
	 * @param Keys     $keys       The index of the tree.
	 * @param callable $statements Returns the `Statements` service (the module Triples).
	 */
	public function __construct( Keys $keys, $statements ) {
		$this->keys       = $keys;
		$this->statements = $statements;
	}

	/**
	 * The other angles of a page: the pages of the same title elsewhere, by address, then the pages declared as the same subject, by address.
	 *
	 * @param int $post_id The page.
	 * @return int[] Page ids, each once, the page itself never.
	 */
	public function of( $post_id ) {
		$post_id = (int) $post_id;
		$current = $this->keys->current_of( $post_id );
		$found   = array();

		if ( null !== $current ) {
			foreach ( $this->keys->elsewhere( $current['title'], $post_id ) as $page ) {
				$found[ $page['post'] ] = $page['post'];
			}
		}

		$same = $this->related( $post_id );

		return array_values( array_unique( array_merge( $this->by_address( $found ), $this->by_address( array_diff( $same, $found ) ) ) ) );
	}

	/**
	 * Declares two pages the same subject. Symmetric, and can be said twice.
	 *
	 * @param int $a A page.
	 * @param int $b Another page.
	 * @return bool False when it is the same page or one of them has no key (not a page of the tree).
	 */
	public function relate( $a, $b ) {
		if ( (int) $a === (int) $b || null === $this->keys->current_of( (int) $a ) || null === $this->keys->current_of( (int) $b ) ) {
			return false;
		}

		$this->statements()->triple( Ref::post( (int) $a ), self::SAME, Ref::post( (int) $b ) );

		return true;
	}

	/**
	 * Takes the declaration back.
	 *
	 * @param int $a A page.
	 * @param int $b Another page.
	 * @return bool Whether there was one.
	 */
	public function unrelate( $a, $b ) {
		return $this->statements()->remove( Ref::post( (int) $a ), self::SAME, Ref::post( (int) $b ) ) > 0;
	}

	/**
	 * The pages declared as the same subject as a page (either end).
	 *
	 * @param int $post_id The page.
	 * @return int[]
	 */
	public function related( $post_id ) {
		$ids = array();

		foreach ( $this->statements()->objects_of( Ref::post( (int) $post_id ), self::SAME ) as $statement ) {
			$ids[] = (int) $statement->object()->id();
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Deletes the declarations that involve a page that no longer exists (a deletion that skipped the hooks of WordPress leaves them).
	 *
	 * @param int[] $pages The ids of the pages that exist.
	 * @return int The number of statements deleted.
	 */
	public function purge( array $pages ) {
		$known   = array_flip( array_map( 'intval', $pages ) );
		$deleted = 0;

		foreach ( $this->statements()->match( null, self::SAME ) as $statement ) {
			if ( ! isset( $known[ (int) $statement->subject()->id() ], $known[ (int) $statement->object()->id() ] ) ) {
				$this->statements()->delete( $statement );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Orders pages by their address; a page with no key goes last.
	 *
	 * @param int[] $pages Page ids.
	 * @return int[]
	 */
	private function by_address( array $pages ) {
		$paths = array();

		foreach ( $pages as $page ) {
			$titles          = $this->keys->path_of( (int) $page );
			$paths[ $page ] = null === $titles ? '~' : implode( '/', $titles );
		}

		asort( $paths );

		return array_keys( $paths );
	}

	/**
	 * The statements of Triples.
	 *
	 * @return Statements
	 */
	private function statements() {
		return ( $this->statements )();
	}
}
