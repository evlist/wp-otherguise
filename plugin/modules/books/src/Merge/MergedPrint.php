<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The print page of a section as the concatenation of its units.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

use Otherguise\Books\Section\Sections;

defined( 'ABSPATH' ) || exit;

/**
 * Option 4 of `docs/design/books.md` (slice 231): merge the HTML of the units before the pagination, so that one PDF of a section has the pages
 * of all its units.
 *
 * **Only the block `books/section-merge` causes a merge** (slice 235), in the modes it names (all of them by default): the page of a section
 * then shows, **where the block is** (in front of the footer when the block's marker is not seen in time), one after the other, the page of each
 * unit in the mode of the request (the template that the Modes module would give that unit, rendered for it), each in a `div` (`books-merge-unit`) that adds **no page break**: the templates of the theme
 * decide where a unit starts a page, so that a book of texts can run chapters on; a unit that is a section is followed by its own units. Only published units that have no password. It is **asked for**, never given to
 * a visitor: see `MergeAccess` (a user who can edit the section, or the PDF job with its token). The preview of the editor merges only the first
 * `PREVIEW_UNITS` units (the filter `books_merge_preview_limit`), with a note; the PDF has them all. At most `MAX_UNITS` units and, by default,
 * ten minutes of PHP.
 */
final class MergedPrint {
	/**
	 * Name of the query argument, of the custom field of the section and of the option that stop the merge after a number of units.
	 */
	public const LIMIT = 'books_merge_limit';

	/**
	 * Name of the query argument that gives the key of the map of the pages (slice 228): the page of each heading, read in the PDF of the first pass
	 * of the job, that the tables of contents print.
	 */
	public const PAGES = 'books_pages';

	/**
	 * Name of the query argument that marks the preview of the editor.
	 */
	public const PREVIEW = 'books_preview';

	/**
	 * Deepest level of sections followed.
	 */
	public const DEPTH = 6;

	/**
	 * The number of units that the preview of the editor merges: enough to scroll through the first pages, few enough to be quick.
	 */
	public const PREVIEW_UNITS = 10;

	/**
	 * The most units of a merge.
	 */
	public const MAX_UNITS = 1000;

	/**
	 * The sections.
	 *
	 * @var Sections
	 */
	private $sections;

	/**
	 * Who may have a section merged.
	 *
	 * @var MergeAccess
	 */
	private $access;

	/**
	 * The log.
	 *
	 * @var MergeLog
	 */
	private $log;

	/**
	 * Describes the request: `section` (the id of the single post shown, 0 for none), `preview` (the preview of
	 * the editor) and `limit` (the number of units asked for in the address).
	 *
	 * @var callable
	 */
	private $request;

	/**
	 * Receives a post id; returns the HTML of that unit.
	 *
	 * @var callable
	 */
	private $render;

	/**
	 * Receives a post id; returns `status` and `password` of the post, or null when it does not exist.
	 *
	 * @var callable
	 */
	private $post_of;

	/**
	 * The HTML of the units, once they are rendered.
	 *
	 * @var string|null
	 */
	private $html = null;

	/**
	 * Whether the units were put in front of the output.
	 *
	 * @var bool
	 */
	private $inserted = false;

	/**
	 * The marker that the block `books/section-merge` leaves in the page where the units go (slice 235).
	 */
	public const MARKER = '<!-- books-section-merge -->';

	/**
	 * The start of the marker that the block `books/section-toc` leaves where its table of contents goes (slice 228): the number of the table
	 * follows, then ` -->`.
	 */
	public const TOC_MARKER = '<!-- books-section-toc:';

	/**
	 * Whether the block left the marker in the page.
	 *
	 * @var bool
	 */
	private $slot = false;

	/**
	 * The headings of the units of the last merge, in order (the manifest, slice 227).
	 *
	 * @var array<int, array>
	 */
	private $rows = array();

	/**
	 * The tables of contents that blocks asked for: `levels`, `numbered`, in the order of the markers (slice 228).
	 *
	 * @var array<int, array{levels:int, numbered:bool}>
	 */
	private $tocs = array();

	/**
	 * Returns the address of a post, for a table of contents that is not numbered.
	 *
	 * @var callable
	 */
	private $link;

