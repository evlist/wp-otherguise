<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The table of the assignments.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Admin;

use Otherguise\Index\Designation\Listing;
use Otherguise\Index\Kinds;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * The `WP_List_Table` of the assignments (slice 630): one line per **designation**, with the topic it is assigned to, who assigned it, its state and the
 * number of posts that observe it; views, a search, sortable columns, bulk actions and a field that chooses the topic. **It only shows what `Listing` gives.**
 * The class of WordPress must be loaded before this one is (`Designation\Wiring::table()` does it).
 */
final class AssignmentsTable extends \WP_List_Table {
	public const PER_PAGE = 50;

	/**
	 * What is shown.
	 *
	 * @var Listing
	 */
	private $listing;

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * Reads a field of the request: `fn( string $name ): string`.
	 *
	 * @var callable
	 */
	private $request;

	/**
	 * The address of the screen with arguments: `fn( array $args ): string`.
	 *
	 * @var callable
	 */
	private $url;

	/**
	 * The counts of the views, known once the items are prepared.
	 *
	 * @var array<string, ?int>
	 */
	private $counts = array();

	/**
	 * Builds the table.
	 *
	 * @param Listing  $listing What is shown.
	 * @param Topics   $topics  The topics.
	 * @param callable $request Reads a field of the request.
	 * @param callable $url     The address of the screen with arguments.
	 */
	public function __construct( Listing $listing, Topics $topics, $request, $url ) {
		parent::__construct(
			array(
				'singular' => 'designation',
				'plural'   => 'designations',
				'ajax'     => false,
			)
		);

		$this->listing = $listing;
		$this->topics  = $topics;
		$this->request = $request;
		$this->url     = $url;
	}

	/**
	 * The view that is asked.
	 *
	 * @return string
	 */
	public function view() {
		$view = sanitize_key( (string) ( $this->request )( 'view' ) );

		return in_array( $view, array( Listing::RESOLVE, Listing::SYSTEM, Listing::HAND, Listing::UNOBSERVED ), true ) ? $view : Listing::ALL;
	}

