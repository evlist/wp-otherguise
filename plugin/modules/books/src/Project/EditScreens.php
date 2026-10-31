<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The lists and the edit screens of the printer profiles and of the book projects.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Project;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the lists and the edit screens of the two types fit what they are (slice 234): contents that nobody reads on the site and that a form
 * edits, not posts. WordPress gives them the screens of posts, with a quick edit of the date and of the password, the status "draft" and
 * "published", and the boxes that the other plugins add to every type. Here:
 *
 * - the list has no quick edit (row action and bulk action), no filter by status, and columns that say what the profile or the project is
 *   instead of the date;
 * - the edit screen has only the box of the type and a box "Save" (a button and the link to the trash) instead of the box "Publish": the
 *   boxes of the other plugins are taken away (their scripts may still load), and what is saved is always published, there is no draft.
 */
final class EditScreens {
	/**
	 * Id of the box that replaces the box "Publish".
	 */
	public const SAVE_BOX = 'books-save';

	/**
	 * Returns the titles of the sections, by id.
	 *
	 * @var callable
	 */
	private $sections;

	/**
	 * Returns the titles of the printer profiles, by id.
	 *
	 * @var callable
	 */
	private $profiles;

	/**
	 * The relations of the projects (`root()` and `profile()`).
	 *
	 * @var object
	 */
	private $links;

	/**
	 * Receives an id and a meta key; returns the meta.
	 *
	 * @var callable
	 */
	private $get_meta;

	/**
	 * Returns the boxes of a screen, as WordPress keeps them: by context, then by priority, then by id.
	 *
	 * @var callable
	 */
	private $boxes;

	/**
	 * Builds the screens.
	 *
	 * @param callable      $sections Returns the titles of the sections, by id.
	 * @param callable      $profiles Returns the titles of the printer profiles, by id.
	 * @param object        $links    The relations (`ProjectLinks`): `root()` and `profile()`.
	 * @param callable|null $get_meta Receives an id and a key; defaults to `get_post_meta`.
	 * @param callable|null $boxes    Receives a type; returns the boxes of its screen; defaults to what WordPress keeps.
	 */
	public function __construct( $sections, $profiles, $links, $get_meta = null, $boxes = null ) {
		$this->sections = $sections;
		$this->profiles = $profiles;
		$this->links    = $links;
		$this->get_meta = $get_meta ?? static function ( $id, $key ) {
			return get_post_meta( (int) $id, (string) $key, true );
		};
		$this->boxes    = $boxes ?? static function ( $type ) {
			return isset( $GLOBALS['wp_meta_boxes'][ $type ] ) && is_array( $GLOBALS['wp_meta_boxes'][ $type ] ) ? $GLOBALS['wp_meta_boxes'][ $type ] : array();
		};
	}

	/**
	 * The types that these screens are made for.
	 *
	 * @return string[]
	 */
	private static function types() {
		return array( PostTypes::PROFILE, PostTypes::PROJECT );
	}

	/**
	 * Takes the quick edit away from a row of the list. Filter `post_row_actions`.
	 *
	 * @param mixed $actions The actions of the row.
	 * @param mixed $post    The post.
	 * @return mixed
	 */
	public function row_actions( $actions, $post ) {
		if ( is_array( $actions ) && is_object( $post ) && in_array( $post->post_type ?? '', self::types(), true ) ) {
			unset( $actions['inline hide-if-no-js'] );
		}

		return $actions;
	}

	/**
	 * Takes the bulk edit away. Filters `bulk_actions-edit-<type>`.
	 *
	 * @param mixed $actions The bulk actions.
	 * @return mixed
	 */
	public function bulk_actions( $actions ) {
		if ( is_array( $actions ) ) {
			unset( $actions['edit'] );
		}

		return $actions;
	}

	/**
	 * Keeps the links "All" and "Trash" of the list: there is no status to filter by. Filters `views_edit-<type>`.
	 *
	 * @param mixed $views The links.
	 * @return mixed
	 */
	public function views( $views ) {
		return is_array( $views ) ? array_intersect_key( $views, array_flip( array( 'all', 'trash' ) ) ) : $views;
	}

	/**
	 * The columns of the list of the printer profiles. Filter `manage_books_printer_posts_columns`.
	 *
	 * @param mixed $columns The columns.
	 * @return array<string, string>
	 */
	public function profile_columns( $columns ) {
		return array(
			'cb'          => is_array( $columns ) && isset( $columns['cb'] ) ? (string) $columns['cb'] : '<input type="checkbox" />',
			'title'       => __( 'Title', 'otherguise' ),
			'books_page'  => __( 'Page', 'otherguise' ),
			'books_folio' => __( 'Page numbers', 'otherguise' ),
		);
	}

	/**
	 * The columns of the list of the book projects. Filter `manage_books_project_posts_columns`.
	 *
	 * @param mixed $columns The columns.
	 * @return array<string, string>
	 */
	public function project_columns( $columns ) {
		return array(
			'cb'            => is_array( $columns ) && isset( $columns['cb'] ) ? (string) $columns['cb'] : '<input type="checkbox" />',
			'title'         => __( 'Title', 'otherguise' ),
			'books_root'    => __( 'Root section', 'otherguise' ),
			'books_profile' => __( 'Printer profile', 'otherguise' ),
			'books_scaling' => __( 'Scaling', 'otherguise' ),
		);
	}

