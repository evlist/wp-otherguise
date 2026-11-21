<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What a source says could be indexed.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * A candidate: a label, a kind, the label of the broader entry, the keys that identify it, and how strongly the post speaks of it.
 */
final class Candidate {
	public const MAIN    = 'main';
	public const PASSING = 'passing';

	/**
	 * Label.
	 *
	 * @var string
	 */
	public $label;

	/**
	 * Kind.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Keys that identify it (`term:post_tag:12`, `wikidata:Q42`...), the most reliable first.
	 *
	 * @var string[]
	 */
	public $keys;

	/**
	 * The broader candidate, or null.
	 *
	 * @var Candidate|null
	 */
	public $parent;

	/**
	 * `main` or `passing`.
	 *
	 * @var string
	 */
	public $strength;

	/**
	 * Builds a candidate.
	 *
	 * @param string         $label    Label.
	 * @param string         $kind     Kind.
	 * @param string[]       $keys     Keys.
	 * @param Candidate|null $parent   Broader candidate.
	 * @param string         $strength `main` or `passing`.
	 */
	public function __construct( $label, $kind, array $keys, ?Candidate $parent = null, $strength = self::PASSING ) {
		$this->label    = (string) $label;
		$this->kind     = (string) $kind;
		$this->keys     = array_values( array_unique( array_filter( array_map( 'strval', $keys ), 'strlen' ) ) );
		$this->parent   = $parent;
		$this->strength = self::MAIN === $strength ? self::MAIN : self::PASSING;
	}
}
