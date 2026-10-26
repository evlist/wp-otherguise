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
 * In a print mode, the page of a section shows its own content and then, **after the whole page**, one after the other, the page of each
 * unit in that mode (the template that the Modes module would give that unit, rendered for it), each in a `div` that starts a new page;
 * a unit that is a section is followed by its own units. Only published units that have no password. It is **asked for**, never given to
 * a visitor: see `MergeAccess` (a user who can edit the section, or the PDF job with its token), and the preview of the editor does not
 * merge (the PDF does). At most `MAX_UNITS` units and, by default, ten minutes of PHP.
 */
final class MergedPrint {
	/**
	 * Name of the query argument, of the custom field of the section and of the option that stop the merge after a number of units.
	 */
	public const LIMIT = 'books_merge_limit';

	/**
	 * Name of the query argument that marks the preview of the editor.
	 */
	public const PREVIEW = 'books_preview';

	/**
	 * Deepest level of sections followed.
	 */
	public const DEPTH = 6;

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
	 * Describes the request: `print` (a print mode), `section` (the id of the single post shown, 0 for none), `preview` (the preview of
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
	 * @param callable|null $request  Describes the request; defaults to a read of WordPress.
	 * @param callable|null $render   Renders a unit; defaults to `UnitRenderer`.
	 * @param callable|null $post_of  Reads a post; defaults to `get_post()`.
	 */
	public function __construct( Sections $sections, MergeAccess $access, MergeLog $log, $request = null, $render = null, $post_of = null ) {
		$this->sections = $sections;
		$this->access   = $access;
		$this->log      = $log;
		$this->request  = $request ?? array( $this, 'read_request' );
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
	 * Renders the units at the start of the footer and puts them in front of what follows: after the whole page of the section, outside its
	 * template (a template may put the content in a column: the units would be squeezed in it), and before the scripts of the footer, which
	 * find the units in the page. No HTML is printed here: the output buffer hands the units to the page. Action `wp_footer`, priority 1.
	 *
	 * @return void
	 */
	public function footer() {
		if ( null !== $this->html || $this->busy ) {
			return;
		}

		$html = $this->merge();

		if ( '' === $html ) {
			return;
		}

		$this->html = $html;

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
	 * The HTML of the units of the section of the request: empty when the request is not a print page of a section, is the preview of the
	 * editor, is not allowed to merge, or the section holds nothing.
	 *
	 * @return string
	 */
	public function merge() {
		$request = (array) ( $this->request )();
		$section = (int) ( $request['section'] ?? 0 );

		if ( empty( $request['print'] ) || ! empty( $request['preview'] ) || $section <= 0 || ! $this->access->allowed( $section ) || array() === $this->sections->order( $section ) ) {
			return '';
		}

		$this->limit   = $this->limit_for( $section, (int) ( $request['limit'] ?? 0 ) );
		$this->count   = 0;
		$this->busy    = true;
		$this->started = microtime( true );
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

		return '<style>.books-merge-unit{break-before:page;}</style>' . $units . '<!-- books-merge ' . wp_json_encode( $summary ) . ' -->';
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
	 * Reads the request: a print mode, the single post shown, the preview of the editor, the number of units asked for.
	 *
	 * @return array{print:bool, section:int, preview:bool, limit:int}
	 */
	public function read_request() {
		$modes = apply_filters( 'books_preview_frame_modes', array( 'print' ) );
		$mode  = function_exists( 'modes_active_mode' ) ? modes_active_mode() : null;
		$limit = filter_input( INPUT_GET, self::LIMIT, FILTER_VALIDATE_INT );

		return array(
			'print'   => null !== $mode && is_array( $modes ) && in_array( $mode->slug(), $modes, true ),
			'section' => is_singular() ? (int) get_queried_object_id() : 0,
			'preview' => null !== filter_input( INPUT_GET, self::PREVIEW, FILTER_UNSAFE_RAW ),
			'limit'   => is_int( $limit ) ? $limit : 0,
		);
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
			$out .= '<div class="books-merge-unit" data-post="' . (int) $id . '" data-depth="' . (int) $depth . '">' . $html . '</div>';

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
