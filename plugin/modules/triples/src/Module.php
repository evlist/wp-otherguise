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
use Otherguise\Triples\Admin\Admin;
use Otherguise\Triples\Admin\Environment;
use Otherguise\Triples\Datatype\DatatypeRegistry;
use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Entity\WordPressEntities;
use Otherguise\Triples\Predicate\PredicateDefinition;
use Otherguise\Triples\Predicate\PredicateRegistry;
use Otherguise\Triples\Service\EntityResolver;
use Otherguise\Triples\Service\EventQueue;
use Otherguise\Triples\Service\StatementEraser;
use Otherguise\Triples\Service\StatementListing;
use Otherguise\Triples\Service\StatementReader;
use Otherguise\Triples\Service\StatementValidator;
use Otherguise\Triples\Service\WordPressCleanup;
use Otherguise\Triples\Storage\Cache;
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
	 * Adds an action: receives the hook name, the callback, the priority and the number of arguments.
	 *
	 * @var callable
	 */
	private $add_action;

	/**
	 * Tells whether the request is in the administration.
	 *
	 * @var callable
	 */
	private $is_admin;

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
	 * Database wrapper, shared so that the transactions of every service count their depth together.
	 *
	 * @var Database|null
	 */
	private $database;

	/**
	 * Store.
	 *
	 * @var StatementStore|null
	 */
	private $store;

	/**
	 * Service.
	 *
	 * @var Statements|null
	 */
	private $statements;

	/**
	 * Builds the module and its registries.
	 *
	 * @param callable|null $do_action Runs an action; defaults to WordPress `do_action`.
	 * @param object|null   $wpdb      A wpdb or a compatible object; defaults to the global `$wpdb`.
	 * @param callable|null $add_action Adds an action; defaults to WordPress `add_action`.
	 * @param callable|null $is_admin   Tells whether this is an administration request; defaults to WordPress `is_admin`.
	 */
	public function __construct( $do_action = null, $wpdb = null, $add_action = null, $is_admin = null ) {
		$this->do_action  = $do_action ?? 'do_action';
		$this->add_action = $add_action ?? 'add_action';
		$this->is_admin   = $is_admin ?? 'is_admin';
		$this->wpdb      = $wpdb;

		$this->entity_types = EntityTypeRegistry::with_builtins(
			function ( $registry ) {
				( $this->do_action )( 'triples_register_entity_types', $registry );
			},
			$this->entity_behaviors()
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
	 * Makes sure the table exists and is up to date (one option read when it is): the other sites of a network create theirs here, and
	 * the statements that involve a post, a media item, a term or a user follow its deletion (`WordPressCleanup`), and the administration
	 * screen is hooked in the administration only.
	 *
	 * The registries are filled lazily.
	 *
	 * @return void
	 */
	public function boot() {
		require_once dirname( __DIR__ ) . '/functions.php';

		( new SchemaManager( $this->database() ) )->maybe_upgrade();
		( new WordPressCleanup( $this->statements() ) )->register( $this->add_action );

		if ( ( $this->is_admin )() ) {
			$this->admin( new Environment() )->register( $this->add_action );
		}
	}

	/**
	 * Builds the administration screen.
	 *
	 * @param Environment $environment Environment.
	 * @return Admin
	 */
	public function admin( Environment $environment ) {
		return new Admin( $environment, $this->statements(), $this->store(), $this->predicates, $this->entity_types, $this->datatypes, $this->add_action );
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
	 * Returns the service that creates and reads statements.
	 *
	 * @return Statements
	 */
	public function statements() {
		if ( null === $this->statements ) {
			$resolver         = new EntityResolver( $this->entity_types );
			$validator        = new StatementValidator( $this->entity_types, $this->datatypes, $this->predicates, $resolver, $this->store() );
			$reader           = new StatementReader( $this->store(), $validator, $resolver, $this->predicates );
			$events           = new EventQueue(
				$this->database(),
				function ( $hook, $statement ) {
					( $this->do_action )( $hook, $statement );
				}
			);
			$eraser           = new StatementEraser( $this->store(), $this->database(), $events, $this->predicates );
			$this->statements = new Statements(
				$this->store(),
				$this->database(),
				$validator,
				$resolver,
				$reader,
				new StatementListing( $reader, $resolver, $validator ),
				$eraser,
				$events
			);
		}

		return $this->statements;
	}

	/**
	 * Returns the low-level store of the statements, which checks nothing: use `statements()`.
	 *
	 * @return StatementStore
	 */
	public function store() {
		if ( null === $this->store ) {
			$this->store = new StatementStore( $this->database(), $this->datatypes, null, new Cache() );
		}

		return $this->store;
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
		if ( null === $this->database ) {
			$this->database = new Database( $this->wpdb ?? $GLOBALS['wpdb'] );
		}

		return $this->database;
	}

	/**
	 * Returns the existence checks, recognizers and loaders of the built-in types: WordPress objects, and the store for statements.
	 *
	 * @return array<string, array<string, callable>>
	 */
	private function entity_behaviors() {
		$behaviors = WordPressEntities::behaviors();

		$behaviors['statement'] = array(
			'exists' => function ( $id ) {
				return null !== $this->store()->find( (int) $id );
			},
			'load'   => function ( $id ) {
				return $this->store()->find( (int) $id );
			},
		);

		return $behaviors;
	}
}
