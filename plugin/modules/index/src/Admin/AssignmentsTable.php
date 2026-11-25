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
use Otherguise\Index\Designations;
use Otherguise\Index\Kinds;
use Otherguise\Index\Resolver;
use Otherguise\Index\Sources;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * The `WP_List_Table` of the assignments (slice 630): one line per **motif**, with the topic it is assigned to, who assigned it, its state and the
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

		return in_array( $view, array( Listing::RESOLVE, Listing::INCOMPATIBLE, Listing::EXCLUDED, Listing::SYSTEM, Listing::HAND, Listing::UNOBSERVED ), true ) ? $view : Listing::ALL;
	}

	/**
	 * The columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		// The topic comes first (slice 637); the motif stays the main column of the table (its actions are there).
		return array(
			'cb'          => '<input type="checkbox" />',
			'topic'       => __( 'Topic', 'otherguise' ),
			'status'      => __( 'Topic status', 'otherguise' ),
			'designation' => __( 'Motif', 'otherguise' ),
			'source'      => __( 'Source', 'otherguise' ),
			'posts'       => __( 'Posts', 'otherguise' ),
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
			'status'      => array( 'status', false ),
			'by'          => array( 'by', false ),
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
			Listing::INCOMPATIBLE => __( 'Incompatible', 'otherguise' ),
			Listing::EXCLUDED   => __( 'Excluded', 'otherguise' ),
			Listing::SYSTEM     => __( 'Assigned by the system', 'otherguise' ),
			Listing::HAND       => __( 'Assigned by a person', 'otherguise' ),
			Listing::UNOBSERVED => __( 'Observed by no post', 'otherguise' ),
		);
		$links = array();

		foreach ( $names as $view => $label ) {
			$count = $this->counts[ $view ] ?? null;

			$links[ $view ] = '<a href="' . esc_url( ( $this->url )( array_merge( $this->kept(), Listing::ALL === $view ? array() : array( 'view' => $view ) ) ) ) . '"' . ( $view === $this->view() ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ( null === $count ? '' : ' <span class="count">(' . esc_html( (string) $count ) . ')</span>' ) . '</a>';
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
			'choose'   => __( 'Apply the choices (the lists in the Topic column)', 'otherguise' ),
			'assign'   => __( 'Assign to the topic in the box that appears…', 'otherguise' ),
			'confirm'  => __( 'Confirm the assignment (a person decides)', 'otherguise' ),
			'unassign' => __( 'Take the assignment away', 'otherguise' ),
			'exclude'  => __( 'Exclude from the index (no topic, a reading leaves it alone)', 'otherguise' ),
			'include'  => __( 'Include again (the next reading proposes a topic)', 'otherguise' ),
			'delete'   => __( 'Delete (only what no post observes)', 'otherguise' ),
		);
	}

	/**
	 * The box above the table that says **where the action "Assign…" puts the ticked lines**: an existing topic (a field that searches the topics as one types),
	 * or a new one (its label, its kind and the topic it goes under, which a field searches too). It is printed inside the form of the table, by the screen.
	 *
	 * @return void
	 */
	public function target_fields() {
		echo '<fieldset class="assignment-target" data-shown-by-action="assign" style="background:#fff;border:1px solid #c3c4c7;margin:12px 0;padding:8px 12px;">';
		echo '<legend><strong>' . esc_html__( 'Where "Assign to the topic…" puts the ticked lines', 'otherguise' ) . '</strong></legend>';
		echo '<p><label for="assignment-topic"><strong>' . esc_html__( 'An existing topic', 'otherguise' ) . '</strong> ' . esc_html__( '(type a name, then pick it in the list)', 'otherguise' ) . '</label><br />';
		echo '<input type="text" id="assignment-topic" name="to_label" size="40" data-topic-picker="assignment-topic-id" value="' . esc_attr( (string) ( $this->request )( 'to_label' ) ) . '" />';
		echo '<input type="hidden" id="assignment-topic-id" name="to" value="" /></p>';
		echo '<p><strong>' . esc_html__( 'or a new topic', 'otherguise' ) . '</strong><br />';
		echo '<label for="assignment-new-label">' . esc_html__( 'Label', 'otherguise' ) . '</label> <input type="text" id="assignment-new-label" name="new_label" size="25" /> ';
		echo '<label for="assignment-new-kind">' . esc_html__( 'Kind', 'otherguise' ) . '</label> <select id="assignment-new-kind" name="new_kind">';

		foreach ( Kinds::tree() as $row ) {
			echo '<option value="' . esc_attr( (string) $row['slug'] ) . '"' . selected( Kinds::ANY, $row['slug'], false ) . '>' . esc_html( str_repeat( '— ', (int) $row['depth'] ) . $row['label'] ) . '</option>';
		}

		echo '</select> <label for="assignment-parent">' . esc_html__( 'Under the topic', 'otherguise' ) . '</label> <input type="text" id="assignment-parent" name="new_parent_label" size="25" data-topic-picker="assignment-parent-id" placeholder="' . esc_attr__( 'none, or type a name', 'otherguise' ) . '" />';
		echo '<input type="hidden" id="assignment-parent-id" name="new_parent" value="" /></p></fieldset>';
	}

	/**
	 * The filters of the columns that were asked (slice 637): the fields `f_topic`, `f_status`, `f_motif`, `f_source`, `f_posts`, `f_by` and `f_state`.
	 *
	 * @return array<string, string>
	 */
	public function filters() {
		$asked = array();

		foreach ( Listing::clean_filters( array() ) as $name => $unused ) {
			$asked[ $name ] = sanitize_text_field( (string) ( $this->request )( 'f_' . $name ) );
		}

		return Listing::clean_filters( $asked );
	}

	/**
	 * What the links of the views keep: the search and the filters.
	 *
	 * @return array<string, string>
	 */
	private function kept() {
		$kept   = array();
		$search = sanitize_text_field( (string) ( $this->request )( 's' ) );

		if ( '' !== $search ) {
			$kept['s'] = $search;
		}

		foreach ( $this->filters() as $name => $value ) {
			if ( '' !== $value ) {
				$kept[ 'f_' . $name ] = $value;
			}
		}

		return $kept;
	}

	/**
	 * The bar of the filters, above the table, in the style of WordPress (slice 637): **one field per column**, in the order of the columns; they combine with the
	 * view and with the search. It is printed inside the form of the search (a form that sends by GET), so that one button sends them all.
	 *
	 * @return void
	 */
	public function filter_bar() {
		$now = $this->filters();

		echo '<div class="tablenav top assignment-filters"><div class="alignleft actions">';
		$this->filter_text( 'topic', __( 'Topic', 'otherguise' ), $now['topic'] );
		$this->filter_select(
			'status',
			__( 'Any topic status', 'otherguise' ),
			$now['status'],
			array(
				'approved'  => __( 'Approved', 'otherguise' ),
				'candidate' => __( 'Candidate (not in the index)', 'otherguise' ),
				'refused'   => __( 'Refused', 'otherguise' ),
				'none'      => __( 'No topic', 'otherguise' ),
			)
		);
		$this->filter_text( 'motif', __( 'Motif', 'otherguise' ), $now['motif'] );

		$sources = array();

		foreach ( Sources::tree() as $row ) {
			$sources[ (string) $row['path'] ] = str_repeat( '— ', (int) $row['depth'] ) . $row['label'];
		}

		$this->filter_select( 'source', __( 'Any source', 'otherguise' ), $now['source'], $sources );
		$this->filter_select(
			'posts',
			__( 'Any number of posts', 'otherguise' ),
			$now['posts'],
			array(
				'none' => __( 'No post', 'otherguise' ),
				'1'    => __( 'At least 1 post', 'otherguise' ),
				'2'    => __( 'At least 2 posts', 'otherguise' ),
				'5'    => __( 'At least 5 posts', 'otherguise' ),
				'10'   => __( 'At least 10 posts', 'otherguise' ),
				'25'   => __( 'At least 25 posts', 'otherguise' ),
				'50'   => __( 'At least 50 posts', 'otherguise' ),
			)
		);
		$this->filter_select(
			'by',
			__( 'Anyone', 'otherguise' ),
			$now['by'],
			array(
				'system' => __( 'The system', 'otherguise' ),
				'hand'   => __( 'A person', 'otherguise' ),
				'none'   => __( 'No assignment', 'otherguise' ),
			)
		);
		$this->filter_select(
			'state',
			__( 'Any state', 'otherguise' ),
			$now['state'],
			array(
				Designations::ASSIGNED  => __( 'assigned', 'otherguise' ),
				Designations::PENDING   => __( 'pending', 'otherguise' ),
				Designations::AMBIGUOUS => __( 'ambiguous', 'otherguise' ),
				Designations::EXCLUDED  => __( 'excluded', 'otherguise' ),
			)
		);
		echo '<input type="submit" class="button" value="' . esc_attr__( 'Filter', 'otherguise' ) . '" />';

		if ( array() !== array_filter( $now, static fn( $value ) => '' !== $value ) ) {
			$reset = array_diff_key( $this->kept(), array_flip( array_map( static fn( $name ) => 'f_' . $name, array_keys( $now ) ) ) );

			if ( Listing::ALL !== $this->view() ) {
				$reset['view'] = $this->view();
			}

			echo ' <a href="' . esc_url( ( $this->url )( $reset ) ) . '">' . esc_html__( 'Reset the filters', 'otherguise' ) . '</a>';
		}

		echo '</div></div>';
	}

	/**
	 * A text field of the bar of the filters.
	 *
	 * @param string $name  The column.
	 * @param string $label Its label.
	 * @param string $value The filter now.
	 * @return void
	 */
	private function filter_text( $name, $label, $value ) {
		echo '<label class="screen-reader-text" for="f-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
		echo '<input type="search" id="f-' . esc_attr( $name ) . '" name="f_' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $label ) . '" size="14" /> ';
	}

	/**
	 * A list of the bar of the filters: the first choice says "any".
	 *
	 * @param string                $name    The column.
	 * @param string                $any     The text of the first choice.
	 * @param string                $value   The filter now.
	 * @param array<string, string> $choices Value => label.
	 * @return void
	 */
	private function filter_select( $name, $any, $value, array $choices ) {
		echo '<label class="screen-reader-text" for="f-' . esc_attr( $name ) . '">' . esc_html( $any ) . '</label>';
		echo '<select id="f-' . esc_attr( $name ) . '" name="f_' . esc_attr( $name ) . '"><option value="">' . esc_html( $any ) . '</option>';

		foreach ( $choices as $choice => $label ) {
			echo '<option value="' . esc_attr( (string) $choice ) . '"' . selected( $value, (string) $choice, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select> ';
	}

	/**
	 * The status of the topic, said as it is stored.
	 *
	 * @param array $item A line.
	 * @return string
	 */
	protected function column_status( $item ) {
		return '' === (string) ( $item['topic_status'] ?? '' ) ? '—' : esc_html( (string) $item['topic_status'] );
	}

	/**
	 * The message when there is nothing to show.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No motif here: read the pages first (wp otherguise index sync).', 'otherguise' );
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
				'filters'  => $this->filters(),
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
	 * Motif, with its key and the link to the posts.
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

		if ( null === $topic && Designations::EXCLUDED === (string) $item['state'] ) {
			return '<em>' . esc_html__( 'Not in the index', 'otherguise' ) . '</em>';
		}

		if ( null === $topic ) {
			return $this->choice( $item );
		}

		return '<a href="' . esc_url(
			add_query_arg(
				array(
					'page' => TopicsScreen::PAGE,
					'topic' => (int) $topic['id'],
				),
				admin_url( 'admin.php' )
			)
		) . '">' . esc_html( (string) $topic['label'] ) . '</a> <small>' . esc_html( $this->describe( $topic ) ) . '</small>';
	}

	/**
	 * What a motif that has no topic offers: **the topics that a level hesitated between**, in a list, with the kind and the topics above each; the choice
	 * is applied by the bulk action *Apply the choices* (a form inside the form of the table is not possible).
	 *
	 * @param array $item A line.
	 * @return string
	 */
	private function choice( $item ) {
		$ambiguity = $item['ambiguity'] ?? null;

		if ( ! is_array( $ambiguity ) || array() === (array) ( $ambiguity['candidates'] ?? array() ) ) {
			return '—';
		}

		$html = '<label class="screen-reader-text" for="choice-' . esc_attr( (string) $item['id'] ) . '">' . esc_html__( 'Choose the topic', 'otherguise' ) . '</label>';
		$html .= '<small>' . esc_html( $this->question( $ambiguity ) ) . '</small><br />';
		$html .= '<select id="choice-' . esc_attr( (string) $item['id'] ) . '" name="choice[' . esc_attr( (string) $item['id'] ) . ']"><option value="">' . esc_html__( '— choose —', 'otherguise' ) . '</option>';

		foreach ( (array) $ambiguity['candidates'] as $candidate ) {
			$topic = $this->topics->get( (int) $candidate );

			if ( null === $topic ) {
				continue;
			}

			$text = (string) $topic['label'] . ' (' . $this->describe( $topic ) . ') #' . $topic['id'];

			if ( Resolver::INCOMPATIBLE === (string) ( $ambiguity['type'] ?? '' ) ) {
				/* translators: %s: a topic, with its kind and the topics above it. */
				$text = sprintf( __( 'Keep %s', 'otherguise' ), $text );
			}

			$html .= '<option value="' . esc_attr( (string) $topic['id'] ) . '">' . esc_html( $text ) . '</option>';
		}

		return $html . '</select>';
	}

	/**
	 * What the list asks: which topic a level is (several hesitate), or what to do when the only topic of that name is of a kind that the source cannot mean
	 * (slice 632).
	 *
	 * @param array $ambiguity The ambiguity of the motif.
	 * @return string
	 */
	private function question( array $ambiguity ) {
		$level = (string) ( $ambiguity['level'] ?? '' );

		if ( Resolver::INCOMPATIBLE !== (string) ( $ambiguity['type'] ?? '' ) ) {
			/* translators: %s: the label of the level that hesitates. */
			return sprintf( __( 'Which "%s"?', 'otherguise' ), $level );
		}

		/* translators: 1: the label of the level, 2: the kind of the topic that exists, 3: the kind that the source gives. */
		return sprintf( __( '"%1$s" is incompatible: the topic is a %2$s, the source says %3$s.', 'otherguise' ), $level, (string) Kinds::label( (string) ( $ambiguity['found_kind'] ?? '' ) ), (string) Kinds::label( (string) ( $ambiguity['asked_kind'] ?? '' ) ) );
	}

	/**
	 * What tells a topic from its namesakes: its kind and the topics above it.
	 *
	 * @param array $topic A topic.
	 * @return string
	 */
	private function describe( array $topic ) {
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

		return (string) Kinds::label( (string) $topic['kind'] ) . ( array() === $above ? '' : ', ' . implode( ' > ', $above ) );
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
		return esc_html( ! empty( $item['incompatible'] ) ? __( 'incompatible', 'otherguise' ) : (string) $item['state'] ) . ( '' === $item['wikidata'] ? '' : ' <small>' . esc_html( (string) $item['wikidata'] ) . '</small>' );
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
