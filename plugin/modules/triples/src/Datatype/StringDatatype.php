<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * String datatype.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Datatype;

defined( 'ABSPATH' ) || exit;

/**
 * A string of at most 191 bytes, the size of the storage column.
 */
final class StringDatatype implements DatatypeInterface {
	/**
	 * Maximum length, in bytes.
	 */
	public const MAX_BYTES = 191;

	/**
	 * Returns the name of the datatype.
	 *
	 * @return string
	 */
	public function name() {
		return 'string';
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
	 * Tells whether a value is a string short enough to be stored.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate( $value ) {
		return is_string( $value ) && strlen( $value ) <= self::MAX_BYTES && 1 === preg_match( '/^[^\p{Cc}]*\z/u', $value );
	}

	/**
	 * Returns the string unchanged.
	 *
	 * @param mixed $value Valid value.
	 * @return string
	 */
	public function normalize( $value ) {
		return (string) $value;
	}
}
