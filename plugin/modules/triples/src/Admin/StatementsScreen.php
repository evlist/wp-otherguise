<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The tab that lists the statements, and the page that confirms their deletion.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Predicate\PredicateRegistry;
use Otherguise\Triples\Storage\StatementStore;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the filters, the table and the confirmation page. Prints only; the deletion is done by `AdminActions`.
 */
final class StatementsScreen {
	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * View.
	 *
	 * @var StatementsView
	 */
	private $view;

	/**
	 * Store.
	 *
	 * @var StatementStore
	 */
	private $store;

	/**
	 * Predicates.
	 *
	 * @var PredicateRegistry
	 */
	private $predicates;

	/**
	 * Entity types.
	 *
	 * @var EntityTypeRegistry
	 */
	private $types;

	/**
	 * Builds the screen.
	 *
	 * @param Environment        $environment Environment.
	 * @param StatementsView     $view        View.
	 * @param StatementStore     $store       Store.
	 * @param PredicateRegistry  $predicates  Predicates.
	 * @param EntityTypeRegistry $types       Entity types.
	 */
	public function __construct( Environment $environment, StatementsView $view, StatementStore $store, PredicateRegistry $predicates, EntityTypeRegistry $types ) {
		$this->environment = $environment;
		$this->view        = $view;
		$this->store       = $store;
		$this->predicates  = $predicates;
		$this->types       = $types;
	}

