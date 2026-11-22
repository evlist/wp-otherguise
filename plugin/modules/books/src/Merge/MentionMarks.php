<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The markers of the mentions of a merged page.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

use Otherguise\Books\Pdf\PdfMarkers;

defined( 'ABSPATH' ) || exit;

/**
 * Puts an invisible marker link in front of every element of a unit that stands for a mention (slice 612): the PDF then says on which page each of
 * them is printed, and the index gives that page and not the page of the post. The module of the index says which elements they are, through the
 * filter `index_mention_elements` (no class of that module is named here): it marks them with `data-index-mention`, whose value is what produced the
 * mention. Without that module, or without a mention, nothing is marked.
 */
final class MentionMarks {
	/**
	 * Marks the elements of a unit and notes them.
	 *
	 * @param string            $html  Rendered page of the unit.
	 * @param int               $id    Id of the unit.
	 * @param array<int, array> $marks The marks of the merge: `unit`, `n`, `type`, `value` (modified).
	 * @return string
	 */
	public static function unit( $html, $id, array &$marks ) {
		$html = (string) $html;
		$done = apply_filters( 'index_mention_elements', $html, (int) $id );

		if ( ! is_string( $done ) || false === strpos( $done, 'data-index-mention' ) ) {
			return $html;
		}

		$parts = preg_split( '~(<script\b.*?</script>|<style\b.*?</style>|<textarea\b.*?</textarea>|<!--.*?-->)~is', $done, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts ) {
			return $html;
		}

		$number = 0;

		foreach ( $parts as $index => $part ) {
			// The odd parts are what the pattern captured: the blocks to leave alone.
			if ( 1 === $index % 2 || false === strpos( $part, 'data-index-mention' ) ) {
				continue;
			}

			$parts[ $index ] = (string) preg_replace_callback(
				'~<[a-z][a-z0-9-]*\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*?\sdata-index-mention="([^"]*)"(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i',
				static function ( $found ) use ( $id, &$number, &$marks ) {
					$value   = html_entity_decode( $found[1], ENT_QUOTES, 'UTF-8' );
					$marks[] = array(
						'unit'  => (int) $id,
						'n'     => ++$number,
						'type'  => (string) strtok( $value, ':' ),
						'value' => $value,
					);

					return self::marker( (int) $id, $number ) . $found[0];
				},
				$part
			);
		}

		return implode( '', $parts );
	}

	/**
	 * The marker of a mention: inline, one pixel, in front of the element.
	 *
	 * @param int $id Id of the unit.
	 * @param int $n  Number of the mark in the unit.
	 * @return string
	 */
	public static function marker( $id, $n ) {
		return '<a class="books-merge-marker" href="' . esc_url( PdfMarkers::address( 'mention/' . $id . '/' . $n ) ) . '" tabindex="-1" aria-hidden="true" style="display:inline-block;width:1px;height:1px;vertical-align:top;margin-bottom:-1px;"></a>';
	}

	/**
	 * The marks of the units by post and by what produced the mention: the numbers of the marks, to find their pages in the map.
	 *
	 * @param array<int, array> $marks The marks of the merge.
	 * @return array<int, array<string, int[]>> Post => value => numbers.
	 */
	public static function by_post( array $marks ) {
		$found = array();

		foreach ( $marks as $mark ) {
			$found[ (int) $mark['unit'] ][ (string) $mark['value'] ][] = (int) $mark['n'];
		}

		return $found;
	}
}
