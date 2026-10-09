<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The tab that shows what is registered.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the predicates, the entity types, the datatypes, and the statements of the predicates that are no longer registered.
 */
final class RegisteredScreen {
	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * View.
	 *
	 * @var RegisteredView
	 */
	private $view;

	/**
	 * Builds the screen.
	 *
	 * @param Environment    $environment Environment.
	 * @param RegisteredView $view        View.
	 */
	public function __construct( Environment $environment, RegisteredView $view ) {
		$this->environment = $environment;
		$this->view        = $view;
	}

	/**
	 * Prints the tab.
	 *
	 * @return void
	 */
	public function render() {
		$query   = $this->environment->query();
		$confirm = $query['confirm_predicate'] ?? '';

		if ( is_string( $confirm ) && '' !== $confirm && $this->confirm( $confirm ) ) {
			return;
		}

		$this->unregistered();
		$this->predicates();
		$this->entity_types();
		$this->datatypes();
	}

	/**
	 * Prints the confirmation page for the deletion of the statements of a predicate that is no longer registered.
	 *
	 * @param string $predicate Predicate slug asked.
	 * @return bool Whether the page was printed: the predicate must have statements and not be registered.
	 */
	private function confirm( $predicate ) {
		$unregistered = $this->view->unregistered();

		if ( ! isset( $unregistered[ $predicate ] ) ) {
			return false;
		}

		echo '<h2>' . esc_html__( 'Delete these statements?', 'triples' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of statements, 2: predicate slug. */
				_n( '%1$d statement of the predicate %2$s, and the statements about it, will be deleted. This cannot be undone.', '%1$d statements of the predicate %2$s, and the statements about them, will be deleted. This cannot be undone.', $unregistered[ $predicate ], 'triples' ),
				$unregistered[ $predicate ],
				$predicate
			)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( $this->environment->admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="triples_delete_predicate" /><input type="hidden" name="predicate" value="' . esc_attr( $predicate ) . '" />';
		$this->environment->print_nonce_field( 'triples_delete_predicate_' . $predicate );
		echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Delete', 'triples' ) . '" /> ';
		echo '<a class="button" href="' . esc_url( $this->environment->page_url( array( 'tab' => 'registered' ) ) ) . '">' . esc_html__( 'Cancel', 'triples' ) . '</a></form>';

		return true;
	}

	/**
	 * Prints the predicates that have statements but are not registered, with the link to the page that confirms their deletion.
	 *
	 * @return void
	 */
	private function unregistered() {
		$unregistered = $this->view->unregistered();

		if ( array() === $unregistered ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Predicates that are no longer registered', 'triples' ) . '</h2>';
		echo '<p>' . esc_html__( 'A plugin that registered these predicates may be deactivated. Deleting their statements cannot be undone.', 'triples' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Predicate', 'triples' ) . '</th><th>' . esc_html__( 'Statements', 'triples' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $unregistered as $slug => $count ) {
			$url = $this->environment->page_url(
				array(
					'tab'               => 'registered',
					'confirm_predicate' => (string) $slug,
				)
			);

			echo '<tr><td><code>' . esc_html( (string) $slug ) . '</code></td><td>' . esc_html( (string) $count ) . '</td><td>';
			echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Delete these statements…', 'triples' ) . '</a></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Prints the registered predicates.
	 *
	 * @return void
	 */
	private function predicates() {
		echo '<h2>' . esc_html__( 'Predicates', 'triples' ) . '</h2><table class="widefat striped"><thead><tr>';

		foreach ( array( 'Predicate', 'Subjects', 'Objects', 'Limits', 'Symmetric', 'On delete', 'Qualified by', 'Qualifies', 'Statements' ) as $heading ) {
			echo '<th>' . esc_html( $this->translate_heading( $heading ) ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $this->view->predicates() as $row ) {
			$html  = '<tr><td>' . esc_html( $row['label'] ) . '<br /><code>' . esc_html( $row['slug'] ) . '</code>';
			$html .= null === $row['inverse_label'] ? '' : '<br /><small>' . esc_html( $row['inverse_label'] ) . '</small>';
			$html .= '</td><td>' . Markup::types( $row['subject_types'] ) . '</td><td>' . Markup::types( $row['object_types'] ) . '</td><td>';
			$html .= esc_html(
				sprintf(
					/* translators: 1: maximum number of objects per subject, 2: maximum number of subjects per object. */
					__( 'objects per subject: %1$s; subjects per object: %2$s', 'triples' ),
					null === $row['max_objects'] ? '∞' : (string) $row['max_objects'],
					null === $row['max_subjects'] ? '∞' : (string) $row['max_subjects']
				)
			);
			$html .= '</td><td>' . Markup::yes_no( $row['symmetric'] ) . '</td><td>' . esc_html( $row['on_delete'] ) . '</td>';
			$html .= '<td>' . Markup::slugs( $row['qualified_by'] ) . '</td><td>' . Markup::slugs( $row['qualifies'] ) . '</td>';
			$html .= '<td>' . esc_html( (string) $row['count'] ) . '</td></tr>';

			echo wp_kses_post( $html );
		}

		echo '</tbody></table>';
	}

	/**
	 * Prints the entity types.
	 *
	 * @return void
	 */
	private function entity_types() {
		echo '<h2>' . esc_html__( 'Entity types', 'triples' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Type', 'triples' ) . '</th><th>' . esc_html__( 'Checks existence', 'triples' ) . '</th><th>' . esc_html__( 'Recognizes objects', 'triples' ) . '</th><th>' . esc_html__( 'Loads objects', 'triples' ) . '</th><th>' . esc_html__( 'Describes', 'triples' ) . '</th></tr></thead><tbody>';

		foreach ( $this->view->entity_types() as $row ) {
			echo wp_kses_post( '<tr><td>' . esc_html( $row['label'] ) . ' <code>' . esc_html( $row['slug'] ) . '</code></td><td>' . Markup::yes_no( $row['exists'] ) . '</td><td>' . Markup::yes_no( $row['identify'] ) . '</td><td>' . Markup::yes_no( $row['load'] ) . '</td><td>' . Markup::yes_no( $row['describe'] ) . '</td></tr>' );
		}

		echo '</tbody></table>';
	}

	/**
	 * Prints the datatypes.
	 *
	 * @return void
	 */
	private function datatypes() {
		echo '<h2>' . esc_html__( 'Datatypes', 'triples' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'triples' ) . '</th><th>' . esc_html__( 'XSD type', 'triples' ) . '</th></tr></thead><tbody>';

		foreach ( $this->view->datatypes() as $row ) {
			echo '<tr><td><code>' . esc_html( $row['name'] ) . '</code></td><td><code>' . esc_html( $row['datatype'] ) . '</code></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Translates a column heading of the table of predicates.
	 *
	 * @param string $heading Heading in English.
	 * @return string
	 */
	private function translate_heading( $heading ) {
		$headings = array(
			'Predicate'    => __( 'Predicate', 'triples' ),
			'Subjects'     => __( 'Subjects', 'triples' ),
			'Objects'      => __( 'Objects', 'triples' ),
			'Limits'       => __( 'Limits', 'triples' ),
			'Symmetric'    => __( 'Symmetric', 'triples' ),
			'On delete'    => __( 'On delete', 'triples' ),
			'Qualified by' => __( 'Qualified by', 'triples' ),
			'Qualifies'    => __( 'Qualifies', 'triples' ),
			'Statements'   => __( 'Statements', 'triples' ),
		);

		return $headings[ $heading ] ?? $heading;
	}
}
