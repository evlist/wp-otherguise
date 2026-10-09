<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Enumeration qualifier type.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Qualifier;

defined( 'ABSPATH' ) || exit;

/**
 * A string taken from a list given by the qualifier definition (option `values`).
 */
final class EnumType implements QualifierTypeInterface {
	/**
	 * Returns the name of the type.
	 *
	 * @return string
	 */
	public function name() {
		return 'enum';
	}

	/**
	 * Returns the XSD datatype.
	 *
	 * @return string
	 */
	public function datatype() {
		return 'xsd:string';
	}

	/**
	 * Checks that the option `values` is a non-empty list of distinct strings and that no other option is given.
	 *
	 * @param array<string, mixed> $options Options of the qualifier.
	 * @return void
	 * @throws \InvalidArgumentException When the options are not valid.
	 */
	public function validate_options( array $options ) {
		if ( array_keys( $options ) !== array( 'values' ) ) {
			throw new \InvalidArgumentException( 'Qualifier type "enum" needs the option "values", and no other option.' );
		}

		$values = $options['values'];

		if ( ! is_array( $values ) || array() === $values || array_filter( $values, 'is_string' ) !== $values || count( array_unique( $values ) ) !== count( $values ) ) {
			throw new \InvalidArgumentException( 'The option "values" of qualifier type "enum" must be a non-empty list of distinct strings.' );
		}
	}

	/**
	 * Tells whether a value is one of the allowed values.
	 *
	 * @param mixed                $value   Value.
	 * @param array<string, mixed> $options Options of the qualifier.
	 * @return bool
	 */
	public function validate( $value, array $options ) {
		return is_string( $value ) && in_array( $value, $options['values'] ?? array(), true );
	}

	/**
	 * Returns the value unchanged.
	 *
	 * @param mixed                $value   Valid value.
	 * @param array<string, mixed> $options Options of the qualifier.
	 * @return string
	 */
	public function normalize( $value, array $options ) {
		return (string) $value;
	}
}
