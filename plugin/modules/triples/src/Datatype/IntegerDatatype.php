<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integer datatype.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Datatype;

defined( 'ABSPATH' ) || exit;

/**
 * A signed integer, given as an integer or as its decimal string.
 */
final class IntegerDatatype implements DatatypeInterface {
	/**
	 * Returns the name of the datatype.
	 *
	 * @return string
	 */
	public function name() {
		return 'integer';
	}

	/**
	 * Returns the XSD datatype.
	 *
	 * @return string
	 */
	public function datatype() {
		return 'xsd:integer';
	}

	/**
	 * Tells whether a value is an integer, or a decimal string that fits in a PHP integer.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate( $value ) {
		if ( is_int( $value ) ) {
			return true;
		}

		return is_string( $value ) && 1 === preg_match( '/^-?[0-9]+\z/', $value ) && $this->fits( $value );
	}

	/**
	 * Returns the canonical decimal string.
	 *
	 * @param mixed $value Valid value.
	 * @return string
	 */
	public function normalize( $value ) {
		return (string) (int) $value;
	}

	/**
	 * Tells whether a decimal string fits in a PHP integer.
	 *
	 * @param string $value Decimal string.
	 * @return bool
	 */
	private function fits( $value ) {
		return (string) (int) $value === $this->canonical( $value );
	}

	/**
	 * Removes the leading zeros of a decimal string and the sign of zero.
	 *
	 * @param string $value Decimal string.
	 * @return string
	 */
	private function canonical( $value ) {
		$negative = '-' === substr( $value, 0, 1 );
		$digits   = ltrim( ltrim( $value, '-' ), '0' );

		if ( '' === $digits ) {
			return '0';
		}

		return ( $negative ? '-' : '' ) . $digits;
	}
}
