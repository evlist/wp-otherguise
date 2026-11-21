<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A small bag of options.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Wikidata;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes options through two functions, so that the pieces of the enrichment can be tested without WordPress.
 */
final class Options {
	/**
	 * Reads an option: `fn( string $name, mixed $default ): mixed`.
	 *
	 * @var callable
	 */
	private $read;

	/**
	 * Writes an option: `fn( string $name, mixed $value ): void`.
	 *
	 * @var callable
	 */
	private $write;

	/**
	 * Builds the bag.
	 *
	 * @param callable|null $read  Default `get_option`.
	 * @param callable|null $write Default `update_option`, not autoloaded.
	 */
	public function __construct( $read = null, $write = null ) {
		$this->read  = $read ?? 'get_option';
		$this->write = $write ?? static function ( $name, $value ) {
			update_option( $name, $value, false );
		};
	}

	/**
	 * Reads an option.
	 *
	 * @param string $name          Name.
	 * @param mixed  $default_value Value when there is none.
	 * @return mixed
	 */
	public function get( $name, $default_value = false ) {
		return ( $this->read )( $name, $default_value );
	}

	/**
	 * Writes an option.
	 *
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function set( $name, $value ) {
		( $this->write )( $name, $value );
	}
}
