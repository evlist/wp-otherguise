<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Where the kinds are kept.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The store of the kinds (slice 603). A kind is a record: slug (its identifier), label, parent (the slug of the broader kind, '' for none),
 * the suffix of its CSS class and whether it is a starter kind (protected). The WordPress implementation keeps them as the terms of the
 * hidden taxonomy `index_kind`; the tests use one in memory. Every method that writes returns `true` or the reason it failed.
 */
interface KindStore {
	/**
	 * The kinds, by slug.
	 *
	 * @return array<string, array{slug:string,label:string,parent:string,css:string,builtin:bool}>
	 */
	public function all();

	/**
	 * Adds a kind.
	 *
	 * @param string $slug    Slug.
	 * @param string $label   Label.
	 * @param string $parent  Slug of the broader kind, '' for none.
	 * @param string $css     Suffix of the CSS class.
	 * @param bool   $builtin Whether it is a starter kind.
	 * @return true|string
	 */
	public function add( $slug, $label, $parent, $css, $builtin );

	/**
	 * Changes the label and the CSS class of a kind.
	 *
	 * @param string $slug  Slug.
	 * @param string $label Label.
	 * @param string $css   CSS suffix.
	 * @return true|string
	 */
	public function update( $slug, $label, $css );

	/**
	 * Moves a kind under another.
	 *
	 * @param string $slug   Slug.
	 * @param string $parent Slug of the new broader kind, '' for none.
	 * @return true|string
	 */
	public function move( $slug, $parent );

	/**
	 * Deletes a kind (the checks are made by `Kinds`).
	 *
	 * @param string $slug Slug.
	 * @return true|string
	 */
	public function delete( $slug );

	/**
	 * How many terms (categories, tags) and entries have a kind.
	 *
	 * @param string $slug Slug.
	 * @return array{terms:int,entries:int}
	 */
	public function usage( $slug );

	/**
	 * Gives the terms and the entries of a kind to another.
	 *
	 * @param string $from Slug.
	 * @param string $to   Slug.
	 * @return int The number of terms and entries changed.
	 */
	public function reassign( $from, $to );
}
