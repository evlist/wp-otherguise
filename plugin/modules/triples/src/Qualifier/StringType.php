<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * String qualifier type.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Qualifier;

defined( 'ABSPATH' ) || exit;

/**
 * A string of at most 255 characters.
 */
final class StringType extends AbstractScalarType {
	/**
	 * Maximum length, in characters.
	 */
	public const MAX_LENGTH = 255;

	/**
	 * Returns the name of the type.
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
	 * @param mixed                $value   Value.
	 * @param array<string, mixed> $options Options of the qualifier.
	 * @return bool
	 */
	public function validate( $value, array $options ) {
		return is_string( $value ) && mb_strlen( $value, 'UTF-8' ) <= self::MAX_LENGTH;
	}

	/**
	 * Returns the string unchanged.
	 *
	 * @param mixed                $value   Valid value.
	 * @param array<string, mixed> $options Options of the qualifier.
	 * @return string
	 */
	public function normalize( $value, array $options ) {
		return (string) $value;
	}
}
