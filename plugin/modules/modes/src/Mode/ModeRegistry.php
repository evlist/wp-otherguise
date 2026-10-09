<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Registry of the modes.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Mode;

defined( 'ABSPATH' ) || exit;

/**
 * The declared modes. The first read runs the initializer, which registers the built-in modes and fires the action
 * `modes_register_modes` with the registry, so that a plugin or a theme can declare its own.
 */
final class ModeRegistry {
	/**
	 * Modes by slug, in the order of registration.
	 *
	 * @var array<string, ModeDefinition>
	 */
	private $modes = array();

	/**
	 * Initializer, or null.
	 *
	 * @var callable|null
	 */
	private $initializer;

	/**
	 * Returns the slug of the default mode.
	 *
	 * @var callable
	 */
	private $default_slug;

	/**
	 * Whether the initializer ran.
	 *
	 * @var bool
	 */
	private $initialized;

	/**
	 * Whether the initializer is running.
	 *
	 * @var bool
	 */
	private $initializing = false;

	/**
	 * Builds the registry.
	 *
	 * @param callable|null $initializer  Called once with the registry before its first read.
	 * @param callable|null $default_slug Returns the slug of the default mode (the mode of a request that asks for none); `web` when null.
	 *                                     A slug that is not registered gives the first mode registered.
	 */
	public function __construct( $initializer = null, $default_slug = null ) {
		$this->initializer  = $initializer;
		$this->initialized  = null === $initializer;
		$this->default_slug = $default_slug ?? static function () {
			return 'web';
		};
	}

	/**
	 * Registers a mode.
	 *
	 * @param ModeDefinition $mode Mode.
	 * @return void
	 * @throws \InvalidArgumentException When the slug or the alias is already used.
	 */
	public function register( ModeDefinition $mode ) {
		if ( isset( $this->modes[ $mode->slug() ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'The mode "%s" is already registered.', esc_html( $mode->slug() ) ) );
		}

		if ( null !== $mode->alias() ) {
			foreach ( $this->modes as $other ) {
				if ( $other->alias() === $mode->alias() ) {
					throw new \InvalidArgumentException( sprintf( 'The alias "%s" is already used.', esc_html( $mode->alias() ) ) );
				}
			}
		}

		$this->modes[ $mode->slug() ] = $mode;
	}

	/**
	 * Tells whether a mode is registered.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function has( $slug ) {
		$this->ensure_initialized();

		return is_string( $slug ) && isset( $this->modes[ $slug ] );
	}

	/**
	 * Returns a mode.
	 *
	 * @param string $slug Slug.
	 * @return ModeDefinition
	 * @throws \InvalidArgumentException When the mode is not registered.
	 */
	public function get( $slug ) {
		if ( ! $this->has( $slug ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown mode "%s".', esc_html( (string) $slug ) ) );
		}

		return $this->modes[ $slug ];
	}

	/**
	 * Returns the modes in the order of registration.
	 *
	 * @return array<string, ModeDefinition>
	 */
	public function all() {
		$this->ensure_initialized();

		return $this->modes;
	}

	/**
	 * Returns the default mode.
	 *
	 * @return ModeDefinition
	 * @throws \LogicException When no mode is registered.
	 */
	public function default_mode() {
		$this->ensure_initialized();

		if ( array() === $this->modes ) {
			throw new \LogicException( 'No mode is registered.' );
		}

		$slug = ( $this->default_slug )();

		return is_string( $slug ) && isset( $this->modes[ $slug ] ) ? $this->modes[ $slug ] : reset( $this->modes );
	}

	/**
	 * Runs the initializer once.
	 *
	 * @return void
	 */
	private function ensure_initialized() {
		if ( $this->initialized || $this->initializing ) {
			return;
		}

		$this->initializing = true;

		try {
			( $this->initializer )( $this );
			$this->initialized = true;
		} finally {
			$this->initializing = false;
		}
	}
}