	/**
	 * Prints a cell of the list of the profiles. Action `manage_books_printer_posts_custom_column`.
	 *
	 * @param string $column Column.
	 * @param int    $id     Id of the profile.
	 * @return void
	 */
	public function profile_column( $column, $id ) {
		$profile = PrinterProfile::normalize( ( $this->get_meta )( (int) $id, PostTypes::PROFILE_META ) );

		if ( 'books_page' === $column ) {
			$text = sprintf(
				/* translators: 1: width in mm, 2: height in mm, 3: margin in mm. */
				__( '%1$s × %2$s mm, margin %3$s mm', 'otherguise' ),
				self::number( (float) $profile['width_mm'] ),
				self::number( (float) $profile['height_mm'] ),
				self::number( (float) $profile['margin_mm'] )
			);

			if ( abs( (float) $profile['gutter_mm'] - (float) $profile['margin_mm'] ) > 0.05 ) {
				/* translators: %s: width in mm. */
				$text .= sprintf( __( ', gutter %s mm', 'otherguise' ), self::number( (float) $profile['gutter_mm'] ) );
			}

			echo esc_html( $text );
		} elseif ( 'books_folio' === $column ) {
			$labels = array(
				'none'    => __( 'None', 'otherguise' ),
				'center'  => __( 'Centred at the bottom', 'otherguise' ),
				'outside' => __( 'Bottom, on the outside', 'otherguise' ),
			);

			echo esc_html( $labels[ $profile['folio'] ] ?? '' );
		}
	}

	/**
	 * Prints a cell of the list of the projects. Action `manage_books_project_posts_custom_column`.
	 *
	 * @param string $column Column.
	 * @param int    $id     Id of the project.
	 * @return void
	 */
	public function project_column( $column, $id ) {
		if ( 'books_root' === $column ) {
			$titles = (array) ( $this->sections )();

			echo esc_html( $titles[ (int) $this->links->root( (int) $id ) ] ?? '—' );
		} elseif ( 'books_profile' === $column ) {
			$titles = (array) ( $this->profiles )();

			echo esc_html( $titles[ (int) $this->links->profile( (int) $id ) ] ?? '—' );
		} elseif ( 'books_scaling' === $column ) {
			$labels = array(
				'adapt' => __( 'Adapt', 'otherguise' ),
				'zoom'  => __( 'Zoom', 'otherguise' ),
				'scale' => __( 'Scale', 'otherguise' ),
			);

			echo esc_html( $labels[ Projects::normalize( ( $this->get_meta )( (int) $id, PostTypes::PROJECT_META ) )['scaling'] ] );
		}
	}

	/**
	 * Takes away the boxes of the other plugins and the box "Publish", and adds the box "Save". Actions `add_meta_boxes_<type>`, late (the
	 * other plugins add theirs first).
	 *
	 * @param mixed $post The post being edited.
	 * @return void
	 */
	public function boxes( $post ) {
		$type = is_object( $post ) ? (string) ( $post->post_type ?? '' ) : '';

		if ( ! in_array( $type, self::types(), true ) ) {
			return;
		}

		$own = array( 'books-profile', 'books-project', self::SAVE_BOX );

		foreach ( (array) ( $this->boxes )( $type ) as $context => $priorities ) {
			foreach ( (array) $priorities as $boxes ) {
				foreach ( array_keys( (array) $boxes ) as $id ) {
					if ( ! in_array( $id, $own, true ) ) {
						remove_meta_box( (string) $id, $type, (string) $context );
					}
				}
			}
		}

		add_meta_box( self::SAVE_BOX, __( 'Save', 'otherguise' ), array( $this, 'render_save' ), $type, 'side', 'high' );
	}

	/**
	 * Prints the box "Save": the button, and the link to the trash for what exists already.
	 *
	 * @param mixed $post The post being edited.
	 * @return void
	 */
	public function render_save( $post ) {
		echo '<div class="submitbox"><p><input type="submit" name="save" id="save-post" class="button button-primary button-large" value="' . esc_attr__( 'Save', 'otherguise' ) . '" /></p>';

		$link = is_object( $post ) && 'auto-draft' !== ( $post->post_status ?? '' ) && function_exists( 'get_delete_post_link' ) ? get_delete_post_link( (int) $post->ID ) : '';

		if ( is_string( $link ) && '' !== $link ) {
			echo '<p id="delete-action"><a class="submitdelete deletion" href="' . esc_url( $link ) . '">' . esc_html__( 'Move to the trash', 'otherguise' ) . '</a></p>';
		}

		echo '</div>';
	}

	/**
	 * The message after a save: the one of the type ("Book project saved."), not "Post updated.". Filter `post_updated_messages`.
	 *
	 * @param mixed $messages The messages, by type.
	 * @return array<string, mixed>
	 */
	public function messages( $messages ) {
		$messages = is_array( $messages ) ? $messages : array();

		foreach ( array(
			PostTypes::PROFILE => __( 'Printer profile saved.', 'otherguise' ),
			PostTypes::PROJECT => __( 'Book project saved.', 'otherguise' ),
		) as $type => $text ) {
			$messages[ $type ] = array(
				0  => '',
				1  => $text,
				4  => $text,
				6  => $text,
				7  => $text,
				8  => $text,
				9  => $text,
				10 => $text,
			);
		}

		return $messages;
	}

	/**
	 * What is saved is published: the two types have no draft (nothing is shown on the site, the notion has no meaning). A post that WordPress
	 * keeps as an auto-draft until it is saved, and the trash, are left alone. Filter `wp_insert_post_data`.
	 *
	 * @param mixed $data The data of the post.
	 * @return mixed
	 */
	public function publish( $data ) {
		if ( is_array( $data ) && in_array( $data['post_type'] ?? '', self::types(), true ) && in_array( $data['post_status'] ?? '', array( 'draft', 'pending', 'future' ), true ) ) {
			$data['post_status'] = 'publish';
		}

		return $data;
	}

	/**
	 * A number of millimetres without a trailing zero.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function number( $value ) {
		return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
	}
}