	/**
	 * The columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'designation' => __( 'What was designated', 'otherguise' ),
			'source'      => __( 'Source', 'otherguise' ),
			'posts'       => __( 'Posts', 'otherguise' ),
			'topic'       => __( 'Assigned to', 'otherguise' ),
			'by'          => __( 'By', 'otherguise' ),
			'state'       => __( 'State', 'otherguise' ),
		);
	}

	/**
	 * The columns that sort.
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	public function get_sortable_columns() {
		return array(
			'designation' => array( 'label', false ),
			'source'      => array( 'source', false ),
			'posts'       => array( 'posts', true ),
			'topic'       => array( 'topic', false ),
			'state'       => array( 'state', false ),
		);
	}

	/**
	 * The views: the parts of the list, with their number.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$names = array(
			Listing::ALL        => __( 'All', 'otherguise' ),
			Listing::RESOLVE    => __( 'To resolve', 'otherguise' ),
			Listing::SYSTEM     => __( 'Assigned by the system', 'otherguise' ),
			Listing::HAND       => __( 'Assigned by a person', 'otherguise' ),
			Listing::UNOBSERVED => __( 'Observed by no post', 'otherguise' ),
		);
		$links = array();

		foreach ( $names as $view => $label ) {
			$count = $this->counts[ $view ] ?? null;

			$links[ $view ] = '<a href="' . esc_url( ( $this->url )( Listing::ALL === $view ? array() : array( 'view' => $view ) ) ) . '"' . ( $view === $this->view() ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ( null === $count ? '' : ' <span class="count">(' . esc_html( (string) $count ) . ')</span>' ) . '</a>';
		}

		return $links;
	}

	/**
	 * The bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array(
			'assign'   => __( 'Assign to the topic chosen above…', 'otherguise' ),
			'confirm'  => __( 'Confirm the assignment (a person decides)', 'otherguise' ),
			'unassign' => __( 'Take the assignment away', 'otherguise' ),
			'delete'   => __( 'Delete (only what no post observes)', 'otherguise' ),
		);
	}

	/**
	 * What is shown above the table: the field that chooses the topic of an assignment.
	 *
	 * @param string $which `top` or `bottom`.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions"><label class="screen-reader-text" for="assignment-topic">' . esc_html__( 'The topic to assign to', 'otherguise' ) . '</label>';
		echo '<input type="text" id="assignment-topic" name="to_label" size="30" placeholder="' . esc_attr__( 'Search the topic to assign to', 'otherguise' ) . '" data-topic-picker="assignment-topic-id" value="' . esc_attr( (string) ( $this->request )( 'to_label' ) ) . '" />';
		echo '<input type="hidden" id="assignment-topic-id" name="to" value="" /></div>';
	}

	/**
	 * The message when there is nothing to show.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No designation here: read the pages first (wp otherguise index sync).', 'otherguise' );
	}

	/**
	 * Reads what was asked and prepares the lines of the page.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$result = $this->listing->query(
			array(
				'view'     => $this->view(),
				'search'   => sanitize_text_field( (string) ( $this->request )( 's' ) ),
				'orderby'  => sanitize_key( (string) ( $this->request )( 'orderby' ) ),
				'order'    => sanitize_key( (string) ( $this->request )( 'order' ) ),
				'paged'    => max( 1, absint( (string) ( $this->request )( 'paged' ) ) ),
				'per_page' => self::PER_PAGE,
			)
		);

		$this->items  = $result['rows'];
		$this->counts = $result['counts'];

		// A table of a plugin names its columns itself: WordPress would otherwise look for them in a filter of the screen and print none.
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'designation' );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
				'total_pages' => $result['pages'],
			)
		);
	}

	/**
	 * The checkbox of a line.
	 *
	 * @param array $item A line.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="designation[]" value="' . esc_attr( (string) $item['id'] ) . '" />';
	}

	/**
	 * What was designated, with its key and the link to the posts.
	 *
	 * @param array $item A line.
	 * @return string
	 */
	protected function column_designation( $item ) {
		$actions = array(
			'posts' => '<a href="' . esc_url(
				add_query_arg(
					array(
						'page' => TopicsScreen::PAGE,
						'designation' => (int) $item['id'],
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Posts', 'otherguise' ) . '</a>',
		);

		return '<strong>' . esc_html( (string) $item['label'] ) . '</strong><br /><code>' . esc_html( (string) $item['key'] ) . '</code>' . $this->row_actions( $actions );
	}

	/**
	 * The source.
	 *
	 * @param array $item A line.
	 * @return string
	 */
	protected function column_source( $item ) {
		return esc_html( (string) $item['source'] );
	}

	/**
	 * The number of posts.
	 *
	 * @param array $item A line.
	 * @return string
	 */
	protected function column_posts( $item ) {
		return esc_html( (string) (int) $item['posts'] );
	}

	/**
	 * The topic, with its kind and the topics above it.
	 *
	 * @param array $item A line.
	 * @return string
	 */
	protected function column_topic( $item ) {
		$topic = (int) $item['topic'] > 0 ? $this->topics->get( (int) $item['topic'] ) : null;

		if ( null === $topic ) {
			return '—';
		}

		$above = array();
		$up    = (int) $topic['parent'];

		for ( $level = 0; $up > 0 && $level < 8; $level++ ) {
			$parent = $this->topics->get( $up );

			if ( null === $parent ) {
				break;
			}

			array_unshift( $above, (string) $parent['label'] );
			$up = (int) $parent['parent'];
		}

		return '<a href="' . esc_url(
			add_query_arg(
				array(
					'page' => TopicsScreen::PAGE,
					'topic' => (int) $topic['id'],
				),
				admin_url( 'admin.php' )
			)
		) . '">' . esc_html( (string) $topic['label'] ) . '</a> <small>' . esc_html( (string) Kinds::label( (string) $topic['kind'] ) . ( array() === $above ? '' : ', ' . implode( ' > ', $above ) ) ) . '</small>';
	}

	/**
	 * Who assigned it.
	 *
	 * @param array $item A line.
	 * @return string
	 */
	protected function column_by( $item ) {
		return esc_html( '' === $item['by'] ? '—' : (string) $item['by'] );
	}

	/**
	 * The state, with the identifier of Wikidata when there is one.
	 *
	 * @param array $item A line.
	 * @return string
	 */
	protected function column_state( $item ) {
		return esc_html( (string) $item['state'] ) . ( '' === $item['wikidata'] ? '' : ' <small>' . esc_html( (string) $item['wikidata'] ) . '</small>' );
	}

	/**
	 * What a column that has no method of its own shows.
	 *
	 * @param array  $item   A line.
	 * @param string $column The column.
	 * @return string
	 */
	protected function column_default( $item, $column ) {
		return esc_html( (string) ( $item[ $column ] ?? '' ) );
	}
}
