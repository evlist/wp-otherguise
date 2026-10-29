<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The menu "Otherguise" of the administration.
 *
 * @package Otherguise
 */

namespace Otherguise\Core;

defined( 'ABSPATH' ) || exit;

/**
 * One menu for the settings of every module: **Otherguise**, in the sidebar of the administration. The modules add their screens under it
 * (`add_submenu_page( AdminMenu::SLUG, … )`, or `'show_in_menu' => AdminMenu::SLUG` for a type of content), so a module that is not active
 * adds nothing, and the Settings menu keeps its own entries. The entry that WordPress makes with the menu itself is the overview page, which
 * lists the screens that are there. Registered before the modules (priority 5 of `admin_menu`): a submenu needs its parent to exist.
 * What is a tool and not a setting stays in the Tools menu.
 */
final class AdminMenu {
	/**
	 * Slug of the menu, the parent of the screens of the modules.
	 */
	public const SLUG = 'otherguise';

	/**
	 * Capability that shows the menu: each screen asks for its own as well.
	 */
	public const CAPABILITY = 'edit_others_posts';

	/**
	 * Returns the entries of the submenu: lists of the title, the capability and the slug (what WordPress keeps in `$submenu`).
	 *
	 * @var callable
	 */
	private $entries;

	/**
	 * Tells whether the user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Adds an action or a filter.
	 *
	 * @var callable
	 */
	private $add_action;

	/**
	 * Builds the menu.
	 *
	 * @param callable|null $entries    Returns the entries of the submenu; defaults to `$submenu` of WordPress.
	 * @param callable|null $can        Receives a capability; defaults to `current_user_can`.
	 * @param callable|null $add_action Adds an action; defaults to `add_action`.
	 */
	public function __construct( $entries = null, $can = null, $add_action = null ) {
		$this->entries = $entries ?? static function () {
			return isset( $GLOBALS['submenu'][ self::SLUG ] ) && is_array( $GLOBALS['submenu'][ self::SLUG ] ) ? $GLOBALS['submenu'][ self::SLUG ] : array();
		};
		$this->can        = $can ?? 'current_user_can';
		$this->add_action = $add_action ?? 'add_action';
	}

	/**
	 * Hooks the menu. Called when the plugin starts.
	 *
	 * @return void
	 */
	public function register() {
		( $this->add_action )( 'admin_menu', array( $this, 'add' ), 5 );
	}

	/**
	 * Adds the menu and its overview page. Action `admin_menu`, priority 5.
	 *
	 * @return void
	 */
	public function add() {
		add_menu_page(
			__( 'Otherguise', 'otherguise' ),
			__( 'Otherguise', 'otherguise' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-book-alt',
			81
		);

		// The first entry of the submenu is the menu itself: named for what it shows.
		add_submenu_page(
			self::SLUG,
			__( 'Otherguise', 'otherguise' ),
			__( 'Overview', 'otherguise' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Prints the overview: the screens of the active modules that the user may open.
	 *
	 * @return void
	 */
	public function render() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Otherguise', 'otherguise' ) . '</h1>';
		echo '<p>' . esc_html__( 'The screens of the active modules.', 'otherguise' ) . '</p><ul>';

		foreach ( (array) ( $this->entries )() as $entry ) {
			if ( ! is_array( $entry ) || self::SLUG === ( $entry[2] ?? '' ) || ! ( $this->can )( (string) ( $entry[1] ?? '' ) ) ) {
				continue;
			}

			$slug = (string) $entry[2];
			$url  = false !== strpos( $slug, '.php' ) ? admin_url( $slug ) : admin_url( 'admin.php?page=' . rawurlencode( $slug ) );

			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( wp_strip_all_tags( (string) $entry[0] ) ) . '</a></li>';
		}

		echo '</ul></div>';
	}
}
