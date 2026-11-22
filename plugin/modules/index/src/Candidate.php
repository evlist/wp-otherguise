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
 * A candidate: a label, a kind, the label of the broader topic, the keys that identify it, and how strongly the post speaks of it.
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
	 * The source of the mention (a path of slice 605: `post/link`), null for the one of the harvester.
	 *
	 * @var string|null
	 */
	public $source = null;

	/**
	 * What produced the mention (`link:fr:Têt (fleuve)`, `image:<hash>`), several values allowed.
	 *
	 * @var string[]
	 */
	public $via = array();

	/**
	 * The modes in which the mention is shown, empty for every mode.
	 *
	 * @var string[]
	 */
	public $modes = array();

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

	/**
	 * Gives the candidate its source, what produced it and the modes in which it is shown.
	 *
	 * @param string   $source The source (a path).
	 * @param string[] $via    What produced it.
	 * @param string[] $modes  The modes in which it is shown, empty for every mode.
	 * @return static
	 */
	public function from( $source, array $via = array(), array $modes = array() ) {
		$this->source = (string) $source;
		$this->via    = array_values( array_unique( array_filter( array_map( 'strval', $via ), 'strlen' ) ) );
		$this->modes  = array_values( array_unique( array_filter( array_map( 'strval', $modes ), 'strlen' ) ) );

		return $this;
	}
}
