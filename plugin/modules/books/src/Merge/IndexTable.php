<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The HTML of the index of a book.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

defined( 'ABSPATH' ) || exit;

/**
 * The index of a section (slice 611), laid out from the data that the module of the index gives (the lines in groups by letter, each with its
 * locators: a post and a strength) and, in print, from the page of the first heading of each post (the map of the pages that the job reads in the PDF).
 *
 * - **In print** a locator is a **page number**: consecutive pages are collapsed (`12–14`), a **main** mention is in bold (a run is broken where the
 *   boldness changes), the pages are in numeric order; **a placeholder** until the pages are known; a post that is not in the PDF has no number.
 * - **Elsewhere** a locator is a **link to the post**, with its title.
 * - One **notice** ends the index (slice 611): the sources that the lines owe to others, OpenStreetMap and the Wikimedia Foundation.
 */
final class IndexTable {
	/**
	 * What a number of page is until the pages are known.
	 */
	public const PLACEHOLDER = '000';

	/**
	 * What the locators of a line are when the pages are known and none of its posts is in the PDF.
	 */
	public const MISSING = '–';

	/**
	 * The HTML of the index.
	 *
	 * @param array{groups:array<string,array>,attribution:array{osm:bool,wikimedia:bool}} $data    What the module of the index gave.
	 * @param bool                                                                         $numbered In print: page numbers; else links.
	 * @param array<int, string>                                                           $first   The key of the first heading of each post, by post (`<post>#<n>`).
	 * @param array<string, int>                                                           $pages   The page of each heading; empty until they are known.
	 * @param callable                                                                     $link    Receives a post id, returns its address.
	 * @param callable                                                                     $title   Receives a post id, returns its title.
	 * @param array<string, mixed>                                                         $options `title` and `attribution` (`auto`, `yes`, `no`).
	 * @param array<int, array<string, int[]>>                                             $marks   The marks of the mentions by post and by `via` (slice 612): their pages are in `$pages` under `m:<post>#<n>`.
	 * @return string An empty string when there is no line.
	 */
	public static function render( array $data, $numbered, array $first, array $pages, $link, $title, array $options = array(), array $marks = array() ) {
		$groups = (array) ( $data['groups'] ?? array() );

		if ( array() === $groups ) {
			return '';
		}

		$html = '<div class="books-index">';

		if ( '' !== trim( (string) ( $options['title'] ?? '' ) ) ) {
			$html .= '<h2 class="books-index__title no-toc">' . esc_html( trim( (string) $options['title'] ) ) . '</h2>';
		}

		foreach ( $groups as $letter => $lines ) {
			$html .= '<section class="books-index__group"><h3 class="books-index__letter no-toc">' . esc_html( (string) $letter ) . '</h3><ul class="books-index__entries">';

			foreach ( (array) $lines as $line ) {
				$classes  = array_map( 'sanitize_html_class', array_merge( array( 'books-index__entry' ), (array) ( $line['classes'] ?? array() ) ) );
				$locators = $numbered ? self::pages_of( (array) $line['locators'], $first, $pages, $marks ) : self::links_of( (array) $line['locators'], $link, $title );
				$html    .= '<li class="' . esc_attr( implode( ' ', $classes ) ) . '"><span class="books-index__label">' . esc_html( (string) $line['label'] ) . '</span> <span class="books-index__locators">' . $locators . '</span></li>';
			}

			$html .= '</ul></section>';
		}

		return $html . self::notice( (array) ( $data['attribution'] ?? array() ), (string) ( $options['attribution'] ?? 'auto' ) ) . '</div>';
	}

