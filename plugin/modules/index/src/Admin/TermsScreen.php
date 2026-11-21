<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The screen where the kinds of the categories and the tags are set.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Admin;

use Otherguise\Core\AdminMenu;
use Otherguise\Index\Kinds;

defined( 'ABSPATH' ) || exit;

/**
 * Otherguise → Index terms (slice 600): the categories or the tags with their number of posts and a kind selector per row, paged and
 * filterable (by name, by "no kind yet"), and a bulk action that sets one kind on the checked rows. Saved through `admin-post.php`, with the
 * capability and a nonce. A term with no kind is not indexed.
 */
final class TermsScreen {
	public const PAGE     = 'otherguise-index-terms';
	public const ACTION   = 'otherguise_index_set_kinds';
	public const NONCE    = 'otherguise_index_set_kinds';
	public const PER_PAGE = 50;

	/**
	 * The taxonomies the screen offers: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function taxonomies() {
		return array(
			'category' => __( 'Categories', 'otherguise' ),
			'post_tag' => __( 'Tags', 'otherguise' ),
		);
	}

	/**
	 * Lists terms: `fn( string $taxonomy, string $search, bool $no_kind_only, int $page, int $per_page ): array{rows: array<int, array{id:int,name:string,count:int,kind:string}>, total:int}`.
	 *
	 * @var callable
	 */
	private $lister;

	/**
	 * Applies kinds: `fn( string $taxonomy, array<int, string> $kinds ): int` returns the number changed.
	 *
	 * @var callable
	 */
	private $apply;

	/**
	 * Tells whether the current user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Reads a field of the request: `fn( int $type, string $name, bool $is_array ): string|array|null`, `$type` being `INPUT_POST` or
	 * `INPUT_GET`.
	 *
	 * @var callable
	 */
	private $input;

	/**
	 * Checks a nonce: `fn( string $nonce, string $action ): bool`.
	 *
	 * @var callable
	 */
	private $verify;

	/**
	 * Redirects and stops: `fn( string $url ): void`.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Builds the screen.
	 *
	 * @param callable      $lister      Lists the terms.
	 * @param callable      $apply       Applies kinds.
	 * @param callable|null $can         Default `current_user_can`.
	 * @param callable|null $input       Reads a field of the request; default `filter_input`.
	 * @param callable|null $verify      Checks a nonce; default `wp_verify_nonce`.
	 * @param callable|null $redirect    Default `wp_safe_redirect` then `exit`.
	 */
	public function __construct( $lister, $apply, $can = null, $input = null, $verify = null, $redirect = null ) {
		$this->lister      = $lister;
		$this->apply       = $apply;
		$this->can         = $can ?? 'current_user_can';
		$this->input       = $input ?? static function ( $type, $name, $is_array ) {
			return filter_input( $type, $name, FILTER_DEFAULT, $is_array ? FILTER_REQUIRE_ARRAY : FILTER_FLAG_NONE );
		};
		$this->verify      = $verify ?? 'wp_verify_nonce';
		$this->redirect    = $redirect ?? static function ( $url ) {
			wp_safe_redirect( $url );
			exit;
		};
	}

