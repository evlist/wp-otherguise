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
 * **Test branch `merge-all-html` only** (option 4 of `docs/design/books.md`: merge the HTML of the units before the pagination).
 *
 * In the print mode, the page of a section shows its own content and then, after the whole page, one after the other, the page of each unit **in the print mode**
 * (the template that the Modes module would give that unit, rendered for it), each one in a `div` that starts a new page; a unit that is a
 * section is followed by its own units. Only published units. `?books_merge=0` shows the page without the units, `?books_merge_limit=N`
 * stops after N units (to try 20 and then 100). Every unit and the whole are written to the log (`MergeLog`). A hack for a test: no
 * settings, no cache, nothing for the editor.
 */
final class MergedPrint {
	/**
	 * Query argument that switches the merge off (`0`).
	 */
	public const SWITCH = 'books_merge';

	/**
	 * Query argument that stops the merge after a number of units.
	 */
	public const LIMIT = 'books_merge_limit';

	/**
	 * Deepest level of sections followed.
	 */
	public const DEPTH = 6;

	/**
	 * The sections.
	 *
	 * @var Sections
	 */
	private $sections;

	/**
	 * True while the units are being rendered: the content of a unit is not merged again, the recursion is done here.
	 *
	 * @var bool
	 */
	private $busy = false;

	/**
	 * Units rendered in the current merge.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Largest number of units, 0 for no limit.
	 *
	 * @var int
	 */
	private $limit = 0;

	/**
	 * Builds the merge.
	 *
	 * @param Sections $sections The sections.
	 */
	public function __construct( Sections $sections ) {
		$this->sections = $sections;
	}

	/**
	 * Prints the units after the whole page of the section, outside its template (the print template of a section may put its content in a
	 * column: the units would be squeezed in it). Action `wp_footer`, early, so that the styles and scripts that the units enqueue are
	 * printed with the others.
	 *
	 * @return void
	 */
	public function footer() {
		if ( $this->busy || ! $this->wanted() ) {
			return;
		}

		$section = (int) get_queried_object_id();

		if ( array() === $this->sections->order( $section ) ) {
			return;
		}

		$limit_argument = filter_input( INPUT_GET, self::LIMIT, FILTER_VALIDATE_INT );
		$this->limit    = is_int( $limit_argument ) && $limit_argument > 0 ? $limit_argument : 0;
		$this->count    = 0;
		$this->busy     = true;
		$started        = microtime( true );
		$queries        = get_num_queries();

		// Images that are lazy are not loaded by the print engine unless it scrolls: all of them must be loaded for the PDF.
		add_filter( 'wp_lazy_loading_enabled', '__return_false' );

		MergeLog::write(
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
			'seconds' => round( microtime( true ) - $started, 2 ),
			'queries' => get_num_queries() - $queries,
			'html_kb' => (int) round( strlen( $units ) / 1024 ),
		);

		MergeLog::write( 'merge_done', $summary );

		echo '<style>.books-merge-unit{break-before:page;}</style>' . $units . '<!-- books-merge ' . wp_json_encode( $summary + MergeLog::memory() ) . ' -->';
	}

	/**
	 * Whether the current request is the print page of a section that should be merged.
	 *
	 * @return bool
	 */
	private function wanted() {
		if ( ! is_singular() || '0' === filter_input( INPUT_GET, self::SWITCH ) || ! function_exists( 'modes_active_mode' ) ) {
			return false;
		}

		$mode  = modes_active_mode();
		$modes = apply_filters( 'books_preview_frame_modes', array( 'print' ) );

		return null !== $mode && is_array( $modes ) && in_array( $mode->slug(), $modes, true );
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
			if ( $this->limit > 0 && $this->count >= $this->limit ) {
				break;
			}

			$post = get_post( $id );

			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
				MergeLog::write( 'unit_skipped', array( 'post' => (int) $id ) );
				continue;
			}

			$started = microtime( true );
			$queries = get_num_queries();
			$html    = $this->render( $post );

			++$this->count;
			$out .= '<div class="books-merge-unit" data-post="' . (int) $post->ID . '" data-depth="' . (int) $depth . '">' . $html . '</div>';

			MergeLog::write(
				'unit',
				array(
					'n'       => $this->count,
					'post'    => (int) $post->ID,
					'depth'   => $depth,
					'ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
					'queries' => get_num_queries() - $queries,
					'html_kb' => (int) round( strlen( $html ) / 1024 ),
				)
			);

			if ( $depth < self::DEPTH && ! isset( $seen[ $post->ID ] ) && array() !== $this->sections->order( $post->ID ) ) {
				$out .= $this->units( $post->ID, $seen + array( $post->ID => true ), $depth + 1 );
			}
		}

		return $out;
	}

	/**
	 * Renders a unit as WordPress would render its own page in the mode of the request: a query for that post, the template that
	 * WordPress finds for it (the Modes module has put the variant in the hierarchy), and the blocks of that template. A classic theme, or
	 * a template that cannot be found, gives the content of the post.
	 *
	 * @param \WP_Post $post The unit.
	 * @return string
	 */
	private function render( \WP_Post $post ) {
		$keep = array();

		foreach ( array( 'wp_query', 'post', '_wp_current_template_content', '_wp_current_template_id' ) as $name ) {
			$keep[ $name ] = $GLOBALS[ $name ] ?? null;
		}

		$GLOBALS['wp_query']                     = new \WP_Query(
			array(
				'p'                   => $post->ID,
				'post_type'           => $post->post_type,
				'post_status'         => 'publish',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);
		$GLOBALS['_wp_current_template_content'] = null;
		$GLOBALS['_wp_current_template_id']      = null;

		try {
			if ( is_page() ) {
				get_page_template();
			} elseif ( is_single() ) {
				get_single_template();
			} else {
				get_singular_template();
			}

			if ( ! empty( $GLOBALS['_wp_current_template_content'] ) ) {
				$html = get_the_block_template_html();
			} else {
				setup_postdata( $post );
				$html = (string) apply_filters( 'the_content', $post->post_content );
			}
		} finally {
			foreach ( $keep as $name => $value ) {
				$GLOBALS[ $name ] = $value;
			}

			if ( $keep['post'] instanceof \WP_Post ) {
				setup_postdata( $keep['post'] );
			}
		}

		return $html;
	}
}
