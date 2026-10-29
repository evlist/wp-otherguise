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

defined( 'ABSPATH' ) || exit;

/**
 * Registers `books_printer` (a printer profile) and `books_project` (a book project) (slice 230). Both are reached from the hub **Settings →
 * Otherguise** (they are not in the menu) by users who can `edit_others_posts`, have no page on the site (not public, no rewrite, not in the search, the sitemaps or the REST API) and are
 * exported with the site. They are edited with a meta box, so the block editor is not used.
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
		register_post_type( self::PROFILE, $this->arguments( __( 'Printer profiles', 'otherguise' ), __( 'Printer profile', 'otherguise' ), __( 'Add a printer profile', 'otherguise' ) ) );
		register_post_type( self::PROJECT, $this->arguments( __( 'Book projects', 'otherguise' ), __( 'Book project', 'otherguise' ), __( 'Add a book project', 'otherguise' ) ) );
	}

	/**
	 * Declares the two lists of contents to the hub "Otherguise" of the Settings menu. Filter `otherguise_admin_screens`.
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
		$screens[] = array(
			'title'       => __( 'Book projects', 'otherguise' ),
			'description' => __( 'A book: a section, a printer profile and how the page is scaled.', 'otherguise' ),
			'capability'  => 'edit_others_posts',
			'post_type'   => self::PROJECT,
			'order'       => 50,
		);

		return $screens;
	}

	/**
	 * The arguments of a type.
	 *
	 * @param string $plural   Plural name.
	 * @param string $singular Singular name.
	 * @param string $add      Label of the button that adds one.
	 * @return array<string, mixed>
	 */
	private function arguments( $plural, $singular, $add ) {
		$capability = 'edit_others_posts';

		return array(
			'labels'              => array(
				'name'          => $plural,
				'singular_name' => $singular,
				'add_new'       => $add,
				'add_new_item'  => $add,
				'edit_item'     => $singular,
				'all_items'     => $plural,
				'menu_name'     => $plural,
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => false,
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
