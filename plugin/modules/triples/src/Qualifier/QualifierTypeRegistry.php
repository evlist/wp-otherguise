<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Registry of qualifier types.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Qualifier;

use Otherguise\Triples\Support\LazyRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Qualifier types by name.
 */
final class QualifierTypeRegistry extends LazyRegistry {
	/**
	 * Builds a registry holding the built-in types: string, integer, boolean and enum.
	 *
	 * @param callable|null $initializer Called once with the registry before its first use, to register other types.
	 * @return self
	 */
	public static function with_builtins( $initializer = null ) {
		$registry = new self( $initializer );

		foreach ( array( new StringType(), new IntegerType(), new BooleanType(), new EnumType() ) as $type ) {
			$registry->add( $type->name(), $type, 'Qualifier type' );
		}

		return $registry;
	}

	/**
	 * Registers a qualifier type.
	 *
	 * @param QualifierTypeInterface $type Qualifier type.
	 * @return void
	 */
	public function register( QualifierTypeInterface $type ) {
		$this->ensure_initialized();
		$this->add( $type->name(), $type, 'Qualifier type' );
	}

	/**
	 * Returns a qualifier type.
	 *
	 * @param string $name Type name.
	 * @return QualifierTypeInterface
	 */
	public function get( $name ) {
		return $this->item( $name, 'Qualifier type' );
	}
}
