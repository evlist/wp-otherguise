<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Loads the modules in dependency order.
 *
 * @package Otherguise
 */

namespace Otherguise\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Orders the modules so that a module always comes after the modules it depends on.
 */
final class ModuleLoader {
	/**
	 * Modules in dependency order, dependencies first.
	 *
	 * @var ModuleInterface[]
	 */
	private $ordered = array();

	/**
	 * Builds the loader and validates the dependencies.
	 *
	 * @param ModuleInterface[] $modules All the known modules.
	 * @param string[]|null     $enabled Identifiers of the enabled modules, or null for all of them.
	 * @throws \InvalidArgumentException When an identifier is duplicated or unknown, or when an enabled module needs a module that is not enabled. A dependency cycle raises a LogicException.
	 */
	public function __construct( array $modules, $enabled = null ) {
		$by_id = array();

		foreach ( $modules as $module ) {
			if ( isset( $by_id[ $module->id() ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Duplicate module "%s".', esc_html( $module->id() ) ) );
			}

			$by_id[ $module->id() ] = $module;
		}

		if ( null !== $enabled ) {
			foreach ( $enabled as $id ) {
				if ( ! isset( $by_id[ $id ] ) ) {
					throw new \InvalidArgumentException( sprintf( 'Unknown module "%s".', esc_html( $id ) ) );
				}
			}

			$by_id = array_intersect_key( $by_id, array_flip( $enabled ) );
		}

		$this->ordered = $this->sort( $by_id );
	}

	/**
	 * Returns the identifiers of the modules in dependency order.
	 *
	 * @return string[]
	 */
	public function order() {
		return array_map(
			static function ( ModuleInterface $module ) {
				return $module->id();
			},
			$this->ordered
		);
	}

	/**
	 * Boots the modules, dependencies first.
	 *
	 * @return void
	 */
	public function boot() {
		foreach ( $this->ordered as $module ) {
			$module->boot();
		}
	}

	/**
	 * Lets the modules remove their data, dependents first.
	 *
	 * @return void
	 */
	public function uninstall() {
		foreach ( array_reverse( $this->ordered ) as $module ) {
			$module->uninstall();
		}
	}

	/**
	 * Sorts the modules topologically.
	 *
	 * @param array<string, ModuleInterface> $by_id Modules by identifier.
	 * @return ModuleInterface[]
	 * @throws \InvalidArgumentException When a module needs a module that is not available.
	 * @throws \LogicException           When the dependencies form a cycle.
	 */
	private function sort( array $by_id ) {
		$ordered = array();
		$state   = array();

		foreach ( array_keys( $by_id ) as $id ) {
			$this->visit( $id, $by_id, $state, $ordered );
		}

		return $ordered;
	}

	/**
	 * Depth-first visit of one module.
	 *
	 * @param string                         $id      Module identifier.
	 * @param array<string, ModuleInterface> $by_id   Modules by identifier.
	 * @param array<string, int>             $state   1 while visiting, 2 when done.
	 * @param ModuleInterface[]              $ordered Result, filled in dependency order.
	 * @return void
	 * @throws \InvalidArgumentException When a module needs a module that is not available.
	 * @throws \LogicException           When the dependencies form a cycle.
	 */
	private function visit( $id, array $by_id, array &$state, array &$ordered ) {
		if ( 2 === ( $state[ $id ] ?? 0 ) ) {
			return;
		}

		if ( 1 === ( $state[ $id ] ?? 0 ) ) {
			throw new \LogicException( sprintf( 'Circular dependency involving module "%s".', esc_html( $id ) ) );
		}

		$state[ $id ] = 1;

		foreach ( $by_id[ $id ]->dependencies() as $dependency ) {
			if ( ! isset( $by_id[ $dependency ] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Module "%1$s" needs module "%2$s", which is not available.', esc_html( $id ), esc_html( $dependency ) )
				);
			}

			$this->visit( $dependency, $by_id, $state, $ordered );
		}

		$state[ $id ] = 2;
		$ordered[]    = $by_id[ $id ];
	}
}
