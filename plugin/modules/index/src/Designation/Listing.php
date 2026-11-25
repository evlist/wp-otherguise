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
use Otherguise\Index\NoneTopic;
use Otherguise\Index\Observations;
use Otherguise\Index\Resolver;
use Otherguise\Index\Sources;
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
	public const TOPICS       = 'topics';
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
	 * @param array{view?:string,search?:string,filters?:array<string,string>,orderby?:string,order?:string,paged?:int,per_page?:int} $args What was asked: `filters` is one
	 *        filter per column (slice 637): `topic` and `motif` (a text), `status` (of the topic), `source` (a path: a source stands for its descendants), `posts`
	 *        (`none`, or a number: at least that many), `by` and `state`; they combine with the view and the search.
	 * @return array{rows:array<int,array<string,mixed>>,total:int,counts:array<string,?int>,pages:int}
	 */
	public function query( array $args ) {
		$view     = (string) ( $args['view'] ?? self::ALL );
		$search   = Folding::key( trim( (string) ( $args['search'] ?? '' ) ) );
		$filters  = self::clean_filters( (array) ( $args['filters'] ?? array() ) );
		$orderby  = (string) ( $args['orderby'] ?? '' );
		$orderby  = '' === $orderby ? 'topic' : $orderby;
		$desc     = 'desc' === strtolower( (string) ( $args['order'] ?? 'asc' ) );
		$per_page = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$assigned = $this->observations->assignments();
		$base     = array();
		$counts   = array(
			self::ALL        => 0,
			self::RESOLVE    => 0,
			self::INCOMPATIBLE => 0,
			self::EXCLUDED     => 0,
			self::TOPICS     => 0,
			self::SYSTEM     => 0,
			self::HAND       => 0,
			self::UNOBSERVED => null,
		);

		$none = ( new NoneTopic( $this->topics ) )->id( false );

		foreach ( $this->designations->all() as $id => $one ) {
			$have = $assigned[ (int) $id ] ?? null;

			$base[ (int) $id ] = array(
				'id'       => (int) $id,
				'type'     => 'motif',
				'key'      => (string) $one['key'],
				'label'    => (string) ( $one['evidence']['label'] ?? $one['key'] ),
				'source'   => (string) $one['source'],
				'state'    => (string) $one['state'],
				'topic'    => null === $have ? 0 : $have['topic'],
				'by'       => null === $have ? '' : $have['by'],
				'wikidata' => (string) ( $this->designations->enrichment( (int) $id )['qid'] ?? '' ),
				'ambiguity' => is_array( $one['proposal']['ambiguity'] ?? null ) ? $one['proposal']['ambiguity'] : null,
			);

			// Excluded: assigned to the topic `_None_` (slice 641).
			$base[ (int) $id ]['excluded']     = $none > 0 && null !== $have && $none === (int) $have['topic'];
			$counts[ self::EXCLUDED ]         += $base[ (int) $id ]['excluded'] ? 1 : 0;
			$base[ (int) $id ]['incompatible'] = Designations::AMBIGUOUS === $one['state'] && Resolver::INCOMPATIBLE === (string) ( $base[ (int) $id ]['ambiguity']['type'] ?? '' );

			++$counts[ self::ALL ];
			$counts[ self::RESOLVE ] += in_array( $one['state'], array( Designations::PENDING, Designations::AMBIGUOUS ), true ) ? 1 : 0;
			$counts[ self::INCOMPATIBLE ] += $base[ (int) $id ]['incompatible'] ? 1 : 0;
			$counts[ self::SYSTEM ]  += null !== $have && Observations::SYSTEM === $have['by'] ? 1 : 0;
			$counts[ self::HAND ]    += null !== $have && Observations::HAND === $have['by'] ? 1 : 0;
		}

		$posts = null;

		// One pass over every observation: heavy, so only when the view, the sort or the filter of the column *Posts* needs it.
		if ( self::UNOBSERVED === $view || 'posts' === $orderby || '' !== $filters['posts'] ) {
			$posts = $this->observations->post_counts();
		}

		if ( self::UNOBSERVED === $view ) {
			$base                      = array_filter( $base, static fn( $row ) => 0 === ( $posts[ $row['id'] ] ?? 0 ) );
			$counts[ self::UNOBSERVED ] = count( $base );
		}

		// The topics that no motif is assigned to are lines of the table too (slice 638): the broader levels of a place (Aragon above its communes), a topic made by hand…
		// Their line has the number of the topic, **negative**, so that it is not taken for a motif; they are in *All* and in their own view, and in no other.
		$with   = array();
		$lonely = array();

		foreach ( $assigned as $have ) {
			$with[ (int) $have['topic'] ] = true;
		}

		foreach ( $this->topics->all() as $topic_id => $topic ) {
			if ( ! isset( $with[ (int) $topic_id ] ) ) {
				$lonely[ (int) $topic_id ] = $topic;
			}
		}

		$counts[ self::TOPICS ] = count( $lonely );
		$counts[ self::ALL ]   += count( $lonely );

		if ( in_array( $view, array( self::ALL, self::TOPICS ), true ) ) {
			foreach ( $lonely as $topic_id => $topic ) {
				$qid = '';

				foreach ( (array) ( $topic['keys'] ?? array() ) as $key ) {
					if ( '' === $qid && 0 === strpos( (string) $key, 'wikidata:' ) ) {
						$qid = substr( (string) $key, 9 );
					}
				}

				$base[ -$topic_id ] = array(
					'id'           => -$topic_id,
					'type'         => 'topic',
					'key'          => '',
					'label'        => '',
					'source'       => '',
					'state'        => '',
					'topic'        => $topic_id,
					'by'           => '',
					'wikidata'     => $qid,
					'ambiguity'    => null,
					'incompatible' => false,
					'excluded'     => false,
				);
			}
		}

		$labels = array();

		foreach ( $base as $id => $row ) {
			$topic = $row['topic'] > 0 ? $this->topics->get( $row['topic'] ) : null;

			$base[ $id ]['topic_label']  = null === $topic ? '' : (string) $topic['label'];
			$base[ $id ]['topic_status'] = null === $topic ? '' : (string) $topic['status'];
			$labels[ $id ]              = Folding::key( $base[ $id ]['label'] . ' ' . $row['key'] . ' ' . $base[ $id ]['topic_label'] . ' ' . $row['wikidata'] . ' ' . $row['state'] );
		}

		$rows = array_values(
			array_filter(
				$base,
				static function ( $row ) use ( $view, $search, $labels, $filters, $posts ) {
					if ( self::TOPICS === $view && 'topic' !== $row['type'] ) {
						return false;
					}

					if ( self::RESOLVE === $view && ! in_array( $row['state'], array( Designations::PENDING, Designations::AMBIGUOUS ), true ) ) {
						return false;
					}

					if ( self::EXCLUDED === $view && ! $row['excluded'] ) {
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

					if ( ! self::passes( $row, $filters, $posts ) ) {
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
						// The motifs that have no topic come last.
						$order = strcmp( '' === $a['topic_label'] ? "\u{FFFF}" : Folding::key( $a['topic_label'] ), '' === $b['topic_label'] ? "\u{FFFF}" : Folding::key( $b['topic_label'] ) );
						break;
					case 'state':
						$order = strcmp( $a['state'], $b['state'] );
						break;
					case 'status':
						$order = strcmp( $a['topic_status'], $b['topic_status'] );
						break;
					case 'by':
						$order = strcmp( $a['by'], $b['by'] );
						break;
					case 'source':
						$order = strcmp( $a['source'], $b['source'] );
						break;
					default:
						$order = strcmp( Folding::key( $a['label'] ), Folding::key( $b['label'] ) );
				}

				// The lines of one topic come in the order of their motifs.
				$order = 0 === $order && 'topic' === $orderby ? strcmp( Folding::key( $a['label'] ), Folding::key( $b['label'] ) ) : $order;

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

		// A line of a topic with no motif has no motif to count the posts of.
		$posts = $posts ?? $this->observations->post_counts( array_values( array_filter( array_column( $rows, 'id' ), static fn( $id ) => $id > 0 ) ) );

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

	/**
	 * The filters of the columns, cleaned: only the known ones, as trimmed strings (an empty one filters nothing).
	 *
	 * @param array<string, mixed> $filters What was asked.
	 * @return array<string, string>
	 */
	public static function clean_filters( array $filters ) {
		$clean = array();

		foreach ( array( 'topic', 'status', 'motif', 'source', 'posts', 'by', 'state' ) as $name ) {
			$clean[ $name ] = isset( $filters[ $name ] ) && is_scalar( $filters[ $name ] ) ? trim( (string) $filters[ $name ] ) : '';
		}

		return $clean;
	}

	/**
	 * Whether a line passes the filters of the columns.
	 *
	 * @param array<string, mixed>  $row     The line.
	 * @param array<string, string> $filters The filters, cleaned.
	 * @param array<int, int>|null  $posts   The number of posts by motif, when the filter of the posts needs it.
	 * @return bool
	 */
	private static function passes( array $row, array $filters, $posts ) {
		// A topic with no motif has no motif, source, posts, assignment or state: the filters of those columns leave it out.
		if ( 'topic' === $row['type'] && '' !== $filters['motif'] . $filters['source'] . $filters['posts'] . $filters['by'] . $filters['state'] ) {
			return false;
		}

		if ( '' !== $filters['topic'] && false === strpos( Folding::key( (string) $row['topic_label'] ), Folding::key( $filters['topic'] ) ) ) {
			return false;
		}

		if ( '' !== $filters['motif'] && false === strpos( Folding::key( $row['label'] . ' ' . $row['key'] ), Folding::key( $filters['motif'] ) ) ) {
			return false;
		}

		// The status of the topic: `none` is a motif that has no topic.
		if ( '' !== $filters['status'] && ( 'none' === $filters['status'] ? '' !== (string) $row['topic_status'] : $filters['status'] !== (string) $row['topic_status'] ) ) {
			return false;
		}

		// A source stands for the sources below it.
		if ( '' !== $filters['source'] && ! Sources::is_a( (string) $row['source'], $filters['source'] ) ) {
			return false;
		}

		// Who assigned it: `none` is a motif with no assignment.
		if ( '' !== $filters['by'] && ( 'none' === $filters['by'] ? '' !== (string) $row['by'] : $filters['by'] !== (string) $row['by'] ) ) {
			return false;
		}

		if ( '' !== $filters['state'] && $filters['state'] !== (string) $row['state'] ) {
			return false;
		}

		if ( '' !== $filters['posts'] ) {
			$count = (int) ( $posts[ $row['id'] ] ?? 0 );

			if ( 'none' === $filters['posts'] ? 0 !== $count : $count < max( 1, (int) $filters['posts'] ) ) {
				return false;
			}
		}

		return true;
	}
}
