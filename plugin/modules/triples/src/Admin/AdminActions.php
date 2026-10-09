<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Handlers of the forms of the administration screen.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

use Otherguise\Triples\Predicate\PredicateDefinition;
use Otherguise\Triples\Predicate\PredicateRegistry;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\StatementQuery;
use Otherguise\Triples\Storage\StatementStore;

defined( 'ABSPATH' ) || exit;

/**
 * The three `admin_post_` actions that delete: statements chosen on the list, the orphans of a batch, the statements of a predicate
 * that is no longer registered. Each one checks the capability, then the nonce, before reading or writing anything, deletes through
 * `Statements::delete()` (so the events are fired), and redirects to the screen with a result.
 */
final class AdminActions {
	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Service.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Store.
	 *
	 * @var StatementStore
	 */
	private $store;

	/**
	 * Scanner.
	 *
	 * @var OrphanScanner
	 */
	private $scanner;

	/**
	 * Predicates.
	 *
	 * @var PredicateRegistry
	 */
	private $predicates;

	/**
	 * Builds the handlers.
	 *
	 * @param Environment       $environment Environment.
	 * @param Statements        $statements  Service.
	 * @param StatementStore    $store       Store.
	 * @param OrphanScanner     $scanner     Scanner.
	 * @param PredicateRegistry $predicates  Predicates.
	 */
	public function __construct( Environment $environment, Statements $statements, StatementStore $store, OrphanScanner $scanner, PredicateRegistry $predicates ) {
		$this->environment = $environment;
		$this->statements  = $statements;
		$this->store       = $store;
		$this->scanner     = $scanner;
		$this->predicates  = $predicates;
	}

	/**
	 * Deletes the statements chosen on the list, with the statements about them.
	 *
	 * @return void
	 */
	public function delete() {
		$form = $this->guard( 'triples_bulk_delete' );
		$ids  = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $form['statement'] ?? array() ) ) ) ) );

		$deleted = (int) $this->statements->transaction(
			function () use ( $ids ) {
				$count = 0;

				foreach ( $ids as $id ) {
					$count += $this->statements->delete( $id );
				}

				return $count;
			}
		);

		$this->done( 'statements', 'deleted', $deleted );
	}

	/**
	 * Deletes the orphans of a batch. The batch is scanned again here: only the position of its start comes from the browser.
	 *
	 * @return void
	 */
	public function delete_orphans() {
		$form  = $this->guard( 'triples_delete_orphans' );
		$batch = $this->scanner->scan( absint( $form['after'] ?? 0 ) );

		$deleted = (int) $this->statements->transaction(
			function () use ( $batch ) {
				$count = 0;

				foreach ( $batch['orphans'] as $orphan ) {
					$count += $this->statements->delete( $orphan );
				}

				return $count;
			}
		);

		$this->done( 'maintenance', 'orphans_deleted', $deleted );
	}

	/**
	 * Deletes every statement of a predicate that is no longer registered. A registered predicate is refused.
	 *
	 * @return void
	 */
	public function delete_predicate() {
		if ( ! $this->environment->can() ) {
			$this->environment->deny();
		}

		$predicate = $this->environment->form()['predicate'] ?? '';
		$predicate = is_string( $predicate ) ? $predicate : '';

		$this->guard( 'triples_delete_predicate_' . $predicate );

		if ( ! PredicateDefinition::is_valid_slug( $predicate ) || $this->predicates->has( $predicate ) ) {
			$this->done( 'registered', 'predicate_refused', 0 );

			return;
		}

		$deleted = 0;

		do {
			$progress = 0;
			$batch    = $this->store->query( ( new StatementQuery() )->with_predicates( array( $predicate ) )->limit( 200 ) );

			foreach ( $batch as $statement ) {
				$progress += $this->statements->delete( $statement );
			}

			$deleted += $progress;
		} while ( $progress > 0 );

		$this->done( 'registered', 'predicate_deleted', $deleted );
	}

	/**
	 * Checks the capability, then the nonce; ends the request when either fails.
	 *
	 * @param string $action Action the nonce was made for.
	 * @return array<string, string|array<int, string>> The posted form.
	 */
	private function guard( $action ) {
		if ( ! $this->environment->can() ) {
			$this->environment->deny();
		}

		$form  = $this->environment->form();
		$nonce = $form['_wpnonce'] ?? '';

		if ( ! is_string( $nonce ) || ! $this->environment->verify_nonce( $nonce, $action ) ) {
			$this->environment->deny();
		}

		return $form;
	}

	/**
	 * Redirects to the screen with the result.
	 *
	 * @param string $tab    Tab to show.
	 * @param string $notice Result code.
	 * @param int    $count  Number of statements concerned.
	 * @return void
	 */
	private function done( $tab, $notice, $count ) {
		$this->environment->redirect(
			$this->environment->page_url(
				array(
					'tab'            => $tab,
					'triples_notice' => $notice,
					'n'              => $count,
				)
			)
		);
	}
}
