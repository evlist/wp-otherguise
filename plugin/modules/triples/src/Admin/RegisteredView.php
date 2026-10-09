<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Data of the tab that shows what is registered.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

use Otherguise\Triples\Datatype\DatatypeRegistry;
use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Predicate\PredicateRegistry;
use Otherguise\Triples\Storage\StatementStore;

defined( 'ABSPATH' ) || exit;

/**
 * The predicates (with the number of statements of each), the entity types and the datatypes, as plain arrays; and the predicates that
 * are no longer registered but still have statements.
 */
final class RegisteredView {
	/**
	 * Predicates.
	 *
	 * @var PredicateRegistry
	 */
	private $predicates;

	/**
	 * Entity types.
	 *
	 * @var EntityTypeRegistry
	 */
	private $types;

	/**
	 * Datatypes.
	 *
	 * @var DatatypeRegistry
	 */
	private $datatypes;

	/**
	 * Store.
	 *
	 * @var StatementStore
	 */
	private $store;

	/**
	 * Builds the view.
	 *
	 * @param PredicateRegistry  $predicates Predicates.
	 * @param EntityTypeRegistry $types      Entity types.
	 * @param DatatypeRegistry   $datatypes  Datatypes.
	 * @param StatementStore     $store      Store.
	 */
	public function __construct( PredicateRegistry $predicates, EntityTypeRegistry $types, DatatypeRegistry $datatypes, StatementStore $store ) {
		$this->predicates = $predicates;
		$this->types      = $types;
		$this->datatypes  = $datatypes;
		$this->store      = $store;
	}

	/**
	 * Describes the registered predicates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function predicates() {
		$counts = $this->store->counts_by_predicate();
		$rows   = array();

		foreach ( $this->predicates->all() as $slug => $predicate ) {
			$rows[] = array(
				'slug'          => $slug,
				'label'         => $predicate->label(),
				'inverse_label' => $predicate->inverse_label(),
				'subject_types' => $predicate->subject_types(),
				'object_types'  => $predicate->object_types(),
				'max_objects'   => $predicate->max_objects_per_subject(),
				'max_subjects'  => $predicate->max_subjects_per_object(),
				'symmetric'     => $predicate->is_symmetric(),
				'on_delete'     => $predicate->on_delete(),
				'qualified_by'  => $predicate->qualified_by(),
				'qualifies'     => $predicate->qualifies(),
				'count'         => $counts[ $slug ] ?? 0,
			);
		}

		return $rows;
	}

	/**
	 * Describes the predicates that have statements but are not registered.
	 *
	 * @return array<string, int> Number of statements by predicate slug.
	 */
	public function unregistered() {
		return array_diff_key( $this->store->counts_by_predicate(), $this->predicates->all() );
	}

	/**
	 * Describes the entity types.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function entity_types() {
		$rows = array();

		foreach ( $this->types->all() as $slug => $type ) {
			$rows[] = array(
				'slug'      => $slug,
				'label'     => $type->label(),
				'exists'    => $type->can_check_existence(),
				'identify'  => $type->can_identify(),
				'load'      => $type->can_load(),
				'describe'  => $type->can_describe(),
			);
		}

		return $rows;
	}

	/**
	 * Describes the datatypes.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function datatypes() {
		$rows = array();

		foreach ( $this->datatypes->all() as $name => $datatype ) {
			$rows[] = array(
				'name'     => $name,
				'datatype' => $datatype->datatype(),
			);
		}

		return $rows;
	}
}
