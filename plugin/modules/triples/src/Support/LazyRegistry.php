<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Base of the registries filled lazily by an initializer.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A keyed registry whose initializer runs once, the first time the registry is read or written.
 *
 * The initializer receives the registry. Registering from inside the initializer is allowed.
 */
abstract class LazyRegistry {
	/**
	 * Registered items, by key, in registration order.
	 *
	 * @var array<string, mixed>
	 */
	private $items = array();

	/**
	 * Initializer, or null.
	 *
	 * @var callable|null
	 */
	private $initializer;

	/**
	 * Whether the initializer has been started.
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
	 * @param callable|null $initializer Called once with the registry before its first use.
	 */
	public function __construct( $initializer = null ) {
		$this->initializer = $initializer;
		$this->initialized = null === $initializer;
	}

	/**
	 * Tells whether an item is registered.
	 *
	 * @param string $key Item key.
	 * @return bool
	 */
	public function has( $key ) {
		$this->ensure_initialized();

		return isset( $this->items[ $key ] );
	}

	/**
	 * Returns every item, by key, in registration order.
	 *
	 * @return array<string, mixed>
	 */
	public function all() {
		$this->ensure_initialized();

		return $this->items;
	}

	/**
	 * Runs the initializer if it has not run yet.
	 *
	 * @return void
	 */
	protected function ensure_initialized() {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized  = true;
		$this->initializing = true;

		try {
			( $this->initializer )( $this );
		} finally {
			$this->initializing = false;
		}
	}

	/**
	 * Tells whether the initializer is running, so that checks needing every registration can wait.
	 *
	 * @return bool
	 */
	protected function is_initializing() {
		return $this->initializing;
	}

	/**
	 * Stores an item without running the initializer.
	 *
	 * @param string $key   Item key.
	 * @param mixed  $item  Item.
	 * @param string $label Name of the kind of item, for the error message.
	 * @return void
	 * @throws \InvalidArgumentException When the key is already registered.
	 */
	protected function add( $key, $item, $label ) {
		if ( isset( $this->items[ $key ] ) ) {
			throw new \InvalidArgumentException( sprintf( '%1$s "%2$s" is already registered.', esc_html( $label ), esc_html( $key ) ) );
		}

		$this->items[ $key ] = $item;
	}

	/**
	 * Returns an item.
	 *
	 * @param string $key   Item key.
	 * @param string $label Name of the kind of item, for the error message.
	 * @return mixed
	 * @throws \InvalidArgumentException When the key is not registered.
	 */
	protected function item( $key, $label ) {
		$this->ensure_initialized();

		if ( ! isset( $this->items[ $key ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown %1$s "%2$s".', esc_html( strtolower( $label ) ), esc_html( $key ) ) );
		}

		return $this->items[ $key ];
	}
}