	/**
	 * Prints the tab, or the confirmation page when a deletion was asked.
	 *
	 * @return void
	 */
	public function render() {
		$query = $this->environment->query();
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $query['statement'] ?? array() ) ) ) ) );

		if ( in_array( 'delete', array( $query['action'] ?? '', $query['action2'] ?? '' ), true ) ) {
			$this->confirm( $ids );

			return;
		}

		$filters = StatementsFilters::from_request( $query, $this->environment->per_page() );
		$page    = $this->view->page( $filters );
		$table   = new StatementsTable( $this->environment );

		$table->set_page( $page, $filters->per_page );
		$this->filters_form( $filters );

		echo '<form method="get" action="' . esc_url( $this->environment->admin_url( 'tools.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Environment::PAGE ) . '" /><input type="hidden" name="tab" value="statements" />';

		foreach ( $filters->to_args() as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}

		$table->display();
		echo '</form>';

		if ( null !== $page['next_after'] ) {
			$next = $this->environment->page_url( array( 'tab' => 'statements' ) + $filters->to_args() + array( 'after' => $page['next_after'] ) );

			echo '<p><a class="button" href="' . esc_url( $next ) . '">' . esc_html__( 'Look for more orphans', 'triples' ) . '</a></p>';
		} elseif ( $filters->orphans ) {
			echo '<p>' . esc_html__( 'The end of the table was reached.', 'triples' ) . '</p>';
		}
	}

	/**
	 * Prints the filters form.
	 *
	 * @param StatementsFilters $filters Filters.
	 * @return void
	 */
	private function filters_form( StatementsFilters $filters ) {
		echo '<form method="get" action="' . esc_url( $this->environment->admin_url( 'tools.php' ) ) . '" class="triples-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Environment::PAGE ) . '" /><input type="hidden" name="tab" value="statements" />';

		echo '<label>' . esc_html__( 'Predicate', 'triples' ) . ' <select name="predicate"><option value="">' . esc_html__( 'All', 'triples' ) . '</option>';

		foreach ( array_keys( $this->predicates->all() ) as $slug ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . ( $slug === $filters->predicate ? ' selected="selected"' : '' ) . '>' . esc_html( $slug ) . '</option>';
		}

		echo '<option value="' . esc_attr( StatementsFilters::UNREGISTERED ) . '"' . ( StatementsFilters::UNREGISTERED === $filters->predicate ? ' selected="selected"' : '' ) . '>' . esc_html__( 'Not registered', 'triples' ) . '</option></select></label> ';

		echo '<label>' . esc_html__( 'Type', 'triples' ) . ' <select name="entity_type"><option value="">' . esc_html__( 'All', 'triples' ) . '</option>';

		foreach ( array_keys( $this->types->all() ) as $slug ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . ( $slug === $filters->type ? ' selected="selected"' : '' ) . '>' . esc_html( $slug ) . '</option>';
		}

		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Entity', 'triples' ) . ' <input type="text" name="entity" placeholder="post:12" value="' . esc_attr( null === $filters->entity ? '' : (string) $filters->entity ) . '" /></label> ';
		echo '<label><input type="checkbox" name="with_qualifiers" value="1"' . ( $filters->with_qualifiers ? ' checked="checked"' : '' ) . ' /> ' . esc_html__( 'Include statements about statements', 'triples' ) . '</label> ';
		echo '<label><input type="checkbox" name="orphans" value="1"' . ( $filters->orphans ? ' checked="checked"' : '' ) . ' /> ' . esc_html__( 'Orphans only', 'triples' ) . '</label> ';
		echo '<label>' . esc_html__( 'Order', 'triples' ) . ' <select name="orderby"><option value="id"' . ( 'id' === $filters->orderby ? ' selected="selected"' : '' ) . '>' . esc_html__( 'Id', 'triples' ) . '</option><option value="created_gmt"' . ( 'created_gmt' === $filters->orderby ? ' selected="selected"' : '' ) . '>' . esc_html__( 'Date of creation', 'triples' ) . '</option></select></label> ';
		echo '<label><select name="order"><option value="asc">' . esc_html__( 'Ascending', 'triples' ) . '</option><option value="desc"' . ( $filters->descending ? ' selected="selected"' : '' ) . '>' . esc_html__( 'Descending', 'triples' ) . '</option></select></label> ';
		echo '<input type="submit" class="button" value="' . esc_attr__( 'Filter', 'triples' ) . '" /></form>';
	}

	/**
	 * Prints the confirmation page: what will be deleted, and the form that does it.
	 *
	 * @param int[] $ids Statement ids chosen.
	 * @return void
	 */
	private function confirm( array $ids ) {
		$chosen = $this->store->find_many( $ids );

		if ( array() === $chosen ) {
			echo '<p>' . esc_html__( 'Nothing was selected.', 'triples' ) . '</p>';

			return;
		}

		$existing = array_map( static fn( $statement ) => (int) $statement->id(), $chosen );
		$total    = count( $this->store->ids_with_dependents( $existing ) );
		$rows     = $this->view->rows( $chosen );

		echo '<h2>' . esc_html__( 'Delete these statements?', 'triples' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of statements chosen, 2: number of statements that will be deleted in all, the statements about them included. */
				_n( '%1$d statement is selected; %2$d statements will be deleted in all, with the statements about it.', '%1$d statements are selected; %2$d statements will be deleted in all, with the statements about them.', count( $existing ), 'triples' ),
				count( $existing ),
				$total
			)
		) . '</p><ul>';

		foreach ( $rows as $row ) {
			echo wp_kses_post( '<li>#' . esc_html( (string) $row['id'] ) . ': ' . Markup::node( $row['subject'] ) . ' &mdash; ' . Markup::predicate( $row['predicate'] ) . ' &mdash; ' . Markup::node( $row['object'] ) . '</li>' );
		}

		echo '</ul><form method="post" action="' . esc_url( $this->environment->admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="triples_delete" />';
		$this->environment->print_nonce_field( 'triples_bulk_delete' );

		foreach ( $existing as $id ) {
			echo '<input type="hidden" name="statement[]" value="' . esc_attr( (string) $id ) . '" />';
		}

		echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Delete', 'triples' ) . '" /> ';
		echo '<a class="button" href="' . esc_url( $this->environment->page_url( array( 'tab' => 'statements' ) ) ) . '">' . esc_html__( 'Cancel', 'triples' ) . '</a></form>';
	}
}
