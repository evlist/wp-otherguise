<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Data of the list of statements.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\NodeInterface;
use Otherguise\Triples\Predicate\PredicateRegistry;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\StatementQuery;
use Otherguise\Triples\Storage\StatementStore;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the filters into a query and the statements into data to display. It calls no WordPress function, and does not escape: the
 * screen escapes what it prints.
 */
final class StatementsView {
	/**
	 * Service.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Store.
	 *
	 * @var StatementStore
	 */
	private $store;

	/**
	 * Predicates.
	 *
	 * @var PredicateRegistry
	 */
	private $predicates;

	/**
	 * Scanner.
	 *
	 * @var OrphanScanner
	 */
	private $scanner;

	/**
	 * Builds the view.
	 *
	 * @param Statements        $statements Service.
	 * @param StatementStore    $store      Store.
	 * @param PredicateRegistry $predicates Predicates.
	 * @param OrphanScanner     $scanner    Scanner.
	 */
	public function __construct( Statements $statements, StatementStore $store, PredicateRegistry $predicates, OrphanScanner $scanner ) {
		$this->statements = $statements;
		$this->store      = $store;
		$this->predicates = $predicates;
		$this->scanner    = $scanner;
	}

	/**
	 * Builds the query for some filters, without paging.
	 *
	 * @param StatementsFilters $filters Filters.
	 * @return StatementQuery
	 */
	public function query( StatementsFilters $filters ) {
		$query = ( new StatementQuery() )->order_by( $filters->orderby );

		if ( $filters->descending ) {
			$query = $query->descending();
		}

		if ( ! $filters->with_qualifiers ) {
			$query = $query->excluding_subject_type( 'statement' );
		}

		if ( StatementsFilters::UNREGISTERED === $filters->predicate ) {
			$query = $query->with_other_predicates( array_keys( $this->predicates->all() ) );
		} elseif ( null !== $filters->predicate ) {
			$query = $query->with_predicates( array( $filters->predicate ) );
		}

		if ( null !== $filters->type ) {
			$query = $query->with_type( $filters->type );
		}

		if ( null !== $filters->entity ) {
			$query = $query->involving( $filters->entity );
		}

		return $query;
	}

	/**
	 * Reads one page of statements.
	 *
	 * Without the orphans filter the page is the one asked for and the total is known. With it the table is scanned from the statement
	 * `after`, so there is no page number and no total: the page says where the next one starts.
	 *
	 * @param StatementsFilters $filters Filters.
	 * @return array{rows: array<int, array<string, mixed>>, total: int|null, next_after: int|null}
	 */
	public function page( StatementsFilters $filters ) {
		$query = $this->query( $filters );

		if ( $filters->orphans ) {
			return $this->orphans_page( $filters, $query );
		}

		$statements = $this->store->query( $query->limit( $filters->per_page, ( $filters->page - 1 ) * $filters->per_page ) );

		return array(
			'rows'       => $this->rows( $statements ),
			'total'      => $this->store->count( $query ),
			'next_after' => null,
		);
	}

	/**
	 * Turns statements into data to display.
	 *
	 * @param Statement[] $statements Statements.
	 * @return array<int, array<string, mixed>>
	 */
	public function rows( array $statements ) {
		$qualifications = $this->statements->qualifications_of( $statements );
		$rows           = array();

		foreach ( $statements as $statement ) {
			$about = array();

			foreach ( $qualifications[ $statement->id() ] ?? array() as $group ) {
				foreach ( $group as $qualification ) {
					$about[] = array(
						'id'        => $qualification->id(),
						'predicate' => $this->predicate( $qualification->predicate() ),
						'object'    => $this->node( $qualification->object() ),
					);
				}
			}

			$rows[] = array(
				'id'        => $statement->id(),
				'subject'   => $this->node( $statement->subject() ),
				'predicate' => $this->predicate( $statement->predicate() ),
				'object'    => $this->node( $statement->object() ),
				'about'     => $about,
				'created'   => $statement->created_gmt(),
			);
		}

		return $rows;
	}

	/**
	 * Describes a predicate.
	 *
	 * @param string $slug Predicate slug.
	 * @return array{slug: string, label: string, registered: bool}
	 */
	private function predicate( $slug ) {
		$registered = $this->predicates->has( $slug );

		return array(
			'slug'       => $slug,
			'label'      => $registered ? $this->predicates->get( $slug )->label() : $slug,
			'registered' => $registered,
		);
	}

	/**
	 * Describes an end of a statement: an entity (label, link, missing or not) or a literal.
	 *
	 * @param NodeInterface $node Entity reference or literal.
	 * @return array{label: string, url: string|null, missing: bool, raw: string, literal: bool, datatype: string|null}
	 */
	private function node( NodeInterface $node ) {
		if ( ! $node instanceof EntityRef ) {
			return array(
				'label'    => $node->key(),
				'url'      => null,
				'missing'  => false,
				'raw'      => $node->type() . ':' . $node->key(),
				'literal'  => true,
				'datatype' => $node->type(),
			);
		}

		$description = $this->statements->describe( $node );

		return array(
			'label'    => $description['label'],
			'url'      => $description['url'],
			'missing'  => false === $description['exists'],
			'raw'      => (string) $node,
			'literal'  => false,
			'datatype' => null,
		);
	}

	/**
	 * Reads a page of orphans: batches are scanned until the page is full or the table ends.
	 *
	 * @param StatementsFilters $filters Filters.
	 * @param StatementQuery    $query   Query of the filters.
	 * @return array{rows: array<int, array<string, mixed>>, total: null, next_after: int|null}
	 */
	private function orphans_page( StatementsFilters $filters, StatementQuery $query ) {
		$orphans = array();
		$after   = $filters->after;
		$done    = false;

		$found = 0;

		while ( ! $done && $found < $filters->per_page ) {
			$batch   = $this->scanner->scan( $after, $query );
			$orphans = array_merge( $orphans, $batch['orphans'] );
			$found   = count( $orphans );
			$after   = $batch['last_id'];
			$done    = $batch['done'];
		}

		if ( count( $orphans ) > $filters->per_page ) {
			$orphans = array_slice( $orphans, 0, $filters->per_page );
			$after   = (int) end( $orphans )->id();
			$done    = false;
		}

		return array(
			'rows'       => $this->rows( $orphans ),
			'total'      => null,
			'next_after' => $done ? null : $after,
		);
	}
}
