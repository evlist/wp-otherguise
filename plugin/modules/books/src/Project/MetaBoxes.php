<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The meta boxes that edit a printer profile and a book project.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Project;

defined( 'ABSPATH' ) || exit;

/**
 * The forms of slice 230: one meta box on each of the two post types. The form is posted with the post, which WordPress already protects
 * (capability, nonce of the editor); the box adds its own nonce and checks `edit_post` again, and everything posted goes through
 * `PrinterProfile::normalize()` or `Projects::normalize()` before it is stored.
 */
final class MetaBoxes {
	/**
	 * Name of the nonce field.
	 */
	public const NONCE = 'books_project_nonce';

	/**
	 * Action of the nonce.
	 */
	public const ACTION = 'books_project_save';

	/**
	 * Name of the form array of a profile.
	 */
	public const PROFILE_FIELD = 'books_profile';

	/**
	 * Name of the form array of a project.
	 */
	public const PROJECT_FIELD = 'books_project';

	/**
	 * Reads a meta: receives the post id and the key.
	 *
	 * @var callable
	 */
	private $get_meta;

	/**
	 * Writes a meta: receives the post id, the key and the value.
	 *
	 * @var callable
	 */
	private $set_meta;

	/**
	 * The relations of the projects (`ProjectLinks`): `root()`, `profile()`, `set_root()` and `set_profile()`.
	 *
	 * @var object
	 */
	private $links;

	/**
	 * Returns the sections (id => title) a project can be rooted in.
	 *
	 * @var callable
	 */
	private $sections;

	/**
	 * Returns the profiles (id => title).
	 *
	 * @var callable
	 */
	private $profiles;

	/**
	 * Receives a capability and a post id; tells whether the user has it.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Receives the name of a form array; returns what was posted (an array of strings) or null.
	 *
	 * @var callable
	 */
	private $posted;

	/**
	 * Receives the nonce and the action; tells whether it is valid.
	 *
	 * @var callable
	 */
	private $nonce_valid;

