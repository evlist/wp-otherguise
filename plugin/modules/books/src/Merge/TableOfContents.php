<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The entries and the HTML of a table of contents made from the manifest of a merge.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

defined( 'ABSPATH' ) || exit;

/**
 * The table of contents of a section (slice 228), made from the headings that a merge noted (the manifest: `unit`, `n`, `level`, `text`, `toc`,
 * `depth`) and, in print, from the page of each (the map of the pages that a first pass of the job reads in the PDF).
 */
final class TableOfContents {
	/**
	 * What a number of page is until the pages are known: as wide as the numbers will be.
	 */
	public const PLACEHOLDER = '000';

	/**
	 * What a number of page is when the pages are known and the heading is not in the PDF (it is not printed): a dash.
	 */
	public const MISSING = '–';

	/**
	 * The entries of the table: the headings that a table lists, with the level of their entry.
	 *
	 * The level of an entry is the depth of its unit (1 for the units of the section, 2 for the units of a part, ...; the headings of the page of
	 * the section itself count as depth 1) plus the difference between the level of the heading and the level of the first heading of its post (its
	 * title): the title of a unit is an entry of the level of its depth, a subtitle `h2` under an `h1` title is one level below.
	 *
	 * @param array<int, array> $rows   The headings of the manifest, in the order of the document.
	 * @param int               $levels The deepest level listed; 0 for all of them.
	 * @return array<int, array{unit:int, n:int, level:int, text:string}> The entries: `level` is the level of the entry.
	 */
	public static function entries( array $rows, $levels ) {
		$first   = array();
		$entries = array();

		foreach ( $rows as $row ) {
			$unit = (int) ( $row['unit'] ?? 0 );

			if ( ! isset( $first[ $unit ] ) ) {
				$first[ $unit ] = (int) ( $row['level'] ?? 1 );
			}

			if ( isset( $row['toc'] ) && false === (bool) $row['toc'] ) {
				continue;
			}

			$level = max( 1, (int) ( $row['depth'] ?? 1 ) ) + (int) ( $row['level'] ?? 1 ) - $first[ $unit ];
			$level = max( 1, $level );

			if ( $levels > 0 && $level > $levels ) {
				continue;
			}

			$text = trim( (string) ( $row['text'] ?? '' ) );

			if ( '' === $text ) {
				continue;
			}

			$entries[] = array(
				'unit'  => $unit,
				'n'     => (int) ( $row['n'] ?? 0 ),
				'level' => $level,
				'text'  => $text,
			);
		}

		return $entries;
	}

	/**
	 * The HTML of the table.
	 *
	 * @param array<int, array{unit:int, n:int, level:int, text:string}> $entries  The entries.
	 * @param bool                                                       $numbered In print: the number of the page of each entry, at the right; else a link.
	 * @param array<string, int>                                         $pages    The page of each heading (`<post>#<n>`); the placeholder when it is not known.
	 * @param callable                                                   $link     Receives the id of a post, returns its address (when not numbered).
	 * @return string An empty string when there is no entry.
	 */
	public static function render( array $entries, $numbered, array $pages, $link ) {
		if ( array() === $entries ) {
			return '';
		}

		$html = '<ul class="books-toc">';

		foreach ( $entries as $entry ) {
			$text = esc_html( $entry['text'] );

			if ( $numbered ) {
				$page   = $pages[ $entry['unit'] . '#' . $entry['n'] ] ?? null;
				$number = null !== $page ? (string) (int) $page : ( array() === $pages ? self::PLACEHOLDER : self::MISSING );
				$html  .= '<li class="books-toc__entry books-toc__level-' . (int) $entry['level'] . '"><span class="books-toc__text">' . $text . '</span><span class="books-toc__leader" aria-hidden="true"></span><span class="books-toc__page">' . esc_html( $number ) . '</span></li>';
				continue;
			}

			$address = (string) $link( $entry['unit'] );
			$html   .= '<li class="books-toc__entry books-toc__level-' . (int) $entry['level'] . '">' . ( '' === $address ? $text : '<a href="' . esc_url( $address ) . '">' . $text . '</a>' ) . '</li>';
		}

		return $html . '</ul>';
	}
}