	/**
	 * The units of the last merge, in order, with their depth (the manifest says which were merged).
	 *
	 * @var array<int, array{id:int, depth:int}>
	 */
	private $unit_list = array();

	/**
	 * Writes a manifest: receives its key (32 hexadecimal digits) and its JSON, returns whether it was written.
	 *
	 * @var callable|null
	 */
	private $manifest_writer;

	/**
	 * Reads a map of the pages: receives its key, returns its JSON or null.
	 *
	 * @var callable|null
	 */
	private $pages_reader;

	/**
	 * Id of the section whose block asked for the merge (the headings of its own page are marked with it).
	 *
	 * @var int
	 */
	private $section_id = 0;

	/**
	 * Whether an output buffer waits to put the units at the marker.
	 *
	 * @var bool
	 */
	private $placing = false;

	/**
	 * Largest number of units.
	 *
	 * @var int
	 */
	private $limit = self::MAX_UNITS;

	/**
	 * Units rendered in the current merge.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Id of the unit being rendered, 0 outside a unit.
	 *
	 * @var int
	 */
	private $current = 0;

	/**
	 * Whether a merge is going on (a unit that renders a section is not merged again: the recursion is done here).
	 *
	 * @var bool
	 */
	private $busy = false;

	/**
	 * Whether the merge stopped at its limit with units left.
	 *
	 * @var bool
	 */
	private $truncated = false;

	/**
	 * Start of the merge, for the log of an aborted one.
	 *
	 * @var float
	 */
	private $started = 0.0;

	/**
	 * Builds the merge.
	 *
	 * @param Sections      $sections The sections.
	 * @param MergeAccess   $access   Who may have a section merged.
	 * @param MergeLog      $log      The log.
	 * @param callable|null $request  Describes the request; defaults to `MergeRequest`.
	 * @param callable|null $render   Renders a unit; defaults to `UnitRenderer`.
	 * @param callable|null $post_of  Reads a post; defaults to `get_post()`.
	 * @param callable|null $manifest Writes a manifest (key, JSON); none by default: no manifest.
	 * @param callable|null $pages    Reads a map of the pages (key): its JSON or null; none by default: the numbers of the tables are placeholders.
	 */
	public function __construct( Sections $sections, MergeAccess $access, MergeLog $log, $request = null, $render = null, $post_of = null, $manifest = null, $pages = null ) {
		$this->manifest_writer = $manifest;
		$this->pages_reader    = $pages;
		$this->link            = static fn( $id ) => (string) get_permalink( (int) $id );
		$this->sections = $sections;
		$this->access   = $access;
		$this->log      = $log;
		$this->request  = $request ?? array( new MergeRequest(), 'read' );
		$this->render   = $render ?? array( new UnitRenderer(), 'render' );
		$this->post_of  = $post_of ?? static function ( $id ) {
			$post = get_post( (int) $id );

			return $post instanceof \WP_Post ? array(
				'status'   => (string) $post->post_status,
				'password' => (string) $post->post_password,
			) : null;
		};
	}

	/**
	 * Tells whether a merge is going on: a unit that renders a section does not merge its units again.
	 *
	 * @return bool
	 */
	public function is_busy() {
		return $this->busy;
	}

	/**
	 * Asks for the units to be put where the block `books/section-merge` is (slice 235): returns the marker to leave there, or an empty
	 * string when the page is not the single page of that section, is not allowed to merge, holds nothing, a merge is going on, or an earlier
	 * block of the page already asked (the first block merges; the units are the same for all of them). **Only a block that asks causes a
	 * merge** (the footer does nothing otherwise).
	 *
	 * @param int $post_id Id of the post of the block, 0 when unknown.
	 * @return string
	 */
	public function slot( $post_id = 0 ) {
		$request = (array) ( $this->request )();
		$section = (int) ( $request['section'] ?? 0 );

		if ( $this->busy || $this->slot || $section <= 0 || ( $post_id > 0 && (int) $post_id !== $section ) ) {
			return '';
		}

		if ( ! $this->access->allowed( $section ) || array() === $this->sections->order( $section ) ) {
			return '';
		}

		$this->slot       = true;
		$this->section_id = $section;

		return self::MARKER;
	}