	/**
	 * Builds the boxes.
	 *
	 * @param callable      $sections    Returns the sections: id => title.
	 * @param callable      $profiles    Returns the profiles: id => title.
	 * @param object        $links       The relations of the projects (`ProjectLinks`).
	 * @param callable|null $get_meta    Defaults to `get_post_meta` (single value).
	 * @param callable|null $set_meta    Defaults to `update_post_meta`.
	 * @param callable|null $can         Defaults to `current_user_can`.
	 * @param callable|null $posted      Defaults to a read of the form with `filter_input()`.
	 * @param callable|null $nonce_valid Defaults to `wp_verify_nonce`.
	 */
	public function __construct( $sections, $profiles, $links, $get_meta = null, $set_meta = null, $can = null, $posted = null, $nonce_valid = null ) {
		$this->sections    = $sections;
		$this->profiles    = $profiles;
		$this->links       = $links;
		$this->get_meta    = $get_meta ?? static function ( $id, $key ) {
			return get_post_meta( (int) $id, (string) $key, true );
		};
		$this->set_meta    = $set_meta ?? 'update_post_meta';
		$this->can         = $can ?? 'current_user_can';
		$this->posted      = $posted ?? static function ( $name ) {
			$value = filter_input( INPUT_POST, (string) $name, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
			$nonce = filter_input( INPUT_POST, self::NONCE, FILTER_DEFAULT );

			return is_array( $value ) ? array_merge( $value, array( '__nonce' => is_string( $nonce ) ? $nonce : '' ) ) : null;
		};
		$this->nonce_valid = $nonce_valid ?? static function ( $nonce, $action ) {
			return false !== wp_verify_nonce( (string) $nonce, (string) $action );
		};
	}

	/**
	 * Adds the box to each type. Action `add_meta_boxes`.
	 *
	 * @return void
	 */
	public function add() {
		add_meta_box( 'books-profile', __( 'Rules of the printer', 'otherguise' ), array( $this, 'render_profile' ), PostTypes::PROFILE, 'normal', 'high' );
		add_meta_box( 'books-project', __( 'Book project', 'otherguise' ), array( $this, 'render_project' ), PostTypes::PROJECT, 'normal', 'high' );
	}

	/**
	 * Prints the form of a profile.
	 *
	 * @param \WP_Post $post The profile.
	 * @return void
	 */
	public function render_profile( $post ) {
		$values = PrinterProfile::normalize( ( $this->get_meta )( (int) $post->ID, PostTypes::PROFILE_META ) );

		wp_nonce_field( self::ACTION, self::NONCE );
		echo '<p class="description">' . esc_html__( 'What the printer asks, as its documentation says. Margins and sizes are in millimetres. A recommendation of the printer is not a measured limit: Books reports and never refuses.', 'otherguise' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $this->profile_rows() as $key => $row ) {
			$name = self::PROFILE_FIELD . '[' . $key . ']';
			$id   = 'books-profile-' . $key;

			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $row['label'] ) . '</label></th><td>';
			$this->control( $row, $id, $name, $values[ $key ] );
			echo ! empty( $row['help'] ) ? '<p class="description">' . esc_html( $row['help'] ) . '</p>' : '';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Prints the form of a project.
	 *
	 * @param \WP_Post $post The project.
	 * @return void
	 */
	public function render_project( $post ) {
		$values = Projects::normalize( ( $this->get_meta )( (int) $post->ID, PostTypes::PROJECT_META ) );

		wp_nonce_field( self::ACTION, self::NONCE );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->select_row( 'root', __( 'Root section', 'otherguise' ), (array) ( $this->sections )(), (int) $this->links->root( (int) $post->ID ), __( 'A top-level section: the cover and title pages are units of this section, before the sections of the book.', 'otherguise' ) );
		$this->select_row( 'profile', __( 'Printer profile', 'otherguise' ), (array) ( $this->profiles )(), (int) $this->links->profile( (int) $post->ID ), __( 'The rules of the printer for this book. The same section can have several projects, one for each printer.', 'otherguise' ) );

		echo '<tr><th scope="row"><label for="books-project-author">' . esc_html__( 'Author', 'otherguise' ) . '</label></th><td><input type="text" id="books-project-author" class="regular-text" maxlength="200" name="' . esc_attr( self::PROJECT_FIELD . '[author]' ) . '" value="' . esc_attr( $values['author'] ) . '" /></td></tr>';
		$labels = array(
			'adapt' => __( 'Adapt: the theme follows the size of the page (the variable --books-k)', 'otherguise' ),
			'zoom'  => __( 'Zoom: the page of the theme is reduced or enlarged as a whole', 'otherguise' ),
			'scale' => __( 'Scale: the print server reduces or enlarges the page (not shown in the preview)', 'otherguise' ),
		);

		echo '<tr><th scope="row"><label for="books-project-scaling">' . esc_html__( 'Scaling', 'otherguise' ) . '</label></th><td><select id="books-project-scaling" name="' . esc_attr( self::PROJECT_FIELD . '[scaling]' ) . '">';

		foreach ( Projects::SCALINGS as $scaling ) {
			echo '<option value="' . esc_attr( $scaling ) . '"' . ( $values['scaling'] === $scaling ? ' selected="selected"' : '' ) . '>' . esc_html( $labels[ $scaling ] ) . '</option>';
		}

		echo '</select><p class="description">' . esc_html__( 'How a page smaller or larger than the one the theme is composed for is obtained. The factor compares the usable area of the printer profile with the one the theme declares.', 'otherguise' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Folios', 'otherguise' ) . '</th><td><input type="hidden" name="' . esc_attr( self::PROJECT_FIELD . '[folios]' ) . '" value="0" /><label><input type="checkbox" name="' . esc_attr( self::PROJECT_FIELD . '[folios]' ) . '" value="1"' . ( $values['folios'] ? ' checked="checked"' : '' ) . ' /> ' . esc_html__( 'Print the page numbers, where the profile puts them', 'otherguise' ) . '</label></td></tr>';
		echo '</tbody></table>';
	}

	/**
	 * Stores the form of a profile. Action `save_post_books_printer`, with the id of the post.
	 *
	 * @param int $post_id Id of the post.
	 * @return void
	 */
	public function save_profile( $post_id ) {
		$this->store( (int) $post_id, self::PROFILE_FIELD, PostTypes::PROFILE_META, array( PrinterProfile::class, 'normalize' ) );
	}

	/**
	 * Stores the form of a project: the author and the folios in its meta, the root section and the profile as relations. Action
	 * `save_post_books_project`, with the id of the post.
	 *
	 * @param int $post_id Id of the post.
	 * @return void
	 */
	public function save_project( $post_id ) {
		$post_id = (int) $post_id;
		$posted  = $this->posted_form( $post_id, self::PROJECT_FIELD );

		if ( null === $posted ) {
			return;
		}

		( $this->set_meta )( $post_id, PostTypes::PROJECT_META, Projects::normalize( $posted ) );
		$this->links->set_root( $post_id, isset( $posted['root'] ) && is_numeric( $posted['root'] ) ? max( 0, (int) $posted['root'] ) : 0 );
		$this->links->set_profile( $post_id, isset( $posted['profile'] ) && is_numeric( $posted['profile'] ) ? max( 0, (int) $posted['profile'] ) : 0 );
	}

	/**
	 * Checks the user and the nonce and stores the normalized form: nothing happens for an autosave, a user who may not edit the post, a
	 * request that does not carry the form, or a nonce that is not valid.
	 *
	 * @param int      $post_id   Id of the post.
	 * @param string   $field     Name of the form array.
	 * @param string   $meta      Key of the meta.
	 * @param callable $normalize Receives what was posted; returns what is stored.
	 * @return void
	 */
	private function store( $post_id, $field, $meta, $normalize ) {
		$posted = $this->posted_form( $post_id, $field );

		if ( null !== $posted ) {
			( $this->set_meta )( $post_id, $meta, $normalize( $posted ) );
		}
	}

	/**
	 * The form that was posted for a post, or null when it must not be stored: an autosave, a user who may not edit the post, no form, a
	 * nonce that is not valid.
	 *
	 * @param int    $post_id Id of the post.
	 * @param string $field   Name of the form array.
	 * @return array<string, mixed>|null
	 */
	private function posted_form( $post_id, $field ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! ( $this->can )( 'edit_post', $post_id ) ) {
			return null;
		}

		$posted = ( $this->posted )( $field );

		if ( null === $posted || ! ( $this->nonce_valid )( $posted['__nonce'] ?? '', self::ACTION ) ) {
			return null;
		}

		unset( $posted['__nonce'] );

		return $posted;
	}

	/**
	 * The rows of the form of a profile: label, kind and help.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function profile_rows() {
		return array(
			'width_mm'       => array(
				'label' => __( 'Page width (mm)', 'otherguise' ),
				'type' => 'number',
				'step' => '0.1',
			),
			'height_mm'      => array(
				'label' => __( 'Page height (mm)', 'otherguise' ),
				'type' => 'number',
				'step' => '0.1',
			),
			'margin_mm'      => array(
				'label' => __( 'Margin (mm)', 'otherguise' ),
				'type' => 'number',
				'step' => '0.1',
				'help' => __( 'Top, bottom and outer margin.', 'otherguise' ),
			),
			'gutter_mm'      => array(
				'label' => __( 'Gutter (mm)', 'otherguise' ),
				'type' => 'number',
				'step' => '0.1',
				'help' => __( 'Inner margin, on the binding side. Keep margin + gutter the same on both sides of a book: the text wraps the same on a right-hand and on a left-hand page.', 'otherguise' ),
			),
			'safe_mm'        => array(
				'label' => __( 'Safety zone (mm)', 'otherguise' ),
				'type' => 'number',
				'step' => '0.1',
				'help' => __( 'Nothing important should be nearer the edge (a recommendation of the printer; used by the checks).', 'otherguise' ),
			),
			'bleed_mm'       => array(
				'label' => __( 'Bleed (mm)', 'otherguise' ),
				'type' => 'number',
				'step' => '0.1',
				'help' => __( 'Only when images go to the edge of the page: the page grows by this on each side.', 'otherguise' ),
			),
			'folio'          => array(
				'label' => __( 'Page numbers', 'otherguise' ),
				'type' => 'select',
				'options' => array(
					'none' => __( 'None', 'otherguise' ),
					'center' => __( 'Centred at the bottom', 'otherguise' ),
					'outside' => __( 'Bottom, on the outside', 'otherguise' ),
				),
			),
			'pages_min'      => array(
				'label' => __( 'Fewest pages', 'otherguise' ),
				'type' => 'number',
				'step' => '1',
				'help' => __( '0 for none. It depends on the binding.', 'otherguise' ),
			),
			'pages_max'      => array(
				'label' => __( 'Most pages', 'otherguise' ),
				'type' => 'number',
				'step' => '1',
				'help' => __( '0 for none. It depends on the binding and on the paper.', 'otherguise' ),
			),
			'pages_multiple' => array(
				'label' => __( 'The number of pages is a multiple of', 'otherguise' ),
				'type' => 'number',
				'step' => '1',
				'help' => __( 'A sheet has two pages: 2.', 'otherguise' ),
			),
			'final_blank'    => array(
				'label' => __( 'A blank last page', 'otherguise' ),
				'type' => 'checkbox',
				'help' => __( 'Some printers put their notice there; otherwise they add pages.', 'otherguise' ),
			),
			'chapter_recto'  => array(
				'label' => __( 'A chapter starts on a right-hand page', 'otherguise' ),
				'type' => 'checkbox',
			),
			'cover'          => array(
				'label' => __( 'Cover', 'otherguise' ),
				'type' => 'select',
				'options' => array(
					'none' => __( 'Not in the file', 'otherguise' ),
					'separate' => __( 'A separate file', 'otherguise' ),
					'first_last' => __( 'First and last pages of the file', 'otherguise' ),
					'combined' => __( 'Combined file (blank second and second-to-last pages)', 'otherguise' ),
				),
			),
			'min_ppi'        => array(
				'label' => __( 'Fewest pixels per inch', 'otherguise' ),
				'type' => 'number',
				'step' => '1',
				'help' => __( '0 for no limit.', 'otherguise' ),
			),
			'colour'         => array(
				'label' => __( 'Colours', 'otherguise' ),
				'type' => 'select',
				'options' => array(
					'rgb' => 'RGB',
					'cmyk' => 'CMYK',
				),
			),
			'max_mb'         => array(
				'label' => __( 'Largest file (MB)', 'otherguise' ),
				'type' => 'number',
				'step' => '1',
				'help' => __( '0 for no limit.', 'otherguise' ),
			),
			'url'            => array(
				'label' => __( 'Documentation of the printer', 'otherguise' ),
				'type' => 'url',
			),
			'read_on'        => array(
				'label' => __( 'Read on (YYYY-MM-DD)', 'otherguise' ),
				'type' => 'text',
				'help' => __( 'The rules change: the date on which the documentation was read.', 'otherguise' ),
			),
		);
	}

	/**
	 * Prints a control.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param string               $id    Id of the control.
	 * @param string               $name  Name of the control.
	 * @param mixed                $value Its value.
	 * @return void
	 */
	private function control( array $row, $id, $name, $value ) {
		if ( 'select' === $row['type'] ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';

			foreach ( $row['options'] as $option => $label ) {
				echo '<option value="' . esc_attr( (string) $option ) . '"' . ( (string) $option === (string) $value ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
			}

			echo '</select>';
			return;
		}

		if ( 'checkbox' === $row['type'] ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" /><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . ( $value ? ' checked="checked"' : '' ) . ' />';
			return;
		}

		echo '<input type="' . esc_attr( $row['type'] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"';

		if ( isset( $row['step'] ) ) {
			echo ' step="' . esc_attr( $row['step'] ) . '" min="0"';
		}

		echo ' class="' . esc_attr( 'number' === $row['type'] ? 'small-text' : 'regular-text' ) . '" value="' . esc_attr( (string) $value ) . '" />';
	}

	/**
	 * Prints a row with a select made of titles.
	 *
	 * @param string             $key     Key of the field.
	 * @param string             $label   Label.
	 * @param array<int, string> $options Titles by id.
	 * @param int                $current Current value.
	 * @param string             $help    Help.
	 * @return void
	 */
	private function select_row( $key, $label, array $options, $current, $help ) {
		$id   = 'books-project-' . $key;
		$name = self::PROJECT_FIELD . '[' . $key . ']';

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( $current > 0 && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = '';
		}

		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="0">' . esc_html__( '— none —', 'otherguise' ) . '</option>';

		foreach ( $options as $option => $title ) {
			echo '<option value="' . esc_attr( (string) $option ) . '"' . ( (int) $option === (int) $current ? ' selected="selected"' : '' ) . '>' . esc_html( '' !== $title ? $title : '#' . $option ) . '</option>';
		}

		echo '</select><p class="description">' . esc_html( $help ) . '</p></td></tr>';
	}
}
