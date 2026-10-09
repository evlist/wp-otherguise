<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Refusal of a statement.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Service;

defined( 'ABSPATH' ) || exit;

/**
 * A statement that the checks of `Statements` refuse. The `error_code()` is stable and meant for screens and the REST API, which
 * translate it; the message is for developers.
 */
final class InvalidStatementException extends \InvalidArgumentException {
	public const UNKNOWN_PREDICATE          = 'unknown_predicate';
	public const UNKNOWN_ENTITY             = 'unknown_entity';
	public const AMBIGUOUS_ENTITY           = 'ambiguous_entity';
	public const UNKNOWN_TYPE               = 'unknown_type';
	public const INVALID_ID                 = 'invalid_id';
	public const SUBJECT_TYPE_NOT_ALLOWED   = 'subject_type_not_allowed';
	public const OBJECT_TYPE_NOT_ALLOWED    = 'object_type_not_allowed';
	public const INVALID_VALUE              = 'invalid_value';
	public const AMBIGUOUS_LITERAL          = 'ambiguous_literal';
	public const SUBJECT_MISSING            = 'subject_missing';
	public const OBJECT_MISSING             = 'object_missing';
	public const TOO_MANY_OBJECTS           = 'too_many_objects';
	public const TOO_MANY_SUBJECTS          = 'too_many_subjects';
	public const QUALIFICATION_NOT_ALLOWED  = 'qualification_not_allowed';

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
