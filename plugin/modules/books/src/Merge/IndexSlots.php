<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The indexes that the blocks of a page ask for.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

defined( 'ABSPATH' ) || exit;

/**
 * The slots of the blocks `books/section-index` of a page (slice 611): a block leaves a marker where its index goes and notes its options; once the
 * units are known the merge replaces each marker by the index of the posts of the merge, in their order. The lines come from the module of the
 * index **through the filter `index_build`** (this module does not know its classes: a site without the module of the index gets no index, and
 * the markers are removed).
 */
final class IndexSlots {
	/**
	 * The start of the marker: the number of the slot follows, then ` -->`.
	 */
	public const MARKER = '<!-- books-section-index:';

	/**
	 * The slots: the options of each block and whether its pages are numbers.
	 *
	 * @var array<int, array{options:array<string,mixed>,numbered:bool}>
	 */
	private $slots = array();

	/**
	 * Receives a post id, returns its address.
	 *
	 * @var callable
	 */
	private $link;

	/**
	 * Receives a post id, returns its title.
	 *
	 * @var callable
	 */
	private $title;

	/**
	 * Receives the posts and the options, returns the data of the index or null.
	 *
	 * @var callable
	 */
	private $data;

	/**
	 * Tells whether the person who sees the page may be told that an option of a block is wrong (in print everybody may: a PDF is not public).
	 *
	 * @var callable
	 */
	private $may_see_errors;

	/**
	 * Builds the slots.
	 *
	 * @param callable      $link           Post id to address.
	 * @param callable|null $title          Post id to title; default `get_the_title`.
	 * @param callable|null $data           The data of the index; default the filter `index_build`.
	 * @param callable|null $may_see_errors Whether the viewer may see an error note; default: who can edit posts.
	 */
	public function __construct( $link, $title = null, $data = null, $may_see_errors = null ) {
		$this->may_see_errors = $may_see_errors ?? static fn() => current_user_can( 'edit_posts' );
		$this->link  = $link;
		$this->title = $title ?? static fn( $id ) => (string) get_the_title( (int) $id );
		$this->data  = $data ?? static fn( $posts, $options ) => apply_filters( 'index_build', null, $posts, $options );
	}

	/**
	 * Notes a block and returns the marker that goes in the page.
	 *
	 * @param array<string, mixed> $options  `include`, `exclude`, `rollup`, `context`, `title`, `attribution`.
	 * @param bool                 $numbered Whether the pages are numbers (print) or links.
	 * @return string
	 */
	public function slot( array $options, $numbered ) {
		$this->slots[] = array(
			'options'  => $options,
			'numbered' => (bool) $numbered,
		);

		return self::MARKER . ( count( $this->slots ) - 1 ) . ' -->';
	}

	/**
	 * How many indexes have page numbers: the job needs a second pass for them, as for the tables of contents.
	 *
	 * @return int
	 */
	public function numbered() {
		return count( array_filter( $this->slots, static fn( $slot ) => $slot['numbered'] ) );
	}

	/**
	 * Replaces the markers of a piece of the page by the indexes; without units the markers are removed.
	 *
	 * @param string             $page  A piece of the page.
	 * @param int[]              $units The posts of the merge, in order.
	 * @param array<int, array>  $rows  The headings of the merge, in the order of the document.
	 * @param array<string, int> $pages The page of each heading (`<post>#<n>`) and of each mark (`m:<post>#<n>`), empty until known.
	 * @param array<int, array>  $marks The marks of the mentions in the units (slice 612).
	 * @return string
	 */
	public function fill( $page, array $units, array $rows, array $pages, array $marks = array() ) {
		if ( array() === $this->slots || false === strpos( $page, self::MARKER ) ) {
			return $page;
		}

		$first = array();

		foreach ( $rows as $row ) {
			$unit = (int) ( $row['unit'] ?? 0 );

			if ( ! isset( $first[ $unit ] ) ) {
				$first[ $unit ] = $unit . '#' . (int) ( $row['n'] ?? 0 );
			}
		}

		foreach ( $this->slots as $index => $slot ) {
			if ( false === strpos( $page, self::MARKER . $index . ' -->' ) ) {
				continue;
			}

			$data = array() === $units ? null : ( $this->data )(
				$units,
				array(
					'include' => (string) ( $slot['options']['include'] ?? '' ),
					'exclude' => (string) ( $slot['options']['exclude'] ?? '' ),
					'rollup'  => (string) ( $slot['options']['rollup'] ?? '' ),
					'context' => ! array_key_exists( 'context', $slot['options'] ) || (bool) $slot['options']['context'],
				)
			);
			if ( is_array( $data ) && array() !== (array) ( $data['errors'] ?? array() ) ) {
				// The options of the block are wrong: say so in print and to the people who can fix it, never an index that is silently empty.
				$data = $slot['numbered'] || ( $this->may_see_errors )() ? $data : null;
				$page = str_replace( self::MARKER . $index . ' -->', null === $data ? '' : IndexTable::error( (array) $data['errors'] ), $page );

				continue;
			}

			$html = is_array( $data ) ? IndexTable::render( $data, $slot['numbered'], $first, $pages, $this->link, $this->title, $slot['options'], MentionMarks::by_post( $marks ) ) : '';
			$page = str_replace( self::MARKER . $index . ' -->', $html, $page );
		}

		return $page;
	}
}