	/**
	 * Registers the screen and the handler.
	 *
	 * @param callable $add_action `add_action`, or a double.
	 * @return void
	 */
	public function register( $add_action ) {
		$add_action( 'admin_menu', array( $this, 'add_menu' ), 10, 0 );
		$add_action( AdminMenu::FILTER, array( $this, 'declare_screen' ), 10, 1 );
		$add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ), 10, 0 );
	}

	/**
	 * The capability: whoever may edit the posts of others; the filter `index_capability` changes it.
	 *
	 * @return string
	 */
	public function capability() {
		return (string) apply_filters( 'index_capability', 'edit_others_posts' );
	}

	/**
	 * Declares the screen to the overview of the menu. Filter `otherguise_admin_screens`.
	 *
	 * @param mixed $screens Screens declared so far.
	 * @return array<int|string, mixed>
	 */
	public function declare_screen( $screens ) {
		$screens   = is_array( $screens ) ? $screens : array();
		$screens[] = array(
			'title'       => __( 'Index terms', 'otherguise' ),
			'description' => __( 'Choose which categories and tags go into the index, and of what kind they are.', 'otherguise' ),
			'capability'  => $this->capability(),
			'page'        => self::PAGE,
			'group'       => 'settings',
			'order'       => 46,
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
			__( 'Index terms', 'otherguise' ),
			__( 'Index terms', 'otherguise' ),
			$this->capability(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Saves the kinds. Action `admin_post_otherguise_index_set_kinds`. The kinds of the rows that changed are applied, then the kind of the
	 * checked rows when a bulk kind is chosen.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! (bool) ( $this->can )( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'otherguise' ), '', array( 'response' => 403 ) );

			return;
		}

		$nonce = (string) ( $this->input )( INPUT_POST, '_wpnonce', false );

		if ( '' === $nonce || ! (bool) ( $this->verify )( $nonce, self::NONCE ) ) {
			wp_die( esc_html__( 'This form has expired: reload the page.', 'otherguise' ), '', array( 'response' => 403 ) );

			return;
		}

		$taxonomy = $this->taxonomy( sanitize_key( (string) ( $this->input )( INPUT_POST, 'taxonomy', false ) ) );
		$kinds    = array();
		$was      = $this->keyed( ( $this->input )( INPUT_POST, 'was', true ) );
		$now      = $this->keyed( ( $this->input )( INPUT_POST, 'kind', true ) );
		$checked  = array_map( 'absint', (array) ( $this->input )( INPUT_POST, 'checked', true ) );
		$bulk     = sanitize_key( (string) ( $this->input )( INPUT_POST, 'bulk_kind', false ) );

		foreach ( $now as $id => $kind ) {
			if ( (string) ( $was[ $id ] ?? '' ) !== $kind && $this->valid( $kind ) ) {
				$kinds[ (int) $id ] = 'none' === $kind ? '' : $kind;
			}
		}

		if ( '' !== $bulk && $this->valid( $bulk ) ) {
			foreach ( $checked as $id ) {
				$kinds[ (int) $id ] = 'none' === $bulk ? '' : $bulk;
			}
		}

		$changed = array() === $kinds ? 0 : (int) ( $this->apply )( $taxonomy, $kinds );

		( $this->redirect )(
			add_query_arg(
				array(
					'page'     => self::PAGE,
					'taxonomy' => $taxonomy,
					'changed'  => $changed,
				),
				admin_url( 'admin.php' )
			)
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

		$taxonomy = $this->taxonomy( sanitize_key( (string) ( $this->input )( INPUT_GET, 'taxonomy', false ) ) );
		$search   = sanitize_text_field( (string) ( $this->input )( INPUT_GET, 's', false ) );
		$no_kind  = '' !== (string) ( $this->input )( INPUT_GET, 'no_kind', false );
		$page     = max( 1, absint( (string) ( $this->input )( INPUT_GET, 'paged', false ) ) );
		$changed  = ( $this->input )( INPUT_GET, 'changed', false );
		$changed  = null === $changed || false === $changed ? null : absint( $changed );

		$list  = ( $this->lister )( $taxonomy, $search, $no_kind, $page, self::PER_PAGE );
		$pages = max( 1, (int) ceil( $list['total'] / self::PER_PAGE ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'Index terms', 'otherguise' ) . '</h1>';

		if ( null !== $changed ) {
			/* translators: %d: number of terms. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( '%d term changed.', '%d terms changed.', $changed, 'otherguise' ), $changed ) ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'A category or a tag with no kind is not in the index.', 'otherguise' ) . '</p>';

		echo '<ul class="subsubsub">';

		foreach ( self::taxonomies() as $slug => $label ) {
			echo '<li><a href="' . esc_url(
				add_query_arg(
					array(
						'page'     => self::PAGE,
						'taxonomy' => $slug,
					),
					admin_url( 'admin.php' )
				)
			) . '"' . ( $slug === $taxonomy ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a> | </li>';
		}

		echo '</ul>';

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="taxonomy" value="' . esc_attr( $taxonomy ) . '" />';
		echo '<p class="search-box"><input type="search" name="s" value="' . esc_attr( $search ) . '" /> <label><input type="checkbox" name="no_kind" value="1"' . checked( $no_kind, true, false ) . ' /> ' . esc_html__( 'No kind yet', 'otherguise' ) . '</label> ';
		echo '<button class="button">' . esc_html__( 'Filter', 'otherguise' ) . '</button></p></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="taxonomy" value="' . esc_attr( $taxonomy ) . '" />';

		echo '<p>' . esc_html__( 'Set the kind of the checked terms:', 'otherguise' ) . ' <select name="bulk_kind">';
		$this->print_options( 'choose', true );
		echo '</select> <button class="button">' . esc_html__( 'Save', 'otherguise' ) . '</button></p>';

		echo '<table class="widefat striped"><thead><tr><th></th><th>' . esc_html__( 'Name', 'otherguise' ) . '</th><th>' . esc_html__( 'Posts', 'otherguise' ) . '</th><th>' . esc_html__( 'Kind', 'otherguise' ) . '</th></tr></thead><tbody>';

		foreach ( $list['rows'] as $row ) {
			$id = (int) $row['id'];

			echo '<tr><td><input type="checkbox" name="checked[]" value="' . esc_attr( (string) $id ) . '" /></td><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( (string) $row['count'] ) . '</td>';
			echo '<td><input type="hidden" name="was[' . esc_attr( (string) $id ) . ']" value="' . esc_attr( '' === $row['kind'] ? 'none' : $row['kind'] ) . '" /><select name="kind[' . esc_attr( (string) $id ) . ']">';
			$this->print_options( $row['kind'] );
			echo '</select></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save the kinds', 'otherguise' ) . '</button></p></form>';

		if ( $pages > 1 ) {
			echo '<p>';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg(
							array(
								'page'     => self::PAGE,
								'taxonomy' => $taxonomy,
								's'        => $search,
								'no_kind'  => $no_kind ? 1 : 0,
							),
							admin_url( 'admin.php' )
						) . '&paged=%#%',
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Prints the options of a kind selector.
	 *
	 * @param string $selected The selected kind, '' for none.
	 * @param bool   $bulk     Whether it is the selector of the bulk action (it starts with "choose").
	 * @return void
	 */
	private function print_options( $selected, $bulk = false ) {
		if ( $bulk ) {
			echo '<option value="">' . esc_html__( '— choose —', 'otherguise' ) . '</option>';
		}

		echo '<option value="none"' . selected( 'none', '' === $selected ? 'none' : $selected, false ) . '>' . esc_html__( '— no kind —', 'otherguise' ) . '</option>';

		foreach ( Kinds::all() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, $selected, false ) . '>' . esc_html( $label ) . '</option>';
		}
	}

	/**
	 * A posted array turned into `id => sanitized slug`.
	 *
	 * @param mixed $posted The posted array, or null.
	 * @return array<int, string>
	 */
	private function keyed( $posted ) {
		$clean = array();

		foreach ( is_array( $posted ) ? $posted : array() as $id => $value ) {
			$clean[ (int) $id ] = sanitize_key( (string) $value );
		}

		return $clean;
	}

	/**
	 * A taxonomy the screen offers; the categories by default.
	 *
	 * @param string $slug Slug asked.
	 * @return string
	 */
	private function taxonomy( $slug ) {
		return array_key_exists( $slug, self::taxonomies() ) ? $slug : 'category';
	}

	/**
	 * Whether a posted kind is acceptable: a kind, `none` (the "no kind" row of a hidden field) or empty for none.
	 *
	 * @param string $kind Kind.
	 * @return bool
	 */
	private function valid( $kind ) {
		return 'none' === $kind || Kinds::is_kind( $kind );
	}
}
