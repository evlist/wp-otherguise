<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The table of statements.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a page of `StatementsView` with the conventions of the core list tables. The class is loaded only once `WP_List_Table` is.
 */
final class StatementsTable extends \WP_List_Table {
	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Builds the table.
	 *
	 * @param Environment $environment Environment.
	 */
	public function __construct( Environment $environment ) {
		parent::__construct(
			array(
				'singular' => 'statement',
				'plural'   => 'statements',
				'ajax'     => false,
			)
		);

		$this->environment = $environment;
	}

	/**
	 * Gives the table its rows.
	 *
	 * @param array<string, mixed> $page    Page, as given by `StatementsView::page()`.
	 * @param int                  $per_page Rows per page.
	 * @return void
	 */
	public function set_page( array $page, $per_page ) {
		$this->items = $page['rows'];

		if ( null !== $page['total'] ) {
			$this->set_pagination_args(
				array(
					'total_items' => $page['total'],
					'per_page'    => $per_page,
				)
			);
		}

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'id' );
	}

	/**
	 * Returns the columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'id'        => esc_html__( 'Id', 'triples' ),
			'subject'   => esc_html__( 'Subject', 'triples' ),
			'predicate' => esc_html__( 'Predicate', 'triples' ),
			'object'    => esc_html__( 'Object', 'triples' ),
			'about'     => esc_html__( 'About it', 'triples' ),
			'created'   => esc_html__( 'Created', 'triples' ),
		);
	}

	/**
	 * Returns the bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => esc_html__( 'Delete', 'triples' ) );
	}

	/**
	 * Prints the checkbox of a row.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="statement[]" value="' . esc_attr( (string) $item['id'] ) . '" />';
	}

	/**
	 * Prints the id with the row action.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_id( $item ) {
		$url = $this->environment->page_url(
			array(
				'tab'       => 'statements',
				'action'    => 'delete',
				'statement' => array( (string) $item['id'] ),
			)
		);

		return esc_html( (string) $item['id'] ) . $this->row_actions( array( 'delete' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Delete', 'triples' ) . '</a>' ) );
	}

	/**
	 * Prints the subject.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_subject( $item ) {
		return Markup::node( $item['subject'] );
	}

	/**
	 * Prints the predicate.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_predicate( $item ) {
		return Markup::predicate( $item['predicate'] );
	}

	/**
	 * Prints the object.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_object( $item ) {
		return Markup::node( $item['object'] );
	}

	/**
	 * Prints the statements about the statement, one per line.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_about( $item ) {
		$lines = array();

		foreach ( $item['about'] as $about ) {
			$lines[] = Markup::predicate( $about['predicate'] ) . ' &rarr; ' . Markup::node( $about['object'] );
		}

		return implode( '<br />', $lines );
	}

	/**
	 * Prints the date of creation.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_created( $item ) {
		return esc_html( $this->environment->format_date( $item['created'] ) );
	}

	/**
	 * Prints the message of an empty table.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No statement found.', 'triples' );
	}
}
