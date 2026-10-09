<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Composition of the administration screen.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

use Otherguise\Triples\Datatype\DatatypeRegistry;
use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Predicate\PredicateRegistry;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\StatementStore;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the classes of the screen and hooks them to WordPress. Created only in the administration (see `Module::boot()`).
 */
final class Admin {
	/**
	 * The page.
	 *
	 * @var AdminPage
	 */
	private $page;

	/**
	 * The handlers.
	 *
	 * @var AdminActions
	 */
	private $actions;

	/**
	 * The settings.
	 *
	 * @var SettingsPage
	 */
	private $settings;

	/**
	 * Builds the screen.
	 *
	 * @param Environment        $environment Environment.
	 * @param Statements         $statements  Service.
	 * @param StatementStore     $store       Store.
	 * @param PredicateRegistry  $predicates  Predicates.
	 * @param EntityTypeRegistry $types       Entity types.
	 * @param DatatypeRegistry   $datatypes   Datatypes.
	 * @param callable           $add_action  Adds an action.
	 */
	public function __construct( Environment $environment, Statements $statements, StatementStore $store, PredicateRegistry $predicates, EntityTypeRegistry $types, DatatypeRegistry $datatypes, $add_action ) {
		$scanner        = new OrphanScanner( $store, $types );
		$view           = new StatementsView( $statements, $store, $predicates, $scanner );
		$this->settings = new SettingsPage( $environment );
		$this->actions  = new AdminActions( $environment, $statements, $store, $scanner, $predicates );
		$this->page     = new AdminPage(
			$environment,
			new StatementsScreen( $environment, $view, $store, $predicates, $types ),
			new RegisteredScreen( $environment, new RegisteredView( $predicates, $types, $datatypes, $store ) ),
			new MaintenanceScreen( $environment, $scanner, $view, $this->settings ),
			$add_action
		);
	}

	/**
	 * Adds the callbacks to WordPress: the menu, the settings, the screen option, and the three handlers.
	 *
	 * @param callable $add_action Adds an action or a filter (`add_action` is `add_filter` in WordPress).
	 * @return void
	 */
	public function register( $add_action ) {
		$add_action( 'admin_menu', array( $this->page, 'register_menu' ) );
		$add_action( 'admin_init', array( $this->settings, 'register' ) );
		$add_action( 'option_page_capability_' . SettingsPage::GROUP, array( $this->settings, 'capability' ) );
		$add_action( 'set_screen_option_' . Environment::PER_PAGE_OPTION, array( $this->page, 'save_screen_option' ), 10, 3 );
		$add_action( 'admin_post_triples_delete', array( $this->actions, 'delete' ) );
		$add_action( 'admin_post_triples_delete_orphans', array( $this->actions, 'delete_orphans' ) );
		$add_action( 'admin_post_triples_delete_predicate', array( $this->actions, 'delete_predicate' ) );
	}
}
