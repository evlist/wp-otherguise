<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The address of a page in a mode.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Link;

use Otherguise\Modes\Mode\ModeDefinition;
use Otherguise\Modes\Mode\ModeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the address of a page in a given mode: `mode=slug`, or nothing for the default mode. Pure string work, no WordPress function.
 */
final class ModeUrl {
	/**
	 * Returns the address in the mode.
	 *
	 * Any `mode` argument and the alias of any registered mode (`print`) are removed, the other arguments and the fragment are kept as
	 * they were written.
	 *
	 * @param string         $url    Address of the page.
	 * @param ModeDefinition $target Target mode.
	 * @param ModeRegistry   $modes  Modes.
	 * @return string
	 */
	public static function build( $url, ModeDefinition $target, ModeRegistry $modes ) {
		$fragment = '';
		$hash     = strpos( $url, '#' );

		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		$query = '';
		$mark  = strpos( $url, '?' );

		if ( false !== $mark ) {
			$query = substr( $url, $mark + 1 );
			$url   = substr( $url, 0, $mark );
		}

		$removed = array( 'mode' );

		foreach ( $modes->all() as $mode ) {
			if ( null !== $mode->alias() ) {
				$removed[] = $mode->alias();
			}
		}

		$pairs = array();

		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$key = urldecode( explode( '=', $pair, 2 )[0] );

			if ( ! in_array( $key, $removed, true ) ) {
				$pairs[] = $pair;
			}
		}

		if ( $target->slug() !== $modes->default_mode()->slug() ) {
			$pairs[] = 'mode=' . rawurlencode( $target->slug() );
		}

		return $url . ( array() === $pairs ? '' : '?' . implode( '&', $pairs ) ) . $fragment;
	}
}
