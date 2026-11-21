<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Prints an index.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Build;

defined( 'ABSPATH' ) || exit;

/**
 * The three outputs of an index (slice 610): the data (for JSON), the CSV of a spreadsheet, and the HTML, whose locators are written by a
 * function that the consumer gives (a link to the post on the web, a page number in a book).
 */
final class IndexRenderer {
	/**
	 * The index as data: the groups with their lines, the locators as `post` and `strength`.
	 *
	 * @param IndexModel $model The index.
	 * @return array<int, array{letter:string,entries:array<int, array<string, mixed>>}>
	 */
	public static function to_array( IndexModel $model ) {
		$out = array();

		foreach ( $model->groups() as $letter => $lines ) {
			$out[] = array(
				'letter'  => (string) $letter,
				'entries' => $lines,
			);
		}

		return $out;
	}

	/**
	 * The index as CSV: `letter, label, kind, posts, main` (the posts as ids separated by spaces, `main` the ones that are main).
	 *
	 * @param IndexModel $model The index.
	 * @return string
	 */
	public static function to_csv( IndexModel $model ) {
		$handle = fopen( 'php://temp', 'r+' );

		fputcsv( $handle, array( 'letter', 'label', 'kind', 'posts', 'main' ), ',', '"', '\\' );

		foreach ( $model->lines() as $line ) {
			$posts = array();
			$main  = array();

			foreach ( $line['locators'] as $locator ) {
				$posts[] = $locator['post'];

				if ( 'main' === $locator['strength'] ) {
					$main[] = $locator['post'];
				}
			}

			fputcsv( $handle, array( $line['letter'], $line['label'], $line['kind'], implode( ' ', $posts ), implode( ' ', $main ) ), ',', '"', '\\' );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * The index as HTML.
	 *
	 * @param IndexModel    $model   The index.
	 * @param callable|null $locator `fn( int $post, string $strength ): string` returns the HTML of a locator; default the post number.
	 * @return string
	 */
	public static function to_html( IndexModel $model, $locator = null ) {
		$locator = $locator ?? static fn( $post, $strength ) => 'main' === $strength ? '<strong>' . (int) $post . '</strong>' : (string) (int) $post;
		$html    = '<div class="index">';

		foreach ( $model->groups() as $letter => $lines ) {
			$html .= '<section class="index-group"><h3 class="index-letter">' . esc_html( (string) $letter ) . '</h3><ul class="index-entries">';

			foreach ( $lines as $line ) {
				$classes  = array_map( 'sanitize_html_class', array_merge( array( 'index-entry' ), $line['classes'] ) );
				$locators = array();

				foreach ( $line['locators'] as $item ) {
					$locators[] = (string) $locator( $item['post'], $item['strength'] );
				}

				$html .= '<li class="' . esc_attr( implode( ' ', $classes ) ) . '"><span class="index-label">' . esc_html( $line['label'] ) . '</span> <span class="index-locators">' . implode( ', ', $locators ) . '</span></li>';
			}

			$html .= '</ul></section>';
		}

		return $html . '</div>';
	}
}
