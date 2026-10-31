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
 * One menu for the screens of every module: **Otherguise**, in the sidebar of the administration, with the settings first and then, after a
 * line that sets them apart, the tools (the relations of Triples). WordPress has two levels of menus and no way to nest one in the other, so
 * the screens of Otherguise are not scattered over Settings and Tools: they are all here.
 *
 * The modules add their screens under it (`add_submenu_page( AdminMenu::SLUG, … )`), and declare each one with the filter
 * `otherguise_admin_screens` (a title, a line that says what it is for, a capability, the page or the list of contents, and the group:
 * `settings` or `tools`) so that the overview, the first entry of the menu, can list them. A module that is not active adds and declares
 * nothing. The menu is registered before the modules (priority 5 of `admin_menu`): a submenu needs its parent to exist.
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
	 * Name of the filter that collects the screens.
	 */
	public const FILTER = 'otherguise_admin_screens';

	/**
	 * Returns what the modules declared (any value; it is checked here).
	 *
	 * @var callable
	 */
	private $declared;

	/**
	 * Tells whether the user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Adds an action.
	 *
	 * @var callable
	 */
	private $add_action;

	/**
	 * Builds the menu.
	 *
	 * @param callable|null $declared   Returns the declared screens; defaults to the filter.
	 * @param callable|null $can        Receives a capability; defaults to `current_user_can`.
	 * @param callable|null $add_action Adds an action; defaults to `add_action`.
	 */
	public function __construct( $declared = null, $can = null, $add_action = null ) {
		$this->declared   = $declared ?? static function () {
			return apply_filters( self::FILTER, array() );
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
		( $this->add_action )( 'admin_menu', array( $this, 'add' ), 5, 0 );
		( $this->add_action )( 'admin_enqueue_scripts', array( $this, 'separate' ), 10, 0 );
	}

	/**
	 * Adds the menu and its overview. Action `admin_menu`, priority 5.
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
	 * Sets the tools apart from the settings in the menu: a line above the first tool. A submenu of WordPress has no separator, so it is a style.
	 * Action `admin_enqueue_scripts`.
	 *
	 * @return void
	 */
	public function separate() {
		foreach ( $this->screens() as $screen ) {
			if ( 'tools' !== $screen['group'] ) {
				continue;
			}

			$target = '' !== $screen['page'] ? '?page=' . $screen['page'] : ( '' !== $screen['post_type'] ? 'post_type=' . $screen['post_type'] : '' );
			$target = preg_replace( '/[^A-Za-z0-9_=?&-]/', '', $target );

			if ( '' === $target ) {
				continue;
			}

			wp_register_style( 'otherguise-menu', false, array(), '1' );
			wp_enqueue_style( 'otherguise-menu' );
			wp_add_inline_style( 'otherguise-menu', '#adminmenu .wp-submenu li:has(> a[href$="' . $target . '"]){margin-top:6px;padding-top:6px;border-top:1px solid rgba(240,246,252,.25);}' );

			return;
		}
	}

	/**
	 * Prints the overview: the screens that the user may open, the settings and then the tools.
	 *
	 * @return void
	 */
	public function render() {
		$groups = array(
			'settings' => array(),
			'tools'    => array(),
		);

		foreach ( $this->screens() as $screen ) {
			if ( ( $this->can )( $screen['capability'] ) ) {
				$groups[ $screen['group'] ][] = $screen;
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Otherguise', 'otherguise' ) . '</h1>';

		if ( array() === $groups['settings'] && array() === $groups['tools'] ) {
			echo '<p>' . esc_html__( 'No screen of Otherguise is available to you.', 'otherguise' ) . '</p></div>';

			return;
		}

		foreach ( array(
			'settings' => __( 'Settings', 'otherguise' ),
			'tools'    => __( 'Tools', 'otherguise' ),
		) as $group => $heading ) {
			if ( array() === $groups[ $group ] ) {
				continue;
			}

			echo '<h2>' . esc_html( $heading ) . '</h2><ul style="list-style:disc;margin-left:1.5em;">';

			foreach ( $groups[ $group ] as $screen ) {
				echo '<li><a href="' . esc_url( $this->address( $screen ) ) . '"><strong>' . esc_html( $screen['title'] ) . '</strong></a>';
				echo '' === $screen['description'] ? '' : ' &mdash; ' . esc_html( $screen['description'] );
				echo '</li>';
			}

			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * The address of a screen: its page, the list of its contents, or the address it was given.
	 *
	 * @param array<string, mixed> $screen A screen.
	 * @return string
	 */
	private function address( array $screen ) {
		if ( '' !== $screen['page'] ) {
			return admin_url( 'admin.php?page=' . rawurlencode( $screen['page'] ) );
		}

		if ( '' !== $screen['post_type'] ) {
			return admin_url( 'edit.php?post_type=' . rawurlencode( $screen['post_type'] ) );
		}

		return admin_url( $screen['url'] );
	}

	/**
	 * What the modules declared, checked and sorted. A screen has a `title`, one of `page` (the slug of an admin page of a module), `post_type`
	 * (a list of contents) or `url` (an address of the administration), and may have a `description`, a `capability` (`manage_options` by
	 * default), a `group` (`settings`, or `tools`) and an `order`. What is not valid is left out.
	 *
	 * @return array<int, array{title:string, description:string, capability:string, page:string, post_type:string, url:string, group:string, order:int}>
	 */
	private function screens() {
		$screens = array();

		foreach ( (array) ( $this->declared )() as $declared ) {
			if ( ! is_array( $declared ) || ! isset( $declared['title'] ) || ! is_string( $declared['title'] ) || '' === $declared['title'] ) {
				continue;
			}

			$screen = array(
				'title'       => $declared['title'],
				'description' => isset( $declared['description'] ) && is_string( $declared['description'] ) ? $declared['description'] : '',
				'capability'  => isset( $declared['capability'] ) && is_string( $declared['capability'] ) && '' !== $declared['capability'] ? $declared['capability'] : 'manage_options',
				'page'        => isset( $declared['page'] ) && is_string( $declared['page'] ) ? $declared['page'] : '',
				'post_type'   => isset( $declared['post_type'] ) && is_string( $declared['post_type'] ) ? $declared['post_type'] : '',
				'url'         => isset( $declared['url'] ) && is_string( $declared['url'] ) ? $declared['url'] : '',
				'group'       => isset( $declared['group'] ) && 'tools' === $declared['group'] ? 'tools' : 'settings',
				'order'       => isset( $declared['order'] ) && is_numeric( $declared['order'] ) ? (int) $declared['order'] : 10,
			);

			if ( '' !== $screen['page'] || '' !== $screen['post_type'] || '' !== $screen['url'] ) {
				$screens[] = $screen;
			}
		}

		usort(
			$screens,
			static function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);

		return $screens;
	}
}