	/**
	 * Asks for a table of contents (slice 228): returns the marker to leave where it goes, or an empty string when the page is not the single page
	 * of that section, may not be merged, holds nothing, or a merge is going on. The table is made when the page is placed, from the headings of
	 * the merge, so it needs the block that merges on the same page.
	 *
	 * @param int  $post_id  Id of the post of the block, 0 when unknown.
	 * @param int  $levels   The deepest level listed, 0 for all.
	 * @param bool $numbered Whether the entries have the number of their page (print) or a link.
	 * @return string
	 */
	public function toc_slot( $post_id, $levels, $numbered ) {
		$request = (array) ( $this->request )();
		$section = (int) ( $request['section'] ?? 0 );

		if ( $this->busy || $section <= 0 || ( $post_id > 0 && (int) $post_id !== $section ) ) {
			return '';
		}

		if ( ! $this->access->allowed( $section ) || array() === $this->sections->order( $section ) ) {
			return '';
		}

		$this->tocs[]     = array(
			'levels'   => max( 0, (int) $levels ),
			'numbered' => (bool) $numbered,
		);
		$this->section_id = $section;

		return self::TOC_MARKER . ( count( $this->tocs ) - 1 ) . ' -->';
	}

	/**
	 * Starts the output buffer that puts the units at the marker, when the template had a block that left one: the template is rendered
	 * before `wp_head`, so the marker is known, and what follows is buffered until the footer has rendered the units. Action `wp_head`, last.
	 *
	 * @return void
	 */
	public function head() {
		if ( ! $this->slot || $this->placing ) {
			return;
		}

		$this->placing = true;

		ob_start( array( $this, 'place' ) );
	}

	/**
	 * Output callback: replaces the marker by the units and puts a marker in each heading of the page of the section (slice 227); without a marker
	 * (it was not in the page) the units go in front of the end of the body, and without units the marker is removed.
	 *
	 * @param string $buffer The page.
	 * @return string
	 */
	public function place( $buffer ) {
		$html     = null === $this->html ? '' : $this->html;
		$position = strpos( $buffer, self::MARKER );
		$number   = 0;
		$before   = array();
		$after    = array();

		if ( false !== $position ) {
			$this->inserted = true;

			// The headings of the page of the section itself get their markers; those of the units already have theirs.
			$head = Headings::mark( substr( $buffer, 0, $position ), $this->section_id, $number, $before );
			$tail = Headings::mark( substr( $buffer, $position + strlen( self::MARKER ) ), $this->section_id, $number, $after );
			$rows = array_merge( $before, $this->rows, $after );

			return $this->tables( $head, $rows, $html ) . $this->manifest( $html, $before, $after ) . $html . $this->tables( $tail, $rows, $html );
		}

		$buffer = $this->tables( Headings::mark( $buffer, $this->section_id, $number, $before ), array_merge( $before, $this->rows ), $html );
		$end    = '' === $html ? false : strrpos( $buffer, '</body>' );

		if ( false === $end ) {
			return $buffer . $html;
		}

		$this->inserted = true;

		return substr_replace( $buffer, $this->manifest( $html, $before, array() ) . $html, $end, 0 );
	}

	/**
	 * Replaces the markers of the tables of contents in a piece of the page by the tables, made from the headings of the merge; without units the
	 * markers are removed.
	 *
	 * @param string            $page  A piece of the page.
	 * @param array<int, array> $rows  The headings of the page of the section and of the units, in the order of the document.
	 * @param string            $units The units.
	 * @return string
	 */
	private function tables( $page, array $rows, $units ) {
		if ( array() === $this->tocs || false === strpos( $page, self::TOC_MARKER ) ) {
			return $page;
		}

		$pages = $this->pages();

		foreach ( $this->tocs as $index => $toc ) {
			$html = '' === $units ? '' : TableOfContents::render( TableOfContents::entries( $rows, $toc['levels'] ), $toc['numbered'], $pages, $this->link );
			$page = str_replace( self::TOC_MARKER . $index . ' -->', $html, $page );
		}

		return $page;
	}

	/**
	 * The page of each heading (`<post>#<n>`) that the address gives (`books_pages`: the map that the first pass of the job saved); none when it
	 * gives nothing, the map is not found or it is not readable: the numbers are then placeholders.
	 *
	 * @return array<string, int>
	 */
	private function pages() {
		$request = (array) ( $this->request )();
		$key     = (string) ( $request['pages'] ?? '' );

		if ( '' === $key || null === $this->pages_reader ) {
			return array();
		}

		$data = json_decode( (string) ( $this->pages_reader )( $key ), true );

		return is_array( $data ) ? array_map( 'intval', array_filter( $data, 'is_numeric' ) ) : array();
	}

