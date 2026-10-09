<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Literal object of a statement.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable pair (datatype, value), the value being already normalized by its datatype.
 *
 * The value check here is the one of the storage column (191 bytes); the datatype checks the meaning (slice 103).
 */
final class Literal implements NodeInterface {
	/**
	 * Maximum length of a value, in bytes.
	 */
	public const MAX_BYTES = 191;

	/**
	 * Datatype name.
	 *
	 * @var string
	 */
	private $datatype;

	/**
	 * Normalized value.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Builds a literal.
	 *
	 * @param string $datatype Datatype name.
	 * @param string $value    Normalized value.
	 * @throws \InvalidArgumentException When the datatype name is malformed or the value too long.
	 */
	public function __construct( $datatype, $value ) {
		if ( 1 !== preg_match( EntityRef::TYPE_PATTERN, (string) $datatype ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid datatype name "%s".', esc_html( (string) $datatype ) ) );
		}

		if ( strlen( (string) $value ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'A literal value is limited to 191 bytes.' );
		}

		$this->datatype = (string) $datatype;
		$this->value    = (string) $value;
	}

	/**
	 * Returns the datatype name.
	 *
	 * @return string
	 */
	public function type() {
		return $this->datatype;
	}

	/**
	 * Returns the value.
	 *
	 * @return string
	 */
	public function key() {
		return $this->value;
	}

	/**
	 * Tells whether another node is the same literal.
	 *
	 * @param NodeInterface $other Other node.
	 * @return bool
	 */
	public function equals( NodeInterface $other ) {
		return $other instanceof self && $this->datatype === $other->datatype && $this->value === $other->value;
	}
}
