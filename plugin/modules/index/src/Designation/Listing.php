<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The list of the motifs and of their assignments.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Index\Designations;
use Otherguise\Index\Folding;
use Otherguise\Index\Observations;
use Otherguise\Index\Resolver;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * What the screen of the assignments shows (slice 630), without WordPress: the motifs with their topic, who assigned them, their state and the number
 * of posts that observe them, **filtered** by a view, **searched** (the label, the key, which holds the source and the chain of a place, the topic, the identifier
 * of Wikidata and the state), **sorted** and **cut in pages**. The assignments are read **in two queries**, whatever
 * their number; the number of posts is asked **only for the lines of the page**, except to sort by it or to list the motifs that nothing observes
 * (one pass over every observation: heavy, and measured by `wp otherguise index motifs measure`).
 */
final class Listing {
	public const ALL        = 'all';
	public const RESOLVE    = 'resolve';
	public const INCOMPATIBLE = 'incompatible';
	public const EXCLUDED     = 'excluded';
	public const SYSTEM     = 'system';
	public const HAND       = 'hand';
	public const UNOBSERVED = 'unobserved';

	/**
	 * The motifs.
	 *
	 * @var Designations
	 */
	private $designations;

	/**
	 * The observations and assignments.
	 *
	 * @var Observations
	 */
	private $observations;

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * Builds the list.
	 *
	 * @param Designations $designations The motifs.
	 * @param Observations $observations The observations.
	 * @param Topics       $topics       The topics.
	 */
	public function __construct( Designations $designations, Observations $observations, Topics $topics ) {
		$this->designations = $designations;
		$this->observations = $observations;
		$this->topics       = $topics;
	}

	/**
	 * The lines of a page.
	 *
	 * @param array{view?:string,search?:string,orderby?:string,order?:string,paged?:int,per_page?:int} $args What was asked.
	 * @return array{rows:array<int,array<string,mixed>>,total:int,counts:array<string,?int>,pages:int}
	 */
	public function query( array $args ) {
		$view     = (string) ( $args['view'] ?? self::ALL );
		$search   = Folding::key( trim( (string) ( $args['search'] ?? '' ) ) );
		$orderby  = (string) ( $args['orderby'] ?? 'label' );
		$desc     = 'desc' === strtolower( (string) ( $args['order'] ?? 'asc' ) );
		$per_page = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$assigned = $this->observations->assignments();
		$base     = array();
		$counts   = array(
			self::ALL        => 0,
			self::RESOLVE    => 0,
			self::INCOMPATIBLE => 0,
			self::EXCLUDED     => 0,
			self::SYSTEM     => 0,
			self::HAND       => 0,
			self::UNOBSERVED => null,
		);

		foreach ( $this->designations->all() as $id => $one ) {
			$have = $assigned[ (int) $id ] ?? null;

			$base[ (int) $id ] = array(
				'id'       => (int) $id,
				'key'      => (string) $one['key'],
				'label'    => (string) ( $one['evidence']['label'] ?? $one['key'] ),
				'source'   => (string) $one['source'],
				'state'    => (string) $one['state'],
				'topic'    => null === $have ? 0 : $have['topic'],
				'by'       => null === $have ? '' : $have['by'],
				'wikidata' => (string) ( $this->designations->enrichment( (int) $id )['qid'] ?? '' ),
				'ambiguity' => is_array( $one['proposal']['ambiguity'] ?? null ) ? $one['proposal']['ambiguity'] : null,
			);

			$base[ (int) $id ]['incompatible'] = Designations::AMBIGUOUS === $one['state'] && Resolver::INCOMPATIBLE === (string) ( $base[ (int) $id ]['ambiguity']['type'] ?? '' );

			++$counts[ self::ALL ];
			$counts[ self::RESOLVE ] += in_array( $one['state'], array( Designations::PENDING, Designations::AMBIGUOUS ), true ) ? 1 : 0;
			$counts[ self::EXCLUDED ]    += Designations::EXCLUDED === $one['state'] ? 1 : 0;
			$counts[ self::INCOMPATIBLE ] += $base[ (int) $id ]['incompatible'] ? 1 : 0;
			$counts[ self::SYSTEM ]  += null !== $have && Observations::SYSTEM === $have['by'] ? 1 : 0;
			$counts[ self::HAND ]    += null !== $have && Observations::HAND === $have['by'] ? 1 : 0;
		}

		$posts = null;

		if ( self::UNOBSERVED === $view || 'posts' === $orderby ) {
			$posts = $this->observations->post_counts();
		}

		if ( self::UNOBSERVED === $view ) {
			$base                      = array_filter( $base, static fn( $row ) => 0 === ( $posts[ $row['id'] ] ?? 0 ) );
			$counts[ self::UNOBSERVED ] = count( $base );
		}

		$labels = array();

		foreach ( $base as $id => $row ) {
			$topic = $row['topic'] > 0 ? $this->topics->get( $row['topic'] ) : null;

			$base[ $id ]['topic_label'] = null === $topic ? '' : (string) $topic['label'];
			$labels[ $id ]              = Folding::key( $base[ $id ]['label'] . ' ' . $row['key'] . ' ' . $base[ $id ]['topic_label'] . ' ' . $row['wikidata'] . ' ' . $row['state'] );
		}

		$rows = array_values(
			array_filter(
				$base,
				static function ( $row ) use ( $view, $search, $labels ) {
					if ( self::RESOLVE === $view && ! in_array( $row['state'], array( Designations::PENDING, Designations::AMBIGUOUS ), true ) ) {
						return false;
					}

					if ( self::EXCLUDED === $view && Designations::EXCLUDED !== $row['state'] ) {
						return false;
					}

					if ( self::INCOMPATIBLE === $view && ! $row['incompatible'] ) {
						return false;
					}

					if ( self::SYSTEM === $view && Observations::SYSTEM !== $row['by'] ) {
						return false;
					}

					if ( self::HAND === $view && Observations::HAND !== $row['by'] ) {
						return false;
					}

					return '' === $search || false !== strpos( $labels[ $row['id'] ], $search );
				}
			)
		);

		usort(
			$rows,
			static function ( $a, $b ) use ( $orderby, $posts ) {
				switch ( $orderby ) {
					case 'posts':
						$order = ( $posts[ $a['id'] ] ?? 0 ) <=> ( $posts[ $b['id'] ] ?? 0 );
						break;
					case 'topic':
						$order = strcmp( Folding::key( $a['topic_label'] ), Folding::key( $b['topic_label'] ) );
						break;
					case 'state':
						$order = strcmp( $a['state'], $b['state'] );
						break;
					case 'source':
						$order = strcmp( $a['source'], $b['source'] );
						break;
					default:
						$order = strcmp( Folding::key( $a['label'] ), Folding::key( $b['label'] ) );
				}

				return 0 !== $order ? $order : $a['id'] <=> $b['id'];
			}
		);

		if ( $desc ) {
			$rows = array_reverse( $rows );
		}

		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = min( max( 1, (int) ( $args['paged'] ?? 1 ) ), $pages );
		$rows  = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );

		$posts = $posts ?? $this->observations->post_counts( array_column( $rows, 'id' ) );

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['posts'] = (int) ( $posts[ $row['id'] ] ?? 0 );
		}

		return array(
			'rows'   => $rows,
			'total'  => $total,
			'counts' => $counts,
			'pages'  => $pages,
		);
	}
}
