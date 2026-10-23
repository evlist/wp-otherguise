<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A refusal of the sections.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a request on a section cannot be done: the code says why, the status is the one of the REST answer.
 */
final class SectionException extends \RuntimeException {
	public const NOT_FOUND = 'books_section_not_found';
	public const FORBIDDEN = 'books_section_forbidden';
	public const BAD_TYPE  = 'books_section_bad_type';
	public const NOT_EMPTY = 'books_section_not_empty';
	public const TOO_MANY  = 'books_section_too_many';
	public const NOT_IN    = 'books_section_not_in';
	public const BAD_MOVE  = 'books_section_bad_move';

	/**
	 * Code of the refusal.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Builds the refusal.
	 *
	 * @param string $error_code One of the constants.
	 * @param string $message    Message for the user.
	 * @param int    $status     HTTP status.
	 */
	public function __construct( $error_code, $message, $status ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->status     = $status;
	}

	/**
	 * Code of the refusal.
	 *
	 * @return string
	 */
	public function error_code() {
		return $this->error_code;
	}

	/**
	 * HTTP status.
	 *
	 * @return int
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * Refuses with the message and the status of a code (throws).
	 *
	 * @param string $error_code One of the constants.
	 * @return never
	 * @throws self Always.
	 */
	public static function refuse( $error_code ) {
		$messages = array(
			self::NOT_FOUND => array( __( 'This post does not exist.', 'otherguise' ), 404 ),
			self::FORBIDDEN => array( __( 'You are not allowed to change this section.', 'otherguise' ), 403 ),
			self::BAD_TYPE  => array( __( 'This type of content cannot be a section.', 'otherguise' ), 400 ),
			self::NOT_EMPTY => array( __( 'This section still contains posts: empty it first.', 'otherguise' ), 409 ),
			self::TOO_MANY  => array( __( 'Too many posts at once.', 'otherguise' ), 400 ),
			self::NOT_IN    => array( __( 'This post is not in this section.', 'otherguise' ), 404 ),
			self::BAD_MOVE  => array( __( 'This move is not possible.', 'otherguise' ), 400 ),
		);

		$exception = new self( $error_code, esc_html( $messages[ $error_code ][0] ), (int) $messages[ $error_code ][1] );

		throw $exception;
	}
}
