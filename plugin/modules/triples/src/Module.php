<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Relations between things: predicate registry and statements. Depends on nothing.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples;

use Otherguise\Core\ModuleInterface;
use Otherguise\Triples\Datatype\DatatypeRegistry;
use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Predicate\PredicateDefinition;
use Otherguise\Triples\Predicate\PredicateRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the triples module.
 *
 * Owns the three registries. Each one runs its registration action the first time it is used:
 * `triples_register_entity_types`, `triples_register_datatypes` and `triples_register_predicates`, each with the registry
 * as argument. Other modules add their callbacks to these actions in their `boot()`. The module itself registers the
 * predicate `triples/position`, the qualifier that gives a statement an explicit rank.
 */
final class Module implements ModuleInterface {
	/**
	 * Runs an action: receives the hook name and the registry.
	 *
	 * @var callable
	 */
	private $do_action;

	/**
	 * Entity types.
	 *
	 * @var EntityTypeRegistry
	 */
	private $entity_types;

	/**
	 * Datatypes.
	 *
	 * @var DatatypeRegistry
	 */
	private $datatypes;

	/**
	 * Predicates.
	 *
	 * @var PredicateRegistry
	 */
	private $predicates;

	/**
	 * Builds the module and its registries.
	 *
	 * @param callable|null $do_action Runs an action; defaults to WordPress `do_action`.
	 */
	public function __construct( $do_action = null ) {
		$this->do_action = $do_action ?? 'do_action';

		$this->entity_types = EntityTypeRegistry::with_builtins(
			function ( $registry ) {
				( $this->do_action )( 'triples_register_entity_types', $registry );
			}
		);
		$this->datatypes    = DatatypeRegistry::with_builtins(
			function ( $registry ) {
				( $this->do_action )( 'triples_register_datatypes', $registry );
			}
		);
		$this->predicates   = new PredicateRegistry(
			$this->entity_types,
			$this->datatypes,
			function ( $registry ) {
				$registry->register(
					PredicateDefinition::from_array(
						array(
							'slug'                    => 'triples/position',
							'label'                   => 'Position',
							'subject_types'           => array( 'statement' ),
							'object_types'            => array( 'integer' ),
							'max_objects_per_subject' => 1,
							'qualifies'               => array( PredicateDefinition::WILDCARD ),
						)
					)
				);

				( $this->do_action )( 'triples_register_predicates', $registry );
			}
		);
	}

	/**
	 * Returns the identifier of the module.
	 *
	 * @return string
	 */
	public function id() {
		return 'triples';
	}

	/**
	 * Returns the identifiers of the modules this module needs.
	 *
	 * @return string[]
	 */
	public function dependencies() {
		return array();
	}

	/**
	 * Registers the hooks of the module.
	 *
	 * @return void
	 */
	public function boot() {
		// Nothing to register yet: the registries are filled lazily.
	}

	/**
	 * Removes the data owned by the module.
	 *
	 * @return void
	 */
	public function uninstall() {
		// Nothing to remove yet.
	}

	/**
	 * Returns the entity types.
	 *
	 * @return EntityTypeRegistry
	 */
	public function entity_types() {
		return $this->entity_types;
	}

	/**
	 * Returns the datatypes.
	 *
	 * @return DatatypeRegistry
	 */
	public function datatypes() {
		return $this->datatypes;
	}

	/**
	 * Returns the predicates.
	 *
	 * @return PredicateRegistry
	 */
	public function predicates() {
		return $this->predicates;
	}
}
