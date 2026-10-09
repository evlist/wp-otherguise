<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Contract of a qualifier type.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Qualifier;

defined( 'ABSPATH' ) || exit;

/**
 * A type of value a qualifier can hold. Values are stored as strings.
 */
interface QualifierTypeInterface {
	/**
	 * Returns the name of the type (for example `integer`).
	 *
	 * @return string
	 */
	public function name();

	/**
	 * Returns the XSD datatype the values map to when exported as RDF (for example `xsd:integer`).
	 *
	 * @return string
	 */
	public function datatype();

	/**
	 * Checks the options given by a qualifier definition.
	 *
	 * @param array<string, mixed> $options Options of the qualifier.
	 * @return void
	 * @throws \InvalidArgumentException When the options are not valid for this type.
	 */
	public function validate_options( array $options );

	/**
	 * Tells whether a value is acceptable.
	 *
	 * @param mixed                $value   Value.
	 * @param array<string, mixed> $options Options of the qualifier.
	 * @return bool
	 */
	public function validate( $value, array $options );

	/**
	 * Returns the canonical string stored for a valid value.
	 *
	 * @param mixed                $value   Valid value.
	 * @param array<string, mixed> $options Options of the qualifier.
	 * @return string
	 */
	public function normalize( $value, array $options );
}
