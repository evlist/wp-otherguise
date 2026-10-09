<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Filters of the list of statements.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Predicate\PredicateDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * What the administrator asked for, checked: every value that is not valid is replaced by its default.
 */
final class StatementsFilters {
	/**
	 * Value of the predicate filter that means "the predicates that are no longer registered".
	 */
	public const UNREGISTERED = '!unregistered';

	/**
	 * Predicate slug, `UNREGISTERED`, or null.
	 *
	 * @var string|null
	 */
	public $predicate;

	/**
	 * Entity type or datatype, or null.
	 *
	 * @var string|null
	 */
	public $type;

	/**
	 * Entity that must be an end of the statements, or null.
	 *
	 * @var EntityRef|null
	 */
	public $entity;

	/**
	 * Whether the statements about statements are listed.
	 *
	 * @var bool
	 */
	public $with_qualifiers = false;

	/**
	 * Whether only the statements with a missing end are listed.
	 *
	 * @var bool
	 */
	public $orphans = false;

	/**
	 * Column: `id` or `created_gmt`.
	 *
	 * @var string
	 */
	public $orderby = 'id';

	/**
	 * Whether the order is descending.
	 *
	 * @var bool
	 */
	public $descending = false;

	/**
	 * Page number, from 1.
	 *
	 * @var int
	 */
	public $page = 1;

	/**
	 * Statements per page.
	 *
	 * @var int
	 */
	public $per_page = 20;

	/**
	 * With the orphans filter: the scan goes on after this statement id.
	 *
	 * @var int
	 */
	public $after = 0;

	/**
	 * Reads the filters from the query string.
	 *
	 * @param array<string, mixed> $request  Query string (unslashed).
	 * @param int                  $per_page Statements per page.
	 * @return self
	 */
	public static function from_request( array $request, $per_page = 20 ) {
		$filters           = new self();
		$filters->per_page = max( 1, min( 200, (int) $per_page ) );

		$predicate = self::text( $request, 'predicate' );

		if ( self::UNREGISTERED === $predicate || ( null !== $predicate && PredicateDefinition::is_valid_slug( $predicate ) ) ) {
			$filters->predicate = $predicate;
		}

		$type = self::text( $request, 'entity_type' );

		if ( null !== $type && 1 === preg_match( EntityRef::TYPE_PATTERN, $type ) ) {
			$filters->type = $type;
		}

		$entity = self::text( $request, 'entity' );

		if ( null !== $entity ) {
			try {
				$filters->entity = EntityRef::parse( $entity );
			} catch ( \InvalidArgumentException $problem ) {
				$filters->entity = null;
			}
		}

		$filters->with_qualifiers = ! empty( $request['with_qualifiers'] );
		$filters->orphans         = ! empty( $request['orphans'] );
		$filters->orderby         = 'created_gmt' === self::text( $request, 'orderby' ) ? 'created_gmt' : 'id';
		$filters->descending      = 'desc' === strtolower( (string) self::text( $request, 'order' ) );
		$filters->page            = max( 1, (int) ( $request['paged'] ?? 1 ) );
		$filters->after           = max( 0, (int) ( $request['after'] ?? 0 ) );

		return $filters;
	}

	/**
	 * Returns the query string arguments that rebuild these filters (the defaults are left out).
	 *
	 * @return array<string, string>
	 */
	public function to_args() {
		$args = array();

		if ( null !== $this->predicate ) {
			$args['predicate'] = $this->predicate;
		}

		if ( null !== $this->type ) {
			$args['entity_type'] = $this->type;
		}

		if ( null !== $this->entity ) {
			$args['entity'] = (string) $this->entity;
		}

		if ( $this->with_qualifiers ) {
			$args['with_qualifiers'] = '1';
		}

		if ( $this->orphans ) {
			$args['orphans'] = '1';
		}

		if ( 'id' !== $this->orderby ) {
			$args['orderby'] = $this->orderby;
		}

		if ( $this->descending ) {
			$args['order'] = 'desc';
		}

		return $args;
	}

	/**
	 * Reads a text value of the request.
	 *
	 * @param array<string, mixed> $request Query string.
	 * @param string               $key     Key.
	 * @return string|null Null when missing, empty or not a string.
	 */
	private static function text( array $request, $key ) {
		$value = $request[ $key ] ?? null;

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}
}