	/**
	 * Writes the manifest of the merge (the headings of the page of the section and of the units, in the order of the document, with their level,
	 * their text and the depth of their unit) and returns the marker that names it, to put in front of the units; an empty string when there
	 * are no units, no place to write it, or it could not be written.
	 *
	 * @param string            $html   The units.
	 * @param array<int, array> $before The headings of the page of the section before the units.
	 * @param array<int, array> $after  The headings of the page of the section after the units.
	 * @return string
	 */
	private function manifest( $html, array $before, array $after ) {
		if ( '' === $html || null === $this->manifest_writer ) {
			return '';
		}

		$json = wp_json_encode(
			array(
				'section'  => $this->section_id,
				'units'    => $this->unit_list,
				'tocs'     => count( array_filter( $this->tocs, static fn( $toc ) => $toc['numbered'] ) ),
				'headings' => array_merge( $before, $this->rows, $after ),
			)
		);

		if ( ! is_string( $json ) ) {
			return '';
		}

		$key = md5( $json );

		return true === ( $this->manifest_writer )( $key, $json ) ? Headings::manifest_marker( $key ) : '';
	}

	/**
	 * Renders the units at the start of the footer and puts them in front of what follows: after the whole page of the section, outside its
	 * template (a template may put the content in a column: the units would be squeezed in it), and before the scripts of the footer, which
	 * find the units in the page. No HTML is printed here: the output buffer hands the units to the page. Action `wp_footer`, priority 1.
	 *
	 * @return void
	 */
	public function footer() {
		if ( null !== $this->html || $this->busy || ! $this->slot ) {
			return;
		}

		$html = $this->merge();

		if ( '' === $html ) {
			return;
		}

		$this->html = $html;

		if ( $this->placing ) {
			// The units go where the block put its marker.
			return;
		}

		ob_start( array( $this, 'prepend' ) );
	}

	/**
	 * Output callback: puts the units in front of the buffer, once.
	 *
	 * @param string $buffer What the page prints from the footer on.
	 * @return string
	 */
	public function prepend( $buffer ) {
		if ( null === $this->html || $this->inserted ) {
			return $buffer;
		}

		$this->inserted = true;

		return $this->html . $buffer;
	}

	/**
	 * The HTML of the units of the section of the request: empty when the request is not the page of a section, is the preview of the
	 * editor, is not allowed to merge, or the section holds nothing.
	 *
	 * @return string
	 */
	public function merge() {
		$request = (array) ( $this->request )();
		$section = (int) ( $request['section'] ?? 0 );

		if ( $section <= 0 || ! $this->access->allowed( $section ) || array() === $this->sections->order( $section ) ) {
			return '';
		}

		$preview       = ! empty( $request['preview'] );
		$this->limit   = $this->limit_for( $section, (int) ( $request['limit'] ?? 0 ) );
		$this->limit   = $preview ? min( $this->limit, max( 1, (int) apply_filters( 'books_merge_preview_limit', self::PREVIEW_UNITS ) ) ) : $this->limit;
		$this->truncated = false;
		$this->count   = 0;
		$this->busy    = true;
		$this->started = microtime( true );
		$this->rows    = array();
		$this->unit_list = array();
		$queries       = get_num_queries();

		// The time that a merge may take: the limit of the server and of the print server remain.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( max( 30, (int) apply_filters( 'books_merge_time_limit', 600 ) ) );
		}

		if ( $this->log->enabled() ) {
			register_shutdown_function( array( $this, 'shutdown' ) );
		}

		// Images that are lazy are not loaded by the print engine unless it scrolls: all of them must be loaded for the PDF.
		add_filter( 'wp_lazy_loading_enabled', '__return_false' );

		$this->log->write(
			'merge_start',
			array(
				'section' => $section,
				'limit'   => $this->limit,
			)
		);

		try {
			$units = $this->units( $section, array( $section => true ), 1 );
		} finally {
			$this->busy = false;
		}

