<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The screen of the wanted pages.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Admin;

use Otherguise\Core\AdminMenu;
use Otherguise\Wiki\Tree\Citations;
use Otherguise\Wiki\Tree\Keys;
use Otherguise\Wiki\Tree\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Otherguise → Wanted pages (slice 506): each path that some page links to and that nobody has yet, with the pages that want it, the most wanted
 * first. The address of a wanted page leads to the offer of slice 504, where a user who may create makes the page (or the chain). Read-only.
 */
final class WantedScreen {
	public const PAGE = 'otherguise-wiki-wanted';

	/**
	 * The index of the links.
	 *
	 * @var Citations
	 */
	private $citations;

	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * The router, for addresses.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Tells whether the current user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Builds the screen.
	 *
	 * @param Citations     $citations The index of the links.
	 * @param Keys          $keys      The index of the tree.
	 * @param Router        $router    The router.
	 * @param callable|null $can       Default `current_user_can`.
	 */
	public function __construct( Citations $citations, Keys $keys, Router $router, $can = null ) {
		$this->citations = $citations;
		$this->keys      = $keys;
		$this->router    = $router;
		$this->can       = $can ?? 'current_user_can';
	}

	/**
	 * Registers the screen.
	 *
	 * @param callable $add_action `add_action`, or a double.
	 * @return void
	 */
	public function register( $add_action ) {
		$add_action( 'admin_menu', array( $this, 'add_menu' ), 10, 0 );
		$add_action( AdminMenu::FILTER, array( $this, 'declare_screen' ), 10, 1 );
	}

	/**
	 * The capability: whoever may edit pages; the filter `wiki_wanted_capability` changes it.
	 *
	 * @return string
	 */
	public function capability() {
		return (string) apply_filters( 'wiki_wanted_capability', 'edit_pages' );
	}

	/**
	 * Declares the screen to the overview of the menu. Filter `otherguise_admin_screens`.
	 *
	 * @param mixed $screens The screens declared so far.
	 * @return array<int|string, mixed>
	 */
	public function declare_screen( $screens ) {
		$screens   = is_array( $screens ) ? $screens : array();
		$screens[] = array(
			'title'       => __( 'Wanted pages', 'otherguise' ),
			'description' => __( 'The pages that other pages link to and that do not exist yet.', 'otherguise' ),
			'capability'  => $this->capability(),
			'page'        => self::PAGE,
			'group'       => 'settings',
			'order'       => 45,
		);

		return $screens;
	}

	/**
	 * Adds the entry to the menu. Action `admin_menu`.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			AdminMenu::SLUG,
			__( 'Wanted pages', 'otherguise' ),
			__( 'Wanted pages', 'otherguise' ),
			$this->capability(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! (bool) ( $this->can )( $this->capability() ) ) {
			return;
		}

		$wanted = $this->citations->wanted();

		echo '<div class="wrap"><h1>' . esc_html__( 'Wanted pages', 'otherguise' ) . '</h1>';

		if ( array() === $wanted ) {
			echo '<p>' . esc_html__( 'No page is wanted: every link leads to a page.', 'otherguise' ) . '</p></div>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'otherguise' ) . '</th><th>' . esc_html__( 'Wanted by', 'otherguise' ) . '</th></tr></thead><tbody>';

		foreach ( $wanted as $path => $from ) {
			$titles = explode( '/', $path );
			$by     = array();

			foreach ( $from as $id ) {
				$by[] = '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( $this->label( $id ) ) . '</a>';
			}

			echo '<tr><td><a href="' . esc_url( $this->router->canonical( $titles ) ) . '">/' . esc_html( $path ) . '</a></td><td>' . wp_kses( implode( ', ', $by ), array( 'a' => array( 'href' => true ) ) ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * The label of a page: its address.
	 *
	 * @param int $id The page.
	 * @return string
	 */
	private function label( $id ) {
		$titles = $this->keys->path_of( $id );

		return null === $titles ? '#' . $id : '/' . implode( '/', $titles );
	}
}
