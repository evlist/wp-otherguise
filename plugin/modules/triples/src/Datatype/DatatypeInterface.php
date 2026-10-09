<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Contract of a datatype.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Datatype;

defined( 'ABSPATH' ) || exit;

/**
 * A type of literal that can be the object of a statement. Values are stored as strings.
 *
 * The name of a datatype shares a namespace with the slugs of the entity types.
 */
interface DatatypeInterface {
	/**
	 * Returns the name of the datatype (for example `integer`).
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
	 * Tells whether a value is acceptable.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate( $value );

	/**
	 * Returns the canonical string stored for a valid value.
	 *
	 * @param mixed $value Valid value.
	 * @return string
	 */
	public function normalize( $value );
}
