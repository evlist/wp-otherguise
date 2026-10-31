<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The post types of the printer profiles and of the book projects.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Project;

use Otherguise\Core\AdminMenu;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `books_printer` (a printer profile) and `books_project` (a book project) (slice 230). Both have no page on the site (not public, no
 * rewrite, not in the search, the sitemaps or the REST API), are exported with the site and are edited by users who can `edit_others_posts` with
 * a meta box, so the block editor is not used. A printer profile is a setting: its list is an entry of the **Otherguise** menu (`add_menu()`,
 * after the settings pages). A book project is a content of the book: its own menu, as the posts and the pages have. Their lists and screens
 * are made to fit them by `EditScreens` (slice 234).
 */
final class PostTypes {
	/**
	 * Type of the printer profiles.
	 */
	public const PROFILE = 'books_printer';

	/**
	 * Type of the book projects.
	 */
	public const PROJECT = 'books_project';

	/**
	 * Meta that holds the fields of a profile.
	 */
	public const PROFILE_META = '_books_profile';

	/**
	 * Meta that holds the fields of a project.
	 */
	public const PROJECT_META = '_books_project';

	/**
	 * Registers the two types. Action `init`.
	 *
	 * @return void
	 */
	public function register() {
		register_post_type(
			self::PROFILE,
			$this->arguments(
				array(
					'name'               => __( 'Printer profiles', 'otherguise' ),
					'singular_name'      => __( 'Printer profile', 'otherguise' ),
					'add_new'            => __( 'Add a printer profile', 'otherguise' ),
					'add_new_item'       => __( 'Add a printer profile', 'otherguise' ),
					'edit_item'          => __( 'Edit the printer profile', 'otherguise' ),
					'new_item'           => __( 'New printer profile', 'otherguise' ),
					'search_items'       => __( 'Search printer profiles', 'otherguise' ),
					'not_found'          => __( 'No printer profile found.', 'otherguise' ),
					'not_found_in_trash' => __( 'No printer profile in the trash.', 'otherguise' ),
					'item_updated'       => __( 'Printer profile saved.', 'otherguise' ),
					'item_published'     => __( 'Printer profile saved.', 'otherguise' ),
				),
				array( 'show_in_menu' => false ) // `add_menu()` adds it to the Otherguise menu.
			)
		);
		register_post_type(
			self::PROJECT,
			$this->arguments(
				array(
					'name'               => __( 'Book projects', 'otherguise' ),
					'singular_name'      => __( 'Book project', 'otherguise' ),
					'add_new'            => __( 'Add a book project', 'otherguise' ),
					'add_new_item'       => __( 'Add a book project', 'otherguise' ),
					'edit_item'          => __( 'Edit the book project', 'otherguise' ),
					'new_item'           => __( 'New book project', 'otherguise' ),
					'search_items'       => __( 'Search book projects', 'otherguise' ),
					'not_found'          => __( 'No book project found.', 'otherguise' ),
					'not_found_in_trash' => __( 'No book project in the trash.', 'otherguise' ),
					'item_updated'       => __( 'Book project saved.', 'otherguise' ),
					'item_published'     => __( 'Book project saved.', 'otherguise' ),
				),
				array(
					'show_in_menu'  => true,
					'menu_position' => 21, // After the pages (20).
					'menu_icon'     => 'dashicons-book',
				)
			)
		);
	}

	/**
	 * Adds the list of the printer profiles to the Otherguise menu, after the settings pages (priority 11 of `admin_menu`): `show_in_menu` would
	 * add it before. Action `admin_menu`.
	 *
	 * @return void
	 */
	public function add_menu() {
		$title = __( 'Printer profiles', 'otherguise' );

		add_submenu_page( AdminMenu::SLUG, $title, $title, 'edit_others_posts', 'edit.php?post_type=' . self::PROFILE );
	}

	/**
	 * Declares the list of the printer profiles to the overview of the Otherguise menu (a book project has its own menu). Filter
	 * `otherguise_admin_screens`.
	 *
	 * @param mixed $screens The screens declared so far.
	 * @return array<int|string, mixed>
	 */
	public function declare_screens( $screens ) {
		$screens   = is_array( $screens ) ? $screens : array();
		$screens[] = array(
			'title'       => __( 'Printer profiles', 'otherguise' ),
			'description' => __( 'The rules of a printer: format, margins, pages, images.', 'otherguise' ),
			'capability'  => 'edit_others_posts',
			'post_type'   => self::PROFILE,
			'order'       => 40,
		);
		return $screens;
	}

	/**
	 * The arguments of a type.
	 *
	 * @param array<string, string> $labels The labels of the type (the menu name is its plural name).
	 * @param array<string, mixed>  $menu   What differs between the two types: where it is in the menu.
	 * @return array<string, mixed>
	 */
	private function arguments( array $labels, array $menu ) {
		$capability = 'edit_others_posts';

		return $menu + array(
			'labels'              => $labels + array(
				'all_items' => $labels['name'],
				'menu_name' => $labels['name'],
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			'map_meta_cap'        => true,
			'supports'            => array( 'title' ),
			'capabilities'        => array(
				'edit_posts'             => $capability,
				'edit_others_posts'      => $capability,
				'publish_posts'          => $capability,
				'read_private_posts'     => $capability,
				'delete_posts'           => $capability,
				'delete_private_posts'   => $capability,
				'delete_published_posts' => $capability,
				'delete_others_posts'    => $capability,
				'edit_private_posts'     => $capability,
				'edit_published_posts'   => $capability,
			),
		);
	}
}
