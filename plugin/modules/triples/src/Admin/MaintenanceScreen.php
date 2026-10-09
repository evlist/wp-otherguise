<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The maintenance tab: orphans and settings.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the orphan scan (one batch per request) and the settings form.
 */
final class MaintenanceScreen {
	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Scanner.
	 *
	 * @var OrphanScanner
	 */
	private $scanner;

	/**
	 * View of the statements, for the display data.
	 *
	 * @var StatementsView
	 */
	private $view;

	/**
	 * Settings page.
	 *
	 * @var SettingsPage
	 */
	private $settings;

	/**
	 * Builds the screen.
	 *
	 * @param Environment    $environment Environment.
	 * @param OrphanScanner  $scanner     Scanner.
	 * @param StatementsView $view        View.
	 * @param SettingsPage   $settings    Settings page.
	 */
	public function __construct( Environment $environment, OrphanScanner $scanner, StatementsView $view, SettingsPage $settings ) {
		$this->environment = $environment;
		$this->scanner     = $scanner;
		$this->view        = $view;
		$this->settings    = $settings;
	}

	/**
	 * Prints the tab.
	 *
	 * @return void
	 */
	public function render() {
		echo '<h2>' . esc_html__( 'Orphans', 'triples' ) . '</h2>';
		echo '<p>' . esc_html__( 'A statement is an orphan when one of its ends no longer exists. The table is scanned 200 statements at a time.', 'triples' ) . '</p>';

		$query = $this->environment->query();

		if ( isset( $query['after'] ) ) {
			$this->batch( absint( $query['after'] ) );
		} else {
			echo '<p><a class="button" href="' . esc_url(
				$this->environment->page_url(
					array(
						'tab' => 'maintenance',
						'after' => 0,
					)
				)
			) . '">' . esc_html__( 'Scan the first 200 statements', 'triples' ) . '</a></p>';
		}

		echo '<h2>' . esc_html__( 'Settings', 'triples' ) . '</h2>';

		$this->settings->render_form();
	}

	/**
	 * Scans a batch and prints its orphans, the way to delete them and the way to go on.
	 *
	 * @param int $after Scan the statements after this id.
	 * @return void
	 */
	private function batch( $after ) {
		$batch = $this->scanner->scan( $after );
		$rows  = $this->view->rows( $batch['orphans'] );

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: id of the first statement not scanned yet, minus one; 2: number of orphans found. */
				_n( 'Statements up to #%1$d were scanned: %2$d orphan found.', 'Statements up to #%1$d were scanned: %2$d orphans found.', count( $rows ), 'triples' ),
				$batch['last_id'],
				count( $rows )
			)
		) . '</p>';

		if ( array() !== $rows ) {
			echo '<ul>';

			foreach ( $rows as $row ) {
				echo wp_kses_post( '<li>#' . esc_html( (string) $row['id'] ) . ': ' . Markup::node( $row['subject'] ) . ' &mdash; ' . Markup::predicate( $row['predicate'] ) . ' &mdash; ' . Markup::node( $row['object'] ) . '</li>' );
			}

			echo '</ul><form method="post" action="' . esc_url( $this->environment->admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="triples_delete_orphans" /><input type="hidden" name="after" value="' . esc_attr( (string) $after ) . '" />';
			$this->environment->print_nonce_field( 'triples_delete_orphans' );
			echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Delete the orphans of this batch', 'triples' ) . '" /></form>';
		}

		if ( $batch['done'] ) {
			echo '<p>' . esc_html__( 'The end of the table was reached.', 'triples' ) . '</p>';

			return;
		}

		echo '<p><a class="button" href="' . esc_url(
			$this->environment->page_url(
				array(
					'tab' => 'maintenance',
					'after' => $batch['last_id'],
				)
			)
		) . '">' . esc_html__( 'Scan the next 200 statements', 'triples' ) . '</a></p>';
	}
}