	/**
	 * The page numbers of a line: its pages in order, the consecutive ones collapsed, a main page in bold.
	 *
	 * The page of a locator is the page of the marker of the element that shows the topic (slice 612); a locator with no marker is the page of its post
	 * (its first heading). When the merge has marks, the two kinds of runs are in a span (`index-locator-element`, `index-locator-post`) that a book can style.
	 *
	 * @param array<int, array{post:int,strength:string,via?:string[]}> $locators The locators.
	 * @param array<int, string>                                        $first    The key of the first heading of each post.
	 * @param array<string, int>                                        $pages    The pages; empty until known.
	 * @param array<int, array<string, int[]>>                          $marks    The marks by post and by `via`.
	 * @return string
	 */
	private static function pages_of( array $locators, array $first, array $pages, array $marks = array() ) {
		if ( array() === $pages ) {
			return esc_html( self::PLACEHOLDER );
		}

		$found = array();

		foreach ( $locators as $locator ) {
			$post   = (int) $locator['post'];
			$main   = 'main' === $locator['strength'];
			$own    = array();
			$kind   = 'element';
			$numbers = array();

			foreach ( (array) ( $locator['via'] ?? array() ) as $via ) {
				$numbers = array_merge( $numbers, $marks[ $post ][ (string) $via ] ?? array() );
			}

			foreach ( $numbers as $number ) {
				if ( isset( $pages[ 'm:' . $post . '#' . $number ] ) ) {
					$own[] = (int) $pages[ 'm:' . $post . '#' . $number ];
				}
			}

			if ( array() === $own ) {
				$kind = 'post';
				$key  = $first[ $post ] ?? '';

				if ( '' !== $key && isset( $pages[ $key ] ) ) {
					$own[] = (int) $pages[ $key ];
				}
			}

			foreach ( $own as $page ) {
				$found[ $page ][ $kind ] = ( $found[ $page ][ $kind ] ?? false ) || $main;
			}
		}

		if ( array() === $found ) {
			return esc_html( self::MISSING );
		}

		ksort( $found );

		$runs = array();

		foreach ( $found as $page => $kinds ) {
			foreach ( $kinds as $kind => $main ) {
				$last = array() === $runs ? null : $runs[ count( $runs ) - 1 ];

				if ( null !== $last && $last['to'] + 1 === $page && $last['main'] === $main && $last['kind'] === $kind ) {
					$runs[ count( $runs ) - 1 ]['to'] = $page;
				} else {
					$runs[] = array(
						'from' => $page,
						'to'   => $page,
						'main' => $main,
						'kind' => $kind,
					);
				}
			}
		}

		$classed = array() !== $marks;

		return implode(
			', ',
			array_map(
				static function ( $run ) use ( $classed ) {
					$text = esc_html( $run['from'] === $run['to'] ? (string) $run['from'] : $run['from'] . '–' . $run['to'] );
					$text = $run['main'] ? '<strong class="books-index__main">' . $text . '</strong>' : $text;

					return $classed ? '<span class="index-locator-' . $run['kind'] . '">' . $text . '</span>' : $text;
				},
				$runs
			)
		);
	}

	/**
	 * The links of a line, outside print: the title of each post, a main one in bold.
	 *
	 * @param array<int, array{post:int,strength:string}> $locators The locators.
	 * @param callable                                    $link     Post id to address.
	 * @param callable                                    $title    Post id to title.
	 * @return string
	 */
	private static function links_of( array $locators, $link, $title ) {
		$items = array();

		foreach ( $locators as $locator ) {
			$text    = esc_html( (string) $title( (int) $locator['post'] ) );
			$address = (string) $link( (int) $locator['post'] );
			$item    = '' === $address ? $text : '<a href="' . esc_url( $address ) . '">' . $text . '</a>';
			$items[] = 'main' === $locator['strength'] ? '<strong class="books-index__main">' . $item . '</strong>' : $item;
		}

		return implode( ', ', $items );
	}

	/**
	 * The note that stands for an index that cannot be made because an option of its block is wrong.
	 *
	 * @param string[] $errors What is wrong.
	 * @return string
	 */
	public static function error( array $errors ) {
		/* translators: %s: what is wrong with the options of the block. */
		return '<p class="books-index__error" style="border:1px solid #b32d2e;color:#b32d2e;padding:.4em .6em;">' . esc_html( sprintf( __( 'Index not made: %s', 'otherguise' ), implode( ' ', array_map( 'strval', $errors ) ) ) ) . '</p>';
	}

	/**
	 * The notice of the index: one line, with links, naming what the lines owe to OpenStreetMap and to the Wikimedia projects. `auto` shows it only
	 * when the lines owe something, `yes` always (both credits), `no` never. The filter `books_index_attribution` changes the wording.
	 *
	 * @param array<string, bool> $owed The credits: `osm`, `wikimedia`.
	 * @param string              $mode `auto`, `yes` or `no`.
	 * @return string
	 */
	public static function notice( array $owed, $mode ) {
		if ( 'no' === $mode ) {
			return '';
		}

		$owed = 'yes' === $mode ? array(
			'osm'       => true,
			'wikimedia' => true,
		) : array(
			'osm'       => ! empty( $owed['osm'] ),
			'wikimedia' => ! empty( $owed['wikimedia'] ),
		);
		$text = array();

		if ( $owed['osm'] ) {
			/* translators: %s: link "© OpenStreetMap contributors". */
			$text[] = sprintf( __( 'Places: %s.', 'otherguise' ), '<a href="https://www.openstreetmap.org/copyright">© OpenStreetMap contributors</a>' );
		}

		if ( $owed['wikimedia'] ) {
			/* translators: %s: link "Wikidata". */
			$text[] = sprintf( __( 'Identifiers and names: %s, Wikimedia Foundation.', 'otherguise' ), '<a href="https://www.wikidata.org/">Wikidata</a>' );
		}

		if ( array() === $text ) {
			return '';
		}

		return '<p class="books-index__attribution">' . wp_kses_post( (string) apply_filters( 'books_index_attribution', implode( ' ', $text ), $owed ) ) . '</p>';
	}
}
