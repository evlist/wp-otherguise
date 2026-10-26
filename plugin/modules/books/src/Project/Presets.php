<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Starting points for printer profiles.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Project;

defined( 'ABSPATH' ) || exit;

/**
 * Two profiles to start from, written from the documentation of the printers on 2026-10-26: **starting points to check against the printer's
 * site**, which changes (formats, bindings, limits). The format is the author's choice; the page counts depend on the binding, so none is set.
 */
final class Presets {
	/**
	 * The presets, by slug: a title and the fields of a profile.
	 *
	 * @return array<string, array{title:string, values:array<string, mixed>}>
	 */
	public static function all() {
		return array(
			'coollibri'  => array(
				'title'  => 'CoolLibri',
				'values' => array(
					'width_mm'       => 148,
					'height_mm'      => 210,
					'margin_mm'      => 10,
					'gutter_mm'      => 10,
					'safe_mm'        => 20,
					'bleed_mm'       => 0,
					'folio'          => 'outside',
					'pages_multiple' => 2,
					'final_blank'    => true,
					'cover'          => 'combined',
					'min_ppi'        => 300,
					'colour'         => 'cmyk',
					'max_mb'         => 700,
					'url'            => 'https://www.coollibri.com/faq',
					'read_on'        => '2026-10-26',
				),
			),
			'flexilivre' => array(
				'title'  => 'Flexilivre',
				'values' => array(
					'width_mm'       => 150,
					'height_mm'      => 210,
					'margin_mm'      => 10,
					'gutter_mm'      => 10,
					'safe_mm'        => 5,
					'bleed_mm'       => 0,
					'folio'          => 'outside',
					'pages_multiple' => 2,
					'cover'          => 'first_last',
					'min_ppi'        => 0,
					'colour'         => 'rgb',
					'max_mb'         => 3000,
					'url'            => 'https://www.flexilivre.com/fichier/',
					'read_on'        => '2026-10-26',
				),
			),
		);
	}

	/**
	 * A preset as a normalized profile, or null when there is none of that name.
	 *
	 * @param string $slug Slug.
	 * @return array<string, mixed>|null
	 */
	public static function profile( $slug ) {
		$all = self::all();

		return isset( $all[ $slug ] ) ? PrinterProfile::normalize( $all[ $slug ]['values'] ) : null;
	}
}
