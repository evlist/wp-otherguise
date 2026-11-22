<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The kinds of the index, in memory.
 *
 * @package Otherguise
 */

use Otherguise\Index\KindStore;
use Otherguise\Index\Kinds;

/**
 * Stands for the taxonomy `index_kind`. `install()` puts a fresh store, with the built-in kinds, in `Kinds`.
 */
final class Otherguise_Test_Memory_Kinds implements KindStore {
	/**
	 * The kinds by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public $kinds = array();

	/**
	 * What `usage()` answers, by slug: `array( terms, topics )`.
	 *
	 * @var array<string, array{0:int,1:int}>
	 */
	public $used = array();

	/**
	 * What `reassign()` was asked: `array( from, to )`.
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	public $reassigned = array();

	/**
	 * Puts a fresh store with the built-in kinds in `Kinds`.
	 *
	 * @return Otherguise_Test_Memory_Kinds
	 */
	public static function install() {
		$store = new self();

		Kinds::use_store( $store );
		Kinds::seed( true );

		return $store;
	}

	/**
	 * The kinds.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all() {
		return $this->kinds;
	}

	/**
	 * Adds a kind.
	 *
	 * @param string $slug    Slug.
	 * @param string $label   Label.
	 * @param string $parent  Parent.
	 * @param string $css     CSS suffix.
	 * @param bool   $builtin Built-in kind.
	 * @return true
	 */
	public function add( $slug, $label, $parent, $css, $builtin ) {
		$this->kinds[ $slug ] = array(
			'slug'    => $slug,
			'label'   => $label,
			'parent'  => $parent,
			'css'     => $css,
			'builtin' => $builtin,
		);

		return true;
	}

	/**
	 * Changes the label and the class.
	 *
	 * @param string $slug  Slug.
	 * @param string $label Label.
	 * @param string $css   CSS suffix.
	 * @return true
	 */
	public function update( $slug, $label, $css ) {
		$this->kinds[ $slug ]['label'] = $label;
		$this->kinds[ $slug ]['css']   = $css;

		return true;
	}

	/**
	 * Moves a kind.
	 *
	 * @param string $slug   Slug.
	 * @param string $parent Parent.
	 * @return true
	 */
	public function move( $slug, $parent ) {
		$this->kinds[ $slug ]['parent'] = $parent;

		return true;
	}

	/**
	 * Deletes a kind.
	 *
	 * @param string $slug Slug.
	 * @return true
	 */
	public function delete( $slug ) {
		unset( $this->kinds[ $slug ] );

		return true;
	}

	/**
	 * How many terms and topics use a kind.
	 *
	 * @param string $slug Slug.
	 * @return array{terms:int,topics:int}
	 */
	public function usage( $slug ) {
		return array(
			'terms'   => $this->used[ $slug ][0] ?? 0,
			'topics'  => $this->used[ $slug ][1] ?? 0,
		);
	}

	/**
	 * Gives the terms and entries of a kind to another.
	 *
	 * @param string $from Slug.
	 * @param string $to   Slug.
	 * @return int
	 */
	public function reassign( $from, $to ) {
		$this->reassigned[] = array( $from, $to );

		return ( $this->used[ $from ][0] ?? 0 ) + ( $this->used[ $from ][1] ?? 0 );
	}
}
