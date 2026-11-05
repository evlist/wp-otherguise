<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The markers of the headings of a merged page and the manifest of their levels and texts.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

use Otherguise\Books\Pdf\PdfMarkers;

defined( 'ABSPATH' ) || exit;

/**
 * Puts an invisible marker link in every heading of the HTML of a merge and notes the heading (slice 227): the PDF made from the page then says on
 * which page each heading is printed (`PdfMarkers`), and the manifest says what each heading is (its level, its text, the depth of its post).
 */
final class Headings {
	/**
	 * Puts a marker at the start of each heading (`h1` to `h6`) of a unit (slice 227): the page of a unit is the page of its first heading. A
	 * marker at the start of the unit would be left on the page before when the template of the unit forces a page break before its first block,
	 * while a marker **inside** the heading goes with it. The markers are numbered in the unit (`heading/<post id>/<n>`). Scripts, styles, comments and text areas are left alone: the text of a
	 * heading that a script holds is not a heading.
	 *
	 * @param string $html Rendered page of the unit.
	 * @param int    $id   Id of the unit.
	 * @return string
	 */
	public static function of_unit( $html, $id ) {
		$number = 0;

		return self::mark( (string) $html, (int) $id, $number );
	}

	/**
	 * Marks the headings of a unit and notes them in the manifest.
	 *
	 * @param string            $html  Rendered page of the unit.
	 * @param int               $id    Id of the unit.
	 * @param array<int, array> $rows  The headings of the merge (modified).
	 * @param int               $depth Depth of the unit in the section.
	 * @return string
	 */
	public static function unit( $html, $id, array &$rows, $depth ) {
		$number = 0;

		return self::mark( (string) $html, $id, $number, $rows, $depth );
	}

	/**
	 * Puts the markers of the headings in a piece of HTML, numbering from the number given, and notes each heading in `$rows` when it is given.
	 *
	 * @param string                 $html   The HTML.
	 * @param int                    $id     Id of the post.
	 * @param int                    $number The number of the last heading marked before this piece (modified).
	 * @param array<int, array>|null $rows   The headings seen: `unit`, `n`, `level`, `text`, `toc`, `depth` (modified), or null to note nothing.
	 * @param int                    $depth  Depth of the post in the section: 0 for the section itself, 1 for its units.
	 * @return string
	 */
	public static function mark( $html, $id, &$number, &$rows = null, $depth = 0 ) {
		$parts = preg_split( '~(<script\b.*?</script>|<style\b.*?</style>|<textarea\b.*?</textarea>|<!--.*?-->)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts ) {
			return $html;
		}

		foreach ( $parts as $index => $part ) {
			// The odd parts are what the pattern captured: the blocks to leave alone.
			if ( 1 === $index % 2 || false === preg_match_all( '~<h([1-6])(?:\s[^>]*)?>~i', $part, $found, PREG_OFFSET_CAPTURE ) || array() === $found[0] ) {
				continue;
			}

			$out  = '';
			$from = 0;

			foreach ( $found[0] as $at => $tag ) {
				++$number;

				$level = (int) $found[1][ $at ][0];
				$after = $tag[1] + strlen( $tag[0] );
				$out  .= substr( $part, $from, $after - $from ) . '<a class="books-merge-marker" href="' . esc_url( PdfMarkers::address( 'heading/' . $id . '/' . $number ) ) . '" tabindex="-1" aria-hidden="true" style="display:inline-block;width:1px;height:1px;vertical-align:top;margin-bottom:-1px;"></a>';
				$from  = $after;

				if ( is_array( $rows ) ) {
					$close  = stripos( $part, '</h' . $level, $after );
					$inside = false === $close ? '' : substr( $part, $after, $close - $after );
					$rows[] = array(
						'unit'  => $id,
						'n'     => $number,
						'level' => $level,
						'text'  => self::plain( $inside ),
						'toc'   => 1 !== preg_match( '~\bclass\s*=\s*["\'][^"\']*\bno-toc\b~i', $tag[0] ),
						'depth' => $depth,
					);
				}
			}

			$parts[ $index ] = $out . substr( $part, $from );
		}

		return implode( '', $parts );
	}

	/**
	 * The text of a heading: no tag, no entity, the spaces in one.
	 *
	 * @param string $html What is between the tags of the heading.
	 * @return string
	 */
	private static function plain( $html ) {
		return trim( (string) preg_replace( '~\s+~u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * The marker that says which manifest describes the PDF (slice 227): its name is the key of the file where the manifest was written.
	 *
	 * @param string $key Key of the manifest.
	 * @return string
	 */
	public static function manifest_marker( $key ) {
		return '<a class="books-merge-marker" href="' . esc_url( PdfMarkers::address( 'manifest/' . $key ) ) . '" tabindex="-1" aria-hidden="true" style="display:block;width:1px;height:1px;margin:0 0 -1px;overflow:hidden;break-after:avoid;"></a>';
	}
}
