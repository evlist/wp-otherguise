<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Registry of datatypes.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Datatype;

use Otherguise\Triples\Support\LazyRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Datatypes by name.
 */
final class DatatypeRegistry extends LazyRegistry {
	/**
	 * Builds a registry holding the built-in datatypes: string, integer and boolean.
	 *
	 * @param callable|null $initializer Called once with the registry before its first use, to register other datatypes.
	 * @return self
	 */
	public static function with_builtins( $initializer = null ) {
		$registry = new self( $initializer );

		foreach ( array( new StringDatatype(), new IntegerDatatype(), new BooleanDatatype() ) as $datatype ) {
			$registry->add( $datatype->name(), $datatype, 'Datatype' );
		}

		return $registry;
	}

	/**
	 * Registers a datatype.
	 *
	 * @param DatatypeInterface $datatype Datatype.
	 * @return void
	 */
	public function register( DatatypeInterface $datatype ) {
		$this->ensure_initialized();
		$this->add( $datatype->name(), $datatype, 'Datatype' );
	}

	/**
	 * Returns a datatype.
	 *
	 * @param string $name Datatype name.
	 * @return DatatypeInterface
	 */
	public function get( $name ) {
		return $this->item( $name, 'Datatype' );
	}
}
