<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Reader of the arguments of a definition.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Predicate;

use Otherguise\Triples\Entity\EntityRef;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and validates the values of a definition array. Error messages start with a label such as `Predicate "modes/has-variant"`.
 */
final class DefinitionReader {
	/**
	 * Definition.
	 *
	 * @var array<string, mixed>
	 */
	private $args;

	/**
	 * Label used in error messages.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Builds the reader.
	 *
	 * @param array<string, mixed> $args  Definition.
	 * @param string               $label Label used in error messages.
	 */
	public function __construct( array $args, $label ) {
		$this->args  = $args;
		$this->label = $label;
	}

	/**
	 * Reads a mandatory non-empty string.
	 *
	 * @param string $key Key.
	 * @return string
	 * @throws \InvalidArgumentException When the value is not a non-empty string.
	 */
	public function required_string( $key ) {
		$value = $this->args[ $key ] ?? '';

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new \InvalidArgumentException( sprintf( '%1$s: "%2$s" must be a non-empty string.', esc_html( $this->label ), esc_html( $key ) ) );
		}

		return $value;
	}

	/**
	 * Reads a list of entity type slugs.
	 *
	 * @param string $key Key.
	 * @return string[]
	 * @throws \InvalidArgumentException When the value is not a list of entity type slugs.
	 */
	public function types( $key ) {
		$value = $this->args[ $key ] ?? array();

		if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
			throw new \InvalidArgumentException( sprintf( '%1$s: "%2$s" must be a list.', esc_html( $this->label ), esc_html( $key ) ) );
		}

		foreach ( $value as $slug ) {
			if ( ! is_string( $slug ) || 1 !== preg_match( EntityRef::TYPE_PATTERN, $slug ) ) {
				throw new \InvalidArgumentException( sprintf( '%1$s: "%2$s" holds an invalid entity type slug.', esc_html( $this->label ), esc_html( $key ) ) );
			}
		}

		return $value;
	}

	/**
	 * Reads a limit: null or a positive integer.
	 *
	 * @param string $key Key.
	 * @return int|null
	 * @throws \InvalidArgumentException When the value is neither null nor a positive integer.
	 */
	public function limit( $key ) {
		$value = $this->args[ $key ] ?? null;

		if ( null !== $value && ( ! is_int( $value ) || $value < 1 ) ) {
			throw new \InvalidArgumentException( sprintf( '%1$s: "%2$s" must be null or a positive integer.', esc_html( $this->label ), esc_html( $key ) ) );
		}

		return $value;
	}

	/**
	 * Reads a boolean flag, false by default.
	 *
	 * @param string $key Key.
	 * @return bool
	 * @throws \InvalidArgumentException When the value is not a boolean.
	 */
	public function flag( $key ) {
		$value = $this->args[ $key ] ?? false;

		if ( ! is_bool( $value ) ) {
			throw new \InvalidArgumentException( sprintf( '%1$s: "%2$s" must be a boolean.', esc_html( $this->label ), esc_html( $key ) ) );
		}

		return $value;
	}

	/**
	 * Reads the IRI, which must be absolute.
	 *
	 * @param string $key Key.
	 * @return string|null
	 * @throws \InvalidArgumentException When the IRI is malformed.
	 */
	public function iri( $key ) {
		$value = $this->args[ $key ] ?? null;

		if ( null === $value ) {
			return null;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9+.\-]*:[^\s<>"{}|\\\\^`]+\z/', $value ) ) {
			throw new \InvalidArgumentException( sprintf( '%s: "iri" must be an absolute IRI.', esc_html( $this->label ) ) );
		}

		return $value;
	}

	/**
	 * Reads the qualifiers.
	 *
	 * @param string $key Key.
	 * @return QualifierDefinition[]
	 * @throws \InvalidArgumentException When the qualifiers are not a list of definitions.
	 */
	public function qualifiers( $key ) {
		$value = $this->args[ $key ] ?? array();

		if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
			throw new \InvalidArgumentException( sprintf( '%s: "qualifiers" must be a list.', esc_html( $this->label ) ) );
		}

		$qualifiers = array();

		foreach ( $value as $qualifier ) {
			if ( is_array( $qualifier ) ) {
				$qualifier = new QualifierDefinition( $qualifier );
			}

			if ( ! $qualifier instanceof QualifierDefinition ) {
				throw new \InvalidArgumentException( sprintf( '%s: each qualifier must be an array or a QualifierDefinition.', esc_html( $this->label ) ) );
			}

			$qualifiers[] = $qualifier;
		}

		return $qualifiers;
	}
}
