<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Boolean datatype.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Datatype;

defined( 'ABSPATH' ) || exit;

/**
 * A boolean, stored as "1" or "0".
 */
final class BooleanDatatype implements DatatypeInterface {
	/**
	 * Values read as true.
	 *
	 * @var array<int, mixed>
	 */
	private const TRUE_VALUES = array( true, 1, '1', 'true' );

	/**
	 * Values read as false.
	 *
	 * @var array<int, mixed>
	 */
	private const FALSE_VALUES = array( false, 0, '0', 'false' );

	/**
	 * Returns the name of the datatype.
	 *
	 * @return string
	 */
	public function name() {
		return 'boolean';
	}

	/**
	 * Returns the XSD datatype.
	 *
	 * @return string
	 */
	public function datatype() {
		return 'xsd:boolean';
	}

	/**
	 * Tells whether a value reads as a boolean: true, false, 1, 0, and the strings "1", "0", "true" and "false".
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate( $value ) {
		return in_array( $value, self::TRUE_VALUES, true ) || in_array( $value, self::FALSE_VALUES, true );
	}

	/**
	 * Returns "1" for a true value and "0" for a false one.
	 *
	 * @param mixed $value Valid value.
	 * @return string
	 */
	public function normalize( $value ) {
		return in_array( $value, self::TRUE_VALUES, true ) ? '1' : '0';
	}
}
