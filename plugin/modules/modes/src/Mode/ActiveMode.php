<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The mode of the request.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Mode;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the mode a request asks for, once.
 *
 * `?mode=print` selects the mode `print`; the presence of the alias of a mode (`?print`) selects it too; `mode` wins over an alias, and
 * among several aliases the mode registered first wins. A value that is not a registered slug is ignored. Without any of them the
 * request is in the default mode. Only registered slugs and aliases are ever used, so the query string never designates anything else.
 */
final class ActiveMode {
	/**
	 * Registry.
	 *
	 * @var ModeRegistry
	 */
	private $modes;

	/**
	 * Reads the query string: returns the values of the keys `mode` and of the aliases that are present, as strings.
	 *
	 * @var callable
	 */
	private $query;

	/**
	 * Mode found, or null before the first call.
	 *
	 * @var ModeDefinition|null
	 */
	private $mode = null;

	/**
	 * Whether the request asked for its mode.
	 *
	 * @var bool
	 */
	private $explicit = false;

	/**
	 * Builds the finder.
	 *
	 * @param ModeRegistry $modes Registry.
	 * @param callable     $query  Returns the query string values of `mode` and of the aliases (a list of the keys that exist is enough).
	 */
	public function __construct( ModeRegistry $modes, $query ) {
		$this->modes = $modes;
		$this->query = $query;
	}

	/**
	 * Returns the mode of the request.
	 *
	 * @return ModeDefinition
	 */
	public function mode() {
		if ( null === $this->mode ) {
			$this->detect();
		}

		return $this->mode;
	}

	/**
	 * Tells whether the request asked for its mode, by `mode` or by an alias, as opposed to being in the default mode.
	 *
	 * @return bool
	 */
	public function is_explicit() {
		$this->mode();

		return $this->explicit;
	}

	/**
	 * Forgets the result (for the tests, or after the registry changed).
	 *
	 * @return void
	 */
	public function reset() {
		$this->mode     = null;
		$this->explicit = false;
	}

	/**
	 * Looks at the query string.
	 *
	 * @return void
	 */
	private function detect() {
		$values = ( $this->query )();
		$values = is_array( $values ) ? $values : array();
		$slug   = $values['mode'] ?? null;

		if ( is_string( $slug ) && $this->modes->has( $slug ) ) {
			$this->mode     = $this->modes->get( $slug );
			$this->explicit = true;

			return;
		}

		foreach ( $this->modes->all() as $mode ) {
			if ( null !== $mode->alias() && array_key_exists( $mode->alias(), $values ) && ( null === $values[ $mode->alias() ] || is_string( $values[ $mode->alias() ] ) ) ) {
				$this->mode     = $mode;
				$this->explicit = true;

				return;
			}
		}

		$this->mode = $this->modes->default_mode();
	}
}
