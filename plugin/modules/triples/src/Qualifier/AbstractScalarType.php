<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Base of the qualifier types that take no option.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Qualifier;

defined( 'ABSPATH' ) || exit;

/**
 * Rejects any option.
 */
abstract class AbstractScalarType implements QualifierTypeInterface {
	/**
	 * Checks that no option is given.
	 *
	 * @param array<string, mixed> $options Options of the qualifier.
	 * @return void
	 * @throws \InvalidArgumentException When an option is given.
	 */
	public function validate_options( array $options ) {
		if ( array() !== $options ) {
			throw new \InvalidArgumentException( sprintf( 'Qualifier type "%s" takes no option.', esc_html( $this->name() ) ) );
		}
	}
}
