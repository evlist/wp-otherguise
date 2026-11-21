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
 * `array{entry:int,label:string,kind:string,classes:string[],letter:string,locators:array<int, array{post:int,strength:string}>}`.
 */
final class IndexModel {
	/**
	 * The lines, sorted.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $lines;

	/**
	 * Builds the model.
	 *
	 * @param array<int, array<string, mixed>> $lines The lines, already sorted.
	 */
	public function __construct( array $lines ) {
		$this->lines = array_values( $lines );
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