		$summary = array(
			'section' => $section,
			'units'   => $this->count,
			'seconds' => round( microtime( true ) - $this->started, 2 ),
			'queries' => get_num_queries() - $queries,
			'html_kb' => (int) round( strlen( $units ) / 1024 ),
		);

		$this->log->write( 'merge_done', $summary );

		return $units . $this->note( $preview ) . '<!-- books-merge ' . wp_json_encode( $summary ) . ' -->';
	}

	/**
	 * Writes why a merge stopped before its end (a fatal error such as the time or the memory limit, or a client that went away). Called
	 * when PHP shuts down; does nothing when the merge ended.
	 *
	 * @return void
	 */
	public function shutdown() {
		if ( ! $this->busy ) {
			return;
		}

		$this->log->write(
			'merge_aborted',
			array(
				'units'      => $this->count,
				'unit'       => $this->current,
				'seconds'    => round( microtime( true ) - $this->started, 2 ),
				'error'      => error_get_last(),
				'connection' => connection_status(),
			)
		);
	}

	/**
	 * The note at the end of a preview that stopped before the last unit: the PDF has them all.
	 *
	 * @param bool $preview Whether the request is the preview of the editor.
	 * @return string
	 */
	private function note( $preview ) {
		if ( ! $preview || ! $this->truncated ) {
			return '';
		}

		/* translators: %d: number of units shown in the preview. */
		$text = sprintf( __( 'Preview: the first %d units. The PDF has all of them.', 'otherguise' ), $this->count );

		return '<p class="books-merge-note" style="break-before:page;font:italic 11pt sans-serif;text-align:center;">' . esc_html( $text ) . '</p>';
	}

	/**
	 * The number of units to stop at: the argument of the address, else the custom field of the section (the PDF is fetched by the print
	 * server, which adds none), else the option of the same name; never more than `MAX_UNITS`.
	 *
	 * @param int $section  Id of the section.
	 * @param int $argument The number asked for in the address.
	 * @return int
	 */
	private function limit_for( $section, $argument ) {
		$field  = (int) get_post_meta( $section, self::LIMIT, true );
		$option = (int) get_option( self::LIMIT, 0 );
		$wanted = $argument > 0 ? $argument : ( $field > 0 ? $field : $option );

		return $wanted > 0 ? min( $wanted, self::MAX_UNITS ) : self::MAX_UNITS;
	}

	/**
	 * The units of a section, each followed by its own units when it is a section.
	 *
	 * @param int              $section Id of the section.
	 * @param array<int, bool> $seen    Sections already followed (the cycles stop here).
	 * @param int              $depth   Level.
	 * @return string
	 */
	private function units( $section, array $seen, $depth ) {
		$out = '';

		foreach ( $this->sections->order( $section ) as $id ) {
			if ( $this->count >= $this->limit ) {
				$this->truncated = true;
				break;
			}

			$post = ( $this->post_of )( (int) $id );

			if ( null === $post || 'publish' !== $post['status'] || '' !== $post['password'] ) {
				$this->log->write( 'unit_skipped', array( 'post' => (int) $id ) );
				continue;
			}

			$started       = microtime( true );
			$queries       = get_num_queries();
			$this->current = (int) $id;

			$this->log->write(
				'unit_start',
				array(
					'n'    => $this->count + 1,
					'post' => $this->current,
				)
			);

			$html          = (string) ( $this->render )( (int) $id );
			$this->current = 0;

			++$this->count;
			$this->unit_list[] = array(
				'id'    => (int) $id,
				'depth' => (int) $depth,
			);
			$out .= '<div class="books-merge-unit" data-post="' . (int) $id . '" data-depth="' . (int) $depth . '">' . Headings::unit( $html, (int) $id, $this->rows, (int) $depth ) . '</div>';

			$this->log->write(
				'unit',
				array(
					'n'       => $this->count,
					'post'    => (int) $id,
					'depth'   => $depth,
					'ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
					'queries' => get_num_queries() - $queries,
					'html_kb' => (int) round( strlen( $html ) / 1024 ),
				)
			);

			if ( $depth < self::DEPTH && ! isset( $seen[ $id ] ) && array() !== $this->sections->order( (int) $id ) ) {
				$out .= $this->units( (int) $id, $seen + array( $id => true ), $depth + 1 );
			}
		}

		return $out;
	}
}
