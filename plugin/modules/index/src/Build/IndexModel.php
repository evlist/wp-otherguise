<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The lines of an index.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Build;

defined( 'ABSPATH' ) || exit;

/**
 * What the builder makes (slice 610): the lines of an index, sorted, and their groups by letter. A line is
 * `array{topic:int,label:string,kind:string,classes:string[],letter:string,locators:array<int, array{post:int,strength:string,sources:string[],via:string[]}>}`.
 */
final class IndexModel {
	/**
	 * The lines, sorted.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $lines;

	/**
	 * What the lines owe to the services of others: `osm` (a place read from OpenStreetMap) and `wikimedia` (an identifier, a label or a kind
	 * of Wikidata or a link to Wikipedia).
	 *
	 * @var array{osm:bool,wikimedia:bool}
	 */
	private $attribution;

	/**
	 * Builds the model.
	 *
	 * @param array<int, array<string, mixed>> $lines       The lines, already sorted.
	 * @param array{osm?:bool,wikimedia?:bool} $attribution What the lines owe to others.
	 */
	public function __construct( array $lines, array $attribution = array() ) {
		$this->lines       = array_values( $lines );
		$this->attribution = array(
			'osm'       => ! empty( $attribution['osm'] ),
			'wikimedia' => ! empty( $attribution['wikimedia'] ),
		);
	}

	/**
	 * What the lines owe to the services of others (slice 611: one notice for the index).
	 *
	 * @return array{osm:bool,wikimedia:bool}
	 */
	public function attribution() {
		return $this->attribution;
	}

	/**
	 * The lines.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function lines() {
		return $this->lines;
	}

	/**
	 * The lines grouped by letter, in order.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function groups() {
		$groups = array();

		foreach ( $this->lines as $line ) {
			$groups[ $line['letter'] ][] = $line;
		}

		return $groups;
	}

	/**
	 * Whether there is no line.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return array() === $this->lines;
	}
}
