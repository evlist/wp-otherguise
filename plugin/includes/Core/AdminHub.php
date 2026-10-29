<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The page "Otherguise" of the Settings menu: the hub of the screens of the modules.
 *
 * @package Otherguise
 */

namespace Otherguise\Core;

defined( 'ABSPATH' ) || exit;

/**
 * One entry in the Settings menu, **Settings → Otherguise**, that opens a page listing the screens of the modules, each with a line that says
 * what it is for, then a section "See also" for what is a tool and not a setting (the relations, under Tools).
 *
 * The screens of the modules are not in the menu: a module declares its screen with the filter `otherguise_admin_screens` (a page, a list of
 * contents or an address). A page that a module added to the Settings menu stays registered there, with its title and its address
 * (`options-general.php?page=<slug>`, so the Settings menu is the one that is open), but its line is hidden by a style added to the header of the
 * administration (taking it out of the menu would leave WordPress without a title for it, which is a deprecation notice in PHP 8.1 or later, and
 * a plugin may not assign the global title). When one of those screens is open, the Settings menu stays open on "Otherguise" and a breadcrumb
 * "Otherguise › Screen" leads back to the hub. A module that is not active declares nothing, so its line is not there.
 */
final class AdminHub {
	/**
	 * Slug of the page of the hub, under Settings.
	 */
	public const PAGE = 'otherguise';

	/**
	 * Capability that shows the entry in the menu: each screen is listed for who has its own capability.
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
	 * Adds a filter.
	 *
	 * @var callable
	 */
	private $add_filter;

	/**
	 * Returns the screen being shown: `page` (the slug of an admin page) and `post_type` (for a list or a form of contents).
	 *
	 * @var callable
	 */
	private $current;

	/**
	 * Builds the hub.
	 *
	 * @param callable|null $declared   Returns the declared screens; defaults to the filter.
	 * @param callable|null $can        Receives a capability; defaults to `current_user_can`.
	 * @param callable|null $add_action Adds an action; defaults to `add_action`.
	 * @param callable|null $add_filter Adds a filter; defaults to `add_filter`.
	 * @param callable|null $current    Returns the screen being shown; defaults to what WordPress knows.
	 */
	public function __construct( $declared = null, $can = null, $add_action = null, $add_filter = null, $current = null ) {
		$this->declared   = $declared ?? static function () {
			return apply_filters( self::FILTER, array() );
		};
		$this->can        = $can ?? 'current_user_can';
		$this->add_action = $add_action ?? 'add_action';
		$this->add_filter = $add_filter ?? 'add_filter';
		$this->current    = $current ?? static function () {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			return array(
				'page'      => isset( $GLOBALS['plugin_page'] ) ? (string) $GLOBALS['plugin_page'] : '',
				'post_type' => is_object( $screen ) && in_array( $screen->base ?? '', array( 'edit', 'post' ), true ) ? (string) ( $screen->post_type ?? '' ) : '',
			);
		};
	}

	/**
	 * Hooks the hub. Called when the plugin starts.
	 *
	 * @return void
	 */
	public function register() {
		( $this->add_action )( 'admin_menu', array( $this, 'add' ), 10, 0 );
		( $this->add_action )( 'admin_enqueue_scripts', array( $this, 'hide_entries' ), 10, 0 );
		( $this->add_action )( 'in_admin_header', array( $this, 'breadcrumb' ), 10, 0 );
		( $this->add_filter )( 'parent_file', array( $this, 'parent_file' ), 10, 1 );
		( $this->add_filter )( 'submenu_file', array( $this, 'submenu_file' ), 10, 1 );
	}

