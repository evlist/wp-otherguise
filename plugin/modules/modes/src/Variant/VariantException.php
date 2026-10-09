<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Refusal of a variant.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Variant;

defined( 'ABSPATH' ) || exit;

/**
 * A variant that the rules of the modes refuse (the statements checks of Triples are `InvalidStatementException`). The `error_code()` is
 * stable.
 */
final class VariantException extends \InvalidArgumentException {
	public const NOT_A_TEMPLATE      = 'not_a_template';
	public const KIND_MISMATCH       = 'kind_mismatch';
	public const SAME_TEMPLATE       = 'same_template';
	public const THEME_MISMATCH      = 'theme_mismatch';
	public const NOT_A_MODE          = 'not_a_mode';
	public const MODE_ALREADY_SERVED = 'mode_already_served';

	/**
	 * Error code.
	 *
	 * @var string
	 */
	private $error_code = '';

	/**
	 * Builds the exception.
	 *
	 * @param string $error_code One of the constants of the class.
	 * @param string $message    Message for developers.
	 */
	public function __construct( $error_code, $message ) {
		parent::__construct( $message );

		$this->error_code = $error_code;
	}

	/**
	 * Throws the exception: the one place where its message is escaped.
	 *
	 * @param string $error_code One of the constants of the class.
	 * @param string $message    Message for developers.
	 * @return never
	 * @throws self Always.
	 */
	public static function refuse( $error_code, $message ) {
		$exception = new self( $error_code, esc_html( $message ) );

		throw $exception;
	}

	/**
	 * Returns the error code.
	 *
	 * @return string
	 */
	public function error_code() {
		return $this->error_code;
	}
}
