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
use Otherguise\Triples\Storage\Database;
use Otherguise\Triples\Storage\SchemaManager;
use Otherguise\Triples\Storage\StatementStore;
use Otherguise\Triples\Storage\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the triples module.
 *
 * Owns the three registries. Each one runs its registration action the first time it is used:
 * `triples_register_entity_types`, `triples_register_datatypes` and `triples_register_predicates`, each with the registry
 * as argument. Other modules add their callbacks to these actions in their `boot()`. The module itself registers the
 * predicate `triples/position`, the qualifier that gives a statement an explicit rank.
 *
 * It owns the table of the statements: it creates it when the plugin is activated (and on the first request of the other sites of a
 * network), and removes it when the plugin is deleted if the administrator asked for it.
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
	 * The wpdb object, or null for the global one.
	 *
	 * @var object|null
	 */
	private $wpdb;

	/**
	 * Builds the module and its registries.
	 *
	 * @param callable|null $do_action Runs an action; defaults to WordPress `do_action`.
	 * @param object|null   $wpdb      A wpdb or a compatible object; defaults to the global `$wpdb`.
	 */
	public function __construct( $do_action = null, $wpdb = null ) {
		$this->do_action = $do_action ?? 'do_action';
		$this->wpdb      = $wpdb;

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
	 * Creates the table of the statements.
	 *
	 * @return void
	 */
	public function activate() {
		( new SchemaManager( $this->database() ) )->maybe_upgrade();
	}

	/**
	 * Makes sure the table exists and is up to date (one option read when it is): the other sites of a network create theirs here.
	 *
	 * The registries are filled lazily.
	 *
	 * @return void
	 */
	public function boot() {
		( new SchemaManager( $this->database() ) )->maybe_upgrade();
	}

	/**
	 * Removes the table and the options when the administrator asked for it.
	 *
	 * @return void
	 */
	public function uninstall() {
		( new Uninstaller( $this->wpdb ?? $GLOBALS['wpdb'] ) )->run();
	}

	/**
	 * Returns the store of the statements.
	 *
	 * @return StatementStore
	 */
	public function statements() {
		return new StatementStore( $this->database(), $this->datatypes );
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

	/**
	 * Wraps the wpdb object.
	 *
	 * @return Database
	 */
	private function database() {
		return new Database( $this->wpdb ?? $GLOBALS['wpdb'] );
	}
}
