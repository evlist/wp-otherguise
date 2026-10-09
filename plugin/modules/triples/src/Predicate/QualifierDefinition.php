<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Definition of a qualifier.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Predicate;

defined( 'ABSPATH' ) || exit;

/**
 * A qualifier a predicate may carry: a name, a type, and whether it is multiple or required.
 */
final class QualifierDefinition {
	/**
	 * Names that would clash with the columns of a statement.
	 */
	private const RESERVED_NAMES = array( 'id', 'subject', 'predicate', 'object', 'position' );

	/**
	 * Name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Type name.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Whether several values may be given.
	 *
	 * @var bool
	 */
	private $multiple;

	/**
	 * Whether a value is required.
	 *
	 * @var bool
	 */
	private $required;

	/**
	 * Options of the type (for an enum, `values`).
	 *
	 * @var array<string, mixed>
	 */
	private $options = array();

	/**
	 * Builds a qualifier definition.
	 *
	 * Keys: `name` and `type` (required), `multiple` and `required` (booleans, default false), `values` (list of strings, for the enum type).
	 *
	 * @param array<string, mixed> $args Definition.
	 * @throws \InvalidArgumentException When a key is unknown or a value malformed.
	 */
	public function __construct( array $args ) {
		$unknown = array_diff( array_keys( $args ), array( 'name', 'type', 'multiple', 'required', 'values' ) );

		if ( array() !== $unknown ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown qualifier key "%s".', esc_html( (string) reset( $unknown ) ) ) );
		}

		$name = $args['name'] ?? '';

		if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z][a-z0-9_]*\z/', $name ) || in_array( $name, self::RESERVED_NAMES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid qualifier name "%s".', esc_html( is_string( $name ) ? $name : '' ) ) );
		}

		$type = $args['type'] ?? '';

		if ( ! is_string( $type ) || 1 !== preg_match( '/^[a-z][a-z0-9_]*\z/', $type ) ) {
			throw new \InvalidArgumentException( sprintf( 'Qualifier "%s" needs a valid type.', esc_html( $name ) ) );
		}

		foreach ( array( 'multiple', 'required' ) as $flag ) {
			if ( isset( $args[ $flag ] ) && ! is_bool( $args[ $flag ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Qualifier "%1$s": "%2$s" must be a boolean.', esc_html( $name ), esc_html( $flag ) ) );
			}
		}

		$this->name     = $name;
		$this->type     = $type;
		$this->multiple = $args['multiple'] ?? false;
		$this->required = $args['required'] ?? false;

		if ( array_key_exists( 'values', $args ) ) {
			$this->options = array( 'values' => $args['values'] );
		}
	}

	/**
	 * Returns the name.
	 *
	 * @return string
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * Returns the name of the qualifier type.
	 *
	 * @return string
	 */
	public function type() {
		return $this->type;
	}

	/**
	 * Tells whether several values may be given.
	 *
	 * @return bool
	 */
	public function is_multiple() {
		return $this->multiple;
	}

	/**
	 * Tells whether a value is required.
	 *
	 * @return bool
	 */
	public function is_required() {
		return $this->required;
	}

	/**
	 * Returns the options passed to the qualifier type.
	 *
	 * @return array<string, mixed>
	 */
	public function options() {
		return $this->options;
	}
}