	/**
	 * Adds the entry to the Settings menu. Action `admin_menu`.
	 *
	 * @return void
	 */
	public function add() {
		add_options_page(
			__( 'Otherguise', 'otherguise' ),
			__( 'Otherguise', 'otherguise' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Hides the lines of the pages of the modules in the Settings menu: the hub leads to them. Action `admin_enqueue_scripts`.
	 *
	 * @return void
	 */
	public function hide_entries() {
		$rules = array();

		foreach ( $this->screens() as $screen ) {
			$page = preg_replace( '/[^A-Za-z0-9_-]/', '', $screen['page'] );

			if ( '' !== $page ) {
				$rules[] = '#adminmenu .wp-submenu li:has(> a[href$="?page=' . $page . '"])';
			}
		}

		if ( array() !== $rules ) {
			wp_register_style( 'otherguise-hub', false, array(), '1' );
			wp_enqueue_style( 'otherguise-hub' );
			wp_add_inline_style( 'otherguise-hub', implode( ',', $rules ) . '{display:none;}' );
		}
	}

	/**
	 * The address of the hub.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * Keeps the Settings menu open on a screen of a module. Filter `parent_file`.
	 *
	 * @param mixed $parent_file The parent the menu would open.
	 * @return mixed
	 */
	public function parent_file( $parent_file ) {
		return null === $this->open_screen() ? $parent_file : 'options-general.php';
	}

	/**
	 * Marks "Otherguise" as the entry of the submenu on a screen of a module. Filter `submenu_file`.
	 *
	 * @param mixed $submenu_file The entry the menu would mark.
	 * @return mixed
	 */
	public function submenu_file( $submenu_file ) {
		return null === $this->open_screen() ? $submenu_file : self::PAGE;
	}

	/**
	 * Prints "Otherguise › Screen" above a screen of a module. Action `in_admin_header`.
	 *
	 * @return void
	 */
	public function breadcrumb() {
		$screen = $this->open_screen();

		if ( null === $screen ) {
			return;
		}

		echo '<p class="otherguise-breadcrumb" style="margin:12px 20px 0;"><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Otherguise', 'otherguise' ) . '</a> &rsaquo; ' . esc_html( $screen['title'] ) . '</p>';
	}

	/**
	 * Prints the hub: the screens that the user may open, then "See also".
	 *
	 * @return void
	 */
	public function render() {
		$groups = array(
			'settings' => array(),
			'see-also' => array(),
		);

		foreach ( $this->screens() as $screen ) {
			if ( ( $this->can )( $screen['capability'] ) ) {
				$groups[ $screen['group'] ][] = $screen;
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Otherguise', 'otherguise' ) . '</h1>';

		if ( array() === $groups['settings'] ) {
			echo '<p>' . esc_html__( 'No screen of Otherguise is available to you.', 'otherguise' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'The screens of the active modules.', 'otherguise' ) . '</p>';
			$this->print_list( $groups['settings'] );
		}

		if ( array() !== $groups['see-also'] ) {
			echo '<h2>' . esc_html__( 'See also', 'otherguise' ) . '</h2>';
			$this->print_list( $groups['see-also'] );
		}

		echo '</div>';
	}

	/**
	 * Prints a list of screens: a link and what it is for.
	 *
	 * @param array<int, array<string, mixed>> $screens Screens.
	 * @return void
	 */
	private function print_list( array $screens ) {
		echo '<ul style="list-style:disc;margin-left:1.5em;">';

		foreach ( $screens as $screen ) {
			echo '<li><a href="' . esc_url( $this->address( $screen ) ) . '"><strong>' . esc_html( $screen['title'] ) . '</strong></a>';
			echo '' === $screen['description'] ? '' : ' &mdash; ' . esc_html( $screen['description'] );
			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * The screen of a module that is being shown, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function open_screen() {
		$current = (array) ( $this->current )();
		$page    = (string) ( $current['page'] ?? '' );
		$type    = (string) ( $current['post_type'] ?? '' );

		foreach ( $this->screens() as $screen ) {
			if ( 'settings' !== $screen['group'] ) {
				continue;
			}

			if ( ( '' !== $page && $page === $screen['page'] ) || ( '' !== $type && $type === $screen['post_type'] ) ) {
				return $screen;
			}
		}

		return null;
	}

	/**
	 * The address of a screen: its page, the list of its contents, or the address it was given.
	 *
	 * @param array<string, mixed> $screen A screen.
	 * @return string
	 */
	private function address( array $screen ) {
		if ( '' !== $screen['page'] ) {
			return admin_url( 'options-general.php?page=' . rawurlencode( $screen['page'] ) );
		}

		if ( '' !== $screen['post_type'] ) {
			return admin_url( 'edit.php?post_type=' . rawurlencode( $screen['post_type'] ) );
		}

		return admin_url( $screen['url'] );
	}

	/**
	 * What the modules declared, checked and sorted. A screen has a `title`, one of `page` (the slug of an admin page of a module), `post_type`
	 * (a list of contents) or `url` (an address of the administration), and may have a `description`, a `capability` (`manage_options` by
	 * default), a `group` (`settings`, or `see-also` for what is not a setting) and an `order`. What is not valid is left out.
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
				'group'       => isset( $declared['group'] ) && 'see-also' === $declared['group'] ? 'see-also' : 'settings',
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
