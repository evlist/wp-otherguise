<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The categories or the tags of a post.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Source;

use Otherguise\Index\Candidate;
use Otherguise\Index\Kinds;
use Otherguise\Index\Source;
use Otherguise\Index\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * The terms of one taxonomy that **have a kind** (slice 600). A term with no kind stays out of the index. The key of a candidate is
 * `term:<taxonomy>:<id>`, stable when the term is renamed; the broader candidate of a hierarchical taxonomy is the parent term, only when
 * that one has a kind too.
 */
final class Terms implements Source {
	public const KIND_META = '_index_kind';

	/**
	 * The taxonomy.
	 *
	 * @var string
	 */
	private $taxonomy;

	/**
	 * The slug of the source.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Returns the terms of a post: a list of `array{id:int,name:string,parent:int}`.
	 *
	 * @var callable
	 */
	private $terms_of;

	/**
	 * Returns a term: `array{id:int,name:string,parent:int}` or null.
	 *
	 * @var callable
	 */
	private $term;

	/**
	 * Returns the kind of a term, '' for none.
	 *
	 * @var callable
	 */
	private $kind_of;

	/**
	 * The source of the mentions it makes (a path of slice 605).
	 *
	 * @var string
	 */
	private $leaf;

	/**
	 * Builds the source.
	 *
	 * @param string      $taxonomy Taxonomy.
	 * @param string      $slug     Harvester slug (`categories`, `tags`): what the option and the commands call it.
	 * @param callable    $terms_of Terms of a post.
	 * @param callable    $term     A term by id.
	 * @param callable    $kind_of  Kind of a term.
	 * @param string|null $leaf     The source of its mentions (`post/category`, `post/tag`); by default the one of the taxonomy.
	 */
	public function __construct( $taxonomy, $slug, $terms_of, $term, $kind_of, $leaf = null ) {
		$this->taxonomy = (string) $taxonomy;
		$this->slug     = (string) $slug;
		$this->leaf     = null !== $leaf ? (string) $leaf : ( 'post_tag' === $taxonomy ? Sources::TAG : Sources::CATEGORY );
		$this->terms_of = $terms_of;
		$this->term     = $term;
		$this->kind_of  = $kind_of;
	}

	/**
	 * The source of the categories, on WordPress.
	 *
	 * @return self
	 */
	public static function categories() {
		return self::on_wordpress( 'category', 'categories' );
	}

	/**
	 * The source of the tags, on WordPress.
	 *
	 * @return self
	 */
	public static function tags() {
		return self::on_wordpress( 'post_tag', 'tags' );
	}

	/**
	 * A source on a taxonomy of WordPress.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Source slug.
	 * @return self
	 */
	private static function on_wordpress( $taxonomy, $slug ) {
		$read = static function ( $term ) {
			return $term instanceof \WP_Term ? array(
				'id'     => (int) $term->term_id,
				'name'   => (string) $term->name,
				'parent' => (int) $term->parent,
			) : null;
		};

		return new self(
			$taxonomy,
			$slug,
			static function ( $post_id ) use ( $taxonomy, $read ) {
				$terms = get_the_terms( (int) $post_id, $taxonomy );

				return is_array( $terms ) ? array_values( array_filter( array_map( $read, $terms ) ) ) : array();
			},
			static function ( $id ) use ( $taxonomy, $read ) {
				return $read( get_term( (int) $id, $taxonomy ) );
			},
			static function ( $id ) {
				return (string) get_term_meta( (int) $id, self::KIND_META, true );
			}
		);
	}

	/**
	 * The slug of the source.
	 *
	 * @return string
	 */
	public function id() {
		return $this->slug;
	}

	/**
	 * The one source of its mentions.
	 *
	 * @return string[]
	 */
	public function mention_sources() {
		return array( $this->leaf );
	}

	/**
	 * A person chose the kind of the term: it is sure.
	 *
	 * @return bool
	 */
	public function is_sure() {
		return true;
	}

	/**
	 * The terms of the post that have a kind.
	 *
	 * @param int $post_id Post.
	 * @return Candidate[]
	 */
	public function candidates( $post_id ) {
		$candidates = array();

		foreach ( ( $this->terms_of )( $post_id ) as $term ) {
			$candidate = $this->candidate( $term, 0 );

			if ( null !== $candidate ) {
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}

	/**
	 * The candidate of a term, with its broader one.
	 *
	 * @param array $term  The term.
	 * @param int   $depth Guard against a loop.
	 * @return Candidate|null
	 */
	private function candidate( array $term, $depth ) {
		$kind = (string) ( $this->kind_of )( $term['id'] );

		if ( '' === $kind || ! Kinds::is_kind( $kind ) ) {
			return null;
		}

		$parent = null;

		if ( $term['parent'] > 0 && $depth < 20 ) {
			$above  = ( $this->term )( $term['parent'] );
			$parent = null === $above ? null : $this->candidate( $above, $depth + 1 );
		}

		return new Candidate( $term['name'], $kind, array( 'term:' . $this->taxonomy . ':' . $term['id'] ), $parent, Candidate::PASSING );
	}
}
