<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Exception raised when a statement already exists.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * The triple is already stored. The statement that holds it is available to the caller.
 */
final class DuplicateStatementException extends \RuntimeException {
	/**
	 * Statement that already holds the triple.
	 *
	 * @var Statement|null
	 */
	private $existing;

	/**
	 * Builds the exception for a triple that is already stored.
	 *
	 * @param Statement|null $existing Statement that already holds the triple, when it could be read.
	 * @return self
	 */
	public static function for_existing( ?Statement $existing ) {
		$exception           = new self( 'The statement already exists.' );
		$exception->existing = $existing;

		return $exception;
	}

	/**
	 * Returns the statement that already holds the triple, or null.
	 *
	 * @return Statement|null
	 */
	public function existing() {
		return $this->existing;
	}
}
