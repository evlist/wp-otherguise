<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The page under Tools.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu entry and prints the frame of the screen: title, notice, tabs, then the screen of the tab.
 */
final class AdminPage {
	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Statements tab.
	 *
	 * @var StatementsScreen
	 */
	private $statements;

	/**
	 * Registered tab.
	 *
	 * @var RegisteredScreen
	 */
	private $registered;

	/**
	 * Maintenance tab.
	 *
	 * @var MaintenanceScreen
	 */
	private $maintenance;

	/**
	 * Adds an action.
	 *
	 * @var callable
	 */
	private $add_action;

	/**
	 * Builds the page.
	 *
	 * @param Environment       $environment Environment.
	 * @param StatementsScreen  $statements  Statements tab.
	 * @param RegisteredScreen  $registered  Registered tab.
	 * @param MaintenanceScreen $maintenance Maintenance tab.
	 * @param callable          $add_action  Adds an action.
	 */
	public function __construct( Environment $environment, StatementsScreen $statements, RegisteredScreen $registered, MaintenanceScreen $maintenance, $add_action ) {
		$this->environment = $environment;
		$this->statements  = $statements;
		$this->registered  = $registered;
		$this->maintenance = $maintenance;
		$this->add_action  = $add_action;
	}

	/**
	 * Adds the entry to the Tools menu. Called on `admin_menu`.
	 *
	 * @return void
	 */
	public function register_menu() {
		$hook = add_submenu_page(
			'tools.php',
			__( 'Relations', 'otherguise' ),
			__( 'Relations', 'otherguise' ),
			$this->environment->capability(),
			Environment::PAGE,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) ) {
			( $this->add_action )( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Adds the screen option "number of statements per page". Called when the page loads.
	 *
	 * @return void
	 */
	public function load() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Statements per page', 'otherguise' ),
				'default' => 20,
				'option'  => Environment::PER_PAGE_OPTION,
			)
		);
	}

	/**
	 * Keeps the value of the screen option. Filter `set_screen_option_triples_per_page`.
	 *
	 * @param mixed  $keep   Value kept by default.
	 * @param string $option Option name.
	 * @param mixed  $value  Value posted.
	 * @return int
	 */
	public function save_screen_option( $keep, $option, $value ) {
		unset( $keep, $option );

		return max( 1, min( 200, (int) $value ) );
	}

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->environment->can() ) {
			$this->environment->deny();

			return;
		}

		$tabs   = $this->tabs();
		$query  = $this->environment->query();
		$active = isset( $query['tab'], $tabs[ $query['tab'] ] ) ? $query['tab'] : 'statements';

		echo '<div class="wrap"><h1>' . esc_html__( 'Relations', 'otherguise' ) . '</h1>';
		$this->notice( $query );
		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			echo '<a class="nav-tab' . ( $slug === $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $this->environment->page_url( array( 'tab' => $slug ) ) ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '</nav>';

		if ( 'registered' === $active ) {
			$this->registered->render();
		} elseif ( 'maintenance' === $active ) {
			$this->maintenance->render();
		} else {
			$this->statements->render();
		}

		echo '</div>';
	}

	/**
	 * Returns the tabs.
	 *
	 * @return array<string, string>
	 */
	private function tabs() {
		return array(
			'statements'  => __( 'Statements', 'otherguise' ),
			'registered'  => __( 'Registered', 'otherguise' ),
			'maintenance' => __( 'Maintenance', 'otherguise' ),
		);
	}

	/**
	 * Prints the result of the last action.
	 *
	 * @param array<string, string|array<int, string>> $query Query string.
	 * @return void
	 */
	private function notice( array $query ) {
		$code  = $query['triples_notice'] ?? '';
		$count = absint( $query['n'] ?? 0 );

		if ( 'deleted' === $code || 'orphans_deleted' === $code || 'predicate_deleted' === $code ) {
			/* translators: %d: number of statements deleted. */
			$message = sprintf( _n( '%d statement deleted.', '%d statements deleted.', $count, 'otherguise' ), $count );
			$class   = 'notice-success';
		} elseif ( 'predicate_refused' === $code ) {
			$message = __( 'This predicate is registered: its statements were not deleted.', 'otherguise' );
			$class   = 'notice-error';
		} else {
			return;
		}

		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
