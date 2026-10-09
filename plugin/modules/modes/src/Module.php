<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Modes selected by the query string and relations between templates.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes;

use Otherguise\Core\ModuleInterface;
use Otherguise\Modes\Integration\TriplesIntegration;
use Otherguise\Modes\Mode\ActiveMode;
use Otherguise\Modes\Mode\ModeDefinition;
use Otherguise\Modes\Mode\ModeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the modes module.
 *
 * It declares the modes (`web`, the default, and `print`, selected by `?mode=print` or by the alias `?print`; other modes are declared
 * with the action `modes_register_modes`), finds the mode of the request, registers the entity type `mode` and the predicate
 * `modes/mode` in the Triples module, and adds the class `modes-mode-{slug}` to the body. It changes no template yet.
 */
final class Module implements ModuleInterface {
	/**
	 * Runs an action: receives the hook name and the argument.
	 *
	 * @var callable
	 */
	private $do_action;

	/**
	 * Adds an action or a filter.
	 *
	 * @var callable
	 */
	private $add_action;

	/**
	 * Applies a filter: receives the hook name and the value.
	 *
	 * @var callable
	 */
	private $apply_filters;

	/**
	 * Modes.
	 *
	 * @var ModeRegistry
	 */
	private $modes;

	/**
	 * Mode of the request.
	 *
	 * @var ActiveMode
	 */
	private $active;

	/**
	 * Builds the module.
	 *
	 * @param callable|null $do_action     Runs an action; defaults to WordPress `do_action`.
	 * @param callable|null $add_action    Adds an action or a filter; defaults to WordPress `add_action`.
	 * @param callable|null $query         Returns the query string keys `mode` and the aliases that are present; defaults to a read of the
	 *                                     request with `filter_input_array()`.
	 * @param callable|null $apply_filters Applies a filter; defaults to WordPress `apply_filters`.
	 */
	public function __construct( $do_action = null, $add_action = null, $query = null, $apply_filters = null ) {
		$this->do_action     = $do_action ?? 'do_action';
		$this->add_action    = $add_action ?? 'add_action';
		$this->apply_filters = $apply_filters ?? 'apply_filters';

		$this->modes  = new ModeRegistry(
			function ( $registry ) {
				$registry->register( new ModeDefinition( 'web', __( 'Web', 'modes' ) ) );
				$registry->register( new ModeDefinition( 'print', __( 'Print', 'modes' ), 'print' ) );

				/**
				 * Lets a plugin or a theme declare modes.
				 *
				 * @param ModeRegistry $registry Registry: call `register( new ModeDefinition( $slug, $label, $alias ) )`.
				 */
				( $this->do_action )( 'modes_register_modes', $registry );
			},
			function () {
				/**
				 * Filters the slug of the default mode, the mode of a request that asks for none.
				 *
				 * @param string $slug Slug; `web` by default. A slug that is not registered gives the first mode registered.
				 */
				return ( $this->apply_filters )( 'modes_default_mode', 'web' );
			}
		);
		$this->active = new ActiveMode( $this->modes, $query ?? array( $this, 'read_query' ) );
	}

	/**
	 * Returns the identifier of the module.
	 *
	 * @return string
	 */
	public function id() {
		return 'modes';
	}

	/**
	 * Returns the identifiers of the modules this module needs.
	 *
	 * @return string[]
	 */
	public function dependencies() {
		return array( 'triples' );
	}

	/**
	 * Creates what the module needs when the plugin is activated.
	 *
	 * @return void
	 */
	public function activate() {
		// Nothing to create: the modes are declared in code.
	}

	/**
	 * Registers the hooks of the module: the registrations in the Triples module, the class of the body, the translations.
	 *
	 * @return void
	 */
	public function boot() {
		require_once dirname( __DIR__ ) . '/functions.php';

		$integration = new TriplesIntegration( $this->modes );

		( $this->add_action )( 'triples_register_entity_types', array( $integration, 'register_entity_type' ), 10, 1 );
		( $this->add_action )( 'triples_register_predicates', array( $integration, 'register_predicate' ), 10, 1 );
		( $this->add_action )( 'body_class', array( $this, 'body_class' ), 10, 1 );
		( $this->add_action )( 'init', array( $this, 'load_textdomain' ), 10, 1 );
	}

	/**
	 * Removes the data owned by the module.
	 *
	 * @return void
	 */
	public function uninstall() {
		// Nothing to remove: the module stores nothing itself.
	}

	/**
	 * Returns the registry of the modes.
	 *
	 * @return ModeRegistry
	 */
	public function modes() {
		return $this->modes;
	}

	/**
	 * Returns the finder of the mode of the request.
	 *
	 * @return ActiveMode
	 */
	public function active() {
		return $this->active;
	}

	/**
	 * Adds the class of the mode of the request to the body. Filter `body_class`, priority 10.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		$classes   = is_array( $classes ) ? $classes : array();
		$classes[] = 'modes-mode-' . $this->active->mode()->slug();

		return $classes;
	}

	/**
	 * Loads the translations of the module (text domain `modes`) from the `languages` directory next to its sources.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		if ( defined( 'OTHERGUISE_PLUGIN_FILE' ) ) {
			load_plugin_textdomain( 'modes', false, dirname( plugin_basename( OTHERGUISE_PLUGIN_FILE ) ) . '/modules/modes/languages' );
		}
	}

	/**
	 * Reads the query string: the keys `mode` and the aliases of the registered modes, only those.
	 *
	 * @return array<string, string>
	 */
	public function read_query() {
		$definitions = array( 'mode' => FILTER_UNSAFE_RAW );

		foreach ( $this->modes->all() as $mode ) {
			if ( null !== $mode->alias() ) {
				$definitions[ $mode->alias() ] = FILTER_UNSAFE_RAW;
			}
		}

		$values = filter_input_array( INPUT_GET, $definitions, false );

		return is_array( $values ) ? array_filter( $values, 'is_string' ) : array();
	}
}
