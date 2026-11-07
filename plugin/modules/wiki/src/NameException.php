<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A refusal of a name of the wiki.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a title or a name cannot be used: the code says why. A name that another page holds carries that page.
 */
final class NameException extends \RuntimeException {
	public const EMPTY_TITLE        = 'wiki_name_empty';
	public const BAD_ENCODING       = 'wiki_name_bad_encoding';
	public const CONTROL_CHARACTER  = 'wiki_name_control_character';
	public const FORBIDDEN          = 'wiki_name_forbidden_character';
	public const BAD_SLASH          = 'wiki_name_bad_slash';
	public const TOO_LONG           = 'wiki_name_too_long';
	public const UNKNOWN_SPACE      = 'wiki_name_unknown_space';
	public const TAKEN              = 'wiki_name_taken';
	public const SLASH              = 'wiki_name_slash';
	public const DOTS               = 'wiki_name_dots';
	public const DIGITS             = 'wiki_name_digits';
	public const RESERVED           = 'wiki_name_reserved';
	public const BAD_PARENT         = 'wiki_name_bad_parent';

	/**
	 * Code of the refusal.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * The page that holds the name, for `TAKEN`.
	 *
	 * @var int
	 */
	private $holder;

	/**
	 * Whether the holder has the name as its current name (and not as an alias).
	 *
	 * @var bool
	 */
	private $current;

	/**
	 * Builds the refusal.
	 *
	 * @param string $error_code One of the constants.
	 * @param string $message    Message for the user.
	 * @param int    $holder     The page that holds the name, 0 when there is none.
	 * @param bool   $current    Whether the holder has it as its current name.
	 */
	public function __construct( $error_code, $message, $holder = 0, $current = false ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->holder     = (int) $holder;
		$this->current    = (bool) $current;
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
	 * The page that holds the name (`TAKEN`), 0 otherwise.
	 *
	 * @return int
	 */
	public function holder() {
		return $this->holder;
	}

	/**
	 * Whether the holder has the name as its current name, and not as an alias (`TAKEN`).
	 *
	 * @return bool
	 */
	public function holder_has_it_as_current() {
		return $this->current;
	}

	/**
	 * Refuses with the message of a code (throws). The messages are translated; the holder is for `TAKEN`.
	 *
	 * @param string $error_code One of the constants.
	 * @param int    $holder     The page that holds the name, 0 when there is none.
	 * @param bool   $current    Whether the holder has it as its current name.
	 * @return never
	 * @throws self Always.
	 */
	public static function refuse( $error_code, $holder = 0, $current = false ) {
		$messages = array(
			self::EMPTY_TITLE       => __( 'A title is not empty.', 'otherguise' ),
			self::BAD_ENCODING      => __( 'A title is a string of UTF-8 characters.', 'otherguise' ),
			self::CONTROL_CHARACTER => __( 'A title holds no control character.', 'otherguise' ),
			self::FORBIDDEN         => __( 'A title holds none of the characters # ? % < > [ ] { } |.', 'otherguise' ),
			self::BAD_SLASH         => __( 'A title does not start or end with a slash and has no two slashes in a row.', 'otherguise' ),
			self::TOO_LONG          => __( 'A qualified name is at most 191 bytes in UTF-8.', 'otherguise' ),
			self::UNKNOWN_SPACE     => __( 'There is no such space.', 'otherguise' ),
			self::TAKEN             => __( 'Another page has this name.', 'otherguise' ),
			self::SLASH             => __( 'A title has no slash: it separates the levels.', 'otherguise' ),
			self::DOTS              => __( 'A title is not "." or "..".', 'otherguise' ),
			self::DIGITS            => __( 'A title of the first level is not made only of digits.', 'otherguise' ),
			self::RESERVED          => __( 'A page of the first level cannot have this title: the address belongs to something else.', 'otherguise' ),
			self::BAD_PARENT        => __( 'A page cannot be its own parent.', 'otherguise' ),
		);

		$exception = new self( $error_code, esc_html( $messages[ $error_code ] ), $holder, $current );

		throw $exception;
	}
}
