<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The posts of a section, flattened, for other modules.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Section;

defined( 'ABSPATH' ) || exit;

/**
 * The posts of a section in the order of the book (slice 610): each unit, followed by its own units when it is itself a section, as the merge
 * does, down to six levels; a cycle stops; a post that is not published, or has a password, is left out. The module of the index asks for
 * this through the filter `index_section_posts` and knows nothing else of Books.
 */
final class SectionPosts {
	public const DEPTH = 6;

	/**
	 * Returns the ids of the units of a section: `fn( int $section ): int[]`.
	 *
	 * @var callable
	 */
	private $order;

	/**
	 * Returns `array{status:string,password:string}` of a post, null when it does not exist: `fn( int $post ): ?array`.
	 *
	 * @var callable
	 */
	private $post_of;

	/**
	 * Builds the list.
	 *
	 * @param callable      $order   The units of a section.
	 * @param callable|null $post_of The status and password of a post; default the post of WordPress.
	 */
	public function __construct( $order, $post_of = null ) {
		$this->order   = $order;
		$this->post_of = $post_of ?? static function ( $id ) {
			$post = get_post( (int) $id );

			return $post instanceof \WP_Post ? array(
				'status'   => (string) $post->post_status,
				'password' => (string) $post->post_password,
			) : null;
		};
	}

	/**
	 * The posts of a section, flattened.
	 *
	 * @param int $section Section.
	 * @return int[]
	 */
	public function ids( $section ) {
		return $this->walk( (int) $section, array( (int) $section => true ), 0 );
	}

	/**
	 * The units of a section, each followed by its own units.
	 *
	 * @param int              $section Section.
	 * @param array<int, bool> $seen    Sections followed.
	 * @param int              $depth   Level.
	 * @return int[]
	 */
	private function walk( $section, array $seen, $depth ) {
		$ids = array();

		foreach ( (array) ( $this->order )( $section ) as $id ) {
			$id   = (int) $id;
			$post = ( $this->post_of )( $id );

			if ( null === $post || 'publish' !== $post['status'] || '' !== $post['password'] ) {
				continue;
			}

			$ids[] = $id;

			if ( $depth < self::DEPTH && ! isset( $seen[ $id ] ) ) {
				$ids = array_merge( $ids, $this->walk( $id, $seen + array( $id => true ), $depth + 1 ) );
			}
		}

		return $ids;
	}
}
