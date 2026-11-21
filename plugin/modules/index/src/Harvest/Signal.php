<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What a page says about what could be indexed.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Harvest;

defined( 'ABSPATH' ) || exit;

/**
 * A signal read in the HTML of a page (slice 601): a type, a value, where it was met, and what else the reader learnt about it.
 */
final class Signal {
	public const ENTRY          = 'entry';
	public const WIKIPEDIA_LINK = 'wikipedia-link';
	public const WIKIDATA_LINK  = 'wikidata-link';
	public const OSM_OBJECT     = 'osm-object';
	public const GPX_FILE       = 'gpx-file';
	public const IMAGE          = 'image';

	/**
	 * One of the constants above.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * The value: an identifier or a label (`entry`), a title (`wikipedia-link`), `Q42` (`wikidata-link`), `node/123` (`osm-object`), an
	 * address (`gpx-file`, `image`).
	 *
	 * @var string
	 */
	public $value;

	/**
	 * What else is known: `language`, `path`, `kind`, `label`, `sort`, `main`, `identifier`... Only what the page gave.
	 *
	 * @var array<string, mixed>
	 */
	public $data;

	/**
	 * The `id` of the element that carries the signal, when it has one (the anchor of a mention).
	 *
	 * @var string
	 */
	public $anchor;

	/**
	 * Where it was met: `content` (the contents of the post) or `page` (elsewhere in `<main>`, or anywhere for an explicit marker).
	 *
	 * @var string
	 */
	public $scope;

	/**
	 * The attribute it was read from, for the explanations (`href`, `data-index`...).
	 *
	 * @var string
	 */
	public $evidence;

	/**
	 * Builds a signal.
	 *
	 * @param string               $type     Type.
	 * @param string               $value    Value.
	 * @param string               $scope    `content` or `page`.
	 * @param string               $evidence Attribute.
	 * @param array<string, mixed> $data     Extra.
	 * @param string               $anchor   Anchor.
	 */
	public function __construct( $type, $value, $scope, $evidence, array $data = array(), $anchor = '' ) {
		$this->type     = (string) $type;
		$this->value    = (string) $value;
		$this->scope    = (string) $scope;
		$this->evidence = (string) $evidence;
		$this->data     = $data;
		$this->anchor   = (string) $anchor;
	}

	/**
	 * As an array, for JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'type'     => $this->type,
			'value'    => $this->value,
			'scope'    => $this->scope,
			'evidence' => $this->evidence,
			'anchor'   => $this->anchor,
			'data'     => $this->data,
		);
	}
}
