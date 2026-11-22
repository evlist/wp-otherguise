<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The screen of the kinds of topics.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Admin;

use Otherguise\Core\AdminMenu;
use Otherguise\Index\Kinds;

defined( 'ABSPATH' ) || exit;

/**
 * Otherguise → Index kinds (slice 603): the tree of the kinds with their slug, label, CSS class and use, a form to add a kind and, per row, to
 * edit it, move it and delete it. A built-in kind keeps its slug, its place and its life (its label and class can change); a kind that comes
 * from code is read-only. Each form posts to `admin-post.php` with the capability and a nonce.
 */
final class KindsScreen {
	public const PAGE   = 'otherguise-index-kinds';
	public const ADD    = 'otherguise_index_kind_add';
	public const SAVE   = 'otherguise_index_kind_save';
	public const DELETE = 'otherguise_index_kind_delete';
	public const NONCE  = 'otherguise_index_kinds';

	/**
	 * Tells whether the current user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Reads a field of the request: `fn( int $type, string $name ): string|null`.
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
	 * Redirects and stops.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Builds the screen.
	 *
	 * @param callable|null $can      Default `current_user_can`.
	 * @param callable|null $input    Default `filter_input`.
	 * @param callable|null $verify   Default `wp_verify_nonce`.
	 * @param callable|null $redirect Default `wp_safe_redirect` then `exit`.
	 */
	public function __construct( $can = null, $input = null, $verify = null, $redirect = null ) {
		$this->can      = $can ?? 'current_user_can';
		$this->input    = $input ?? static function ( $type, $name ) {
			$value = filter_input( $type, $name );

			return is_string( $value ) ? $value : null;
		};
		$this->verify   = $verify ?? 'wp_verify_nonce';
		$this->redirect = $redirect ?? static function ( $url ) {
			wp_safe_redirect( $url );
			exit;
		};
	}

	/**
	 * Registers the screen and the handlers.
	 *
	 * @param callable $add_action `add_action`, or a double.
	 * @return void
	 */
	public function register( $add_action ) {
		$add_action( 'admin_menu', array( $this, 'add_menu' ), 10, 0 );
		$add_action( AdminMenu::FILTER, array( $this, 'declare_screen' ), 10, 1 );
		$add_action( 'admin_post_' . self::ADD, array( $this, 'add' ), 10, 0 );
		$add_action( 'admin_post_' . self::SAVE, array( $this, 'save' ), 10, 0 );
		$add_action( 'admin_post_' . self::DELETE, array( $this, 'delete' ), 10, 0 );
	}

	/**
	 * The capability: the one of the module (`index_capability`).
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
			'title'       => __( 'Index kinds', 'otherguise' ),
			'description' => __( 'The kinds of topics (places, species, routes...), and which is a sort of which.', 'otherguise' ),
			'capability'  => $this->capability(),
			'page'        => self::PAGE,
			'group'       => 'settings',
			'order'       => 47,
		);

		return $screens;
	}

	/**
	 * Adds the topic to the menu. Action `admin_menu`.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page( AdminMenu::SLUG, __( 'Index kinds', 'otherguise' ), __( 'Index kinds', 'otherguise' ), $this->capability(), self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * Adds a kind. Action `admin_post_otherguise_index_kind_add`.
	 *
	 * @return void
	 */
	public function add() {
		$this->done(
			self::ADD,
			static fn( $field ) => Kinds::add( sanitize_key( $field( 'slug' ) ), sanitize_text_field( $field( 'label' ) ), sanitize_key( $field( 'parent' ) ), sanitize_key( $field( 'css' ) ) )
		);
	}

	/**
	 * Saves a kind: its label and CSS class, and its place when it is not a built-in kind. Action `admin_post_otherguise_index_kind_save`.
	 *
	 * @return void
	 */
	public function save() {
		$this->done(
			self::SAVE,
			static function ( $field ) {
				$slug   = sanitize_key( $field( 'slug' ) );
				$kind   = Kinds::records()[ $slug ] ?? null;
				$result = Kinds::edit( $slug, sanitize_text_field( $field( 'label' ) ), sanitize_key( $field( 'css' ) ) );
				$parent = sanitize_key( $field( 'parent' ) );

				if ( true === $result && null !== $kind && ! $kind['builtin'] && $parent !== $kind['parent'] ) {
					$result = Kinds::move( $slug, $parent );
				}

				return $result;
			}
		);
	}

	/**
	 * Deletes a kind, the terms and topics that have it going to another kind when one is chosen. Action `admin_post_otherguise_index_kind_delete`.
	 *
	 * @return void
	 */
	public function delete() {
		$this->done( self::DELETE, static fn( $field ) => Kinds::delete( sanitize_key( $field( 'slug' ) ), sanitize_key( $field( 'reassign' ) ) ) );
	}

	/**
	 * The common part of the handlers: the capability, the nonce, the operation, then back to the screen with the outcome.
	 *
	 * @param string   $action    The action (the name of the nonce is the same for the three).
	 * @param callable $operation Receives a function that reads a posted field; returns `true` or the reason it failed.
	 * @return void
	 */
	private function done( $action, $operation ) {
		if ( ! (bool) ( $this->can )( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'otherguise' ), '', array( 'response' => 403 ) );

			return;
		}

		$nonce = (string) ( $this->input )( INPUT_POST, '_wpnonce' );

		if ( '' === $nonce || ! (bool) ( $this->verify )( $nonce, self::NONCE ) ) {
			wp_die( esc_html__( 'This form has expired: reload the page.', 'otherguise' ), '', array( 'response' => 403 ) );

			return;
		}

		$result = $operation( fn( $name ) => (string) ( $this->input )( INPUT_POST, $name ) );

		( $this->redirect )(
			add_query_arg(
				array(
					'page'   => self::PAGE,
					'done'   => true === $result ? $action : '',
					'failed' => true === $result ? '' : rawurlencode( (string) $result ),
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

		$failed = (string) ( $this->input )( INPUT_GET, 'failed' );
		$done   = (string) ( $this->input )( INPUT_GET, 'done' );
		$tree   = Kinds::tree();

		echo '<div class="wrap"><h1>' . esc_html__( 'Index kinds', 'otherguise' ) . '</h1>';

		if ( '' !== $failed ) {
			echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( $failed ) ) . '</p></div>';
		} elseif ( '' !== $done ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'otherguise' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'A kind placed under another is a sort of it: a waterway is a place. The built-in kinds cannot be deleted or moved; their name and class can change.', 'otherguise' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Slug', 'otherguise' ) . '</th><th>' . esc_html__( 'Label', 'otherguise' ) . '</th><th>' . esc_html__( 'Under', 'otherguise' ) . '</th><th>' . esc_html__( 'CSS class', 'otherguise' ) . '</th><th>' . esc_html__( 'Used by', 'otherguise' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $tree as $row ) {
			$this->print_row( $row );
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Add a kind', 'otherguise' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADD ) . '" />';
		echo '<p><label>' . esc_html__( 'Slug', 'otherguise' ) . ' <input name="slug" pattern="[a-z][a-z0-9-]{0,30}" required /></label> <label>' . esc_html__( 'Label', 'otherguise' ) . ' <input name="label" required /></label> <label>' . esc_html__( 'Under', 'otherguise' ) . ' <select name="parent">';
		$this->print_parents( '', '' );
		echo '</select></label> <label>' . esc_html__( 'CSS class', 'otherguise' ) . ' <input name="css" /></label> <button class="button button-primary">' . esc_html__( 'Add', 'otherguise' ) . '</button></p></form></div>';
	}

	/**
	 * Prints one row of the table, with its forms.
	 *
	 * @param array<string, mixed> $row The kind.
	 * @return void
	 */
	private function print_row( array $row ) {
		$slug  = (string) $row['slug'];
		$usage = Kinds::usage( $slug );
		$free  = 'data' === $row['source'];
		$id    = 'kind-' . $slug;

		echo '<tr><td style="padding-left:' . esc_attr( (string) ( 10 + 16 * (int) $row['depth'] ) ) . 'px"><code>' . esc_html( $slug ) . '</code>';

		if ( $row['builtin'] ) {
			echo ' <em>' . esc_html__( 'built-in', 'otherguise' ) . '</em>';
		} elseif ( ! $free ) {
			echo ' <em>' . esc_html__( 'from code', 'otherguise' ) . '</em>';
		}

		echo '</td>';

		if ( ! $free ) {
			echo '<td>' . esc_html( (string) $row['label'] ) . '</td><td></td><td><code>index-kind-' . esc_html( (string) $row['css'] ) . '</code></td><td>' . esc_html( $usage['terms'] . ' / ' . $usage['topics'] ) . '</td><td></td></tr>';

			return;
		}

		echo '<td><form id="' . esc_attr( $id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="slug" value="' . esc_attr( $slug ) . '" />';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '" /><input form="' . esc_attr( $id ) . '" name="label" value="' . esc_attr( (string) $row['label'] ) . '" required /></form></td>';
		echo '<td>';

		if ( $row['builtin'] ) {
			echo '<input type="hidden" form="' . esc_attr( $id ) . '" name="parent" value="' . esc_attr( (string) $row['parent'] ) . '" />' . esc_html( '' === $row['parent'] ? '—' : (string) Kinds::label( (string) $row['parent'] ) );
		} else {
			echo '<select form="' . esc_attr( $id ) . '" name="parent">';
			$this->print_parents( (string) $row['parent'], $slug );
			echo '</select>';
		}

		echo '</td><td>index-kind-<input form="' . esc_attr( $id ) . '" name="css" value="' . esc_attr( (string) $row['css'] ) . '" size="12" /></td>';
		echo '<td>' . esc_html( sprintf( /* translators: 1: number of terms, 2: number of topics. */ __( '%1$d term(s), %2$d topics', 'otherguise' ), $usage['terms'], $usage['topics'] ) ) . '</td><td>';
		echo '<button form="' . esc_attr( $id ) . '" class="button">' . esc_html__( 'Save', 'otherguise' ) . '</button>';

		if ( ! $row['builtin'] ) {
			echo ' <form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="slug" value="' . esc_attr( $slug ) . '" />';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::DELETE ) . '" />';

			if ( $usage['terms'] + $usage['topics'] > 0 ) {
				echo '<select name="reassign"><option value="">' . esc_html__( '— give them to —', 'otherguise' ) . '</option>';
				$this->print_parents( '', $slug );
				echo '</select> ';
			}

			echo '<button class="button">' . esc_html__( 'Delete', 'otherguise' ) . '</button></form>';
		}

		echo '</td></tr>';
	}

	/**
	 * Prints the options of a selector of kinds, as a tree; the kind itself and its sub-kinds are left out.
	 *
	 * @param string $selected Slug selected.
	 * @param string $except   The kind to leave out with its sub-kinds.
	 * @return void
	 */
	private function print_parents( $selected, $except ) {
		echo '<option value="">' . esc_html__( '— none —', 'otherguise' ) . '</option>';

		foreach ( Kinds::tree() as $row ) {
			if ( 'data' !== $row['source'] || ( '' !== $except && Kinds::is_a( (string) $row['slug'], $except ) ) ) {
				continue;
			}

			echo '<option value="' . esc_attr( (string) $row['slug'] ) . '"' . selected( $row['slug'], $selected, false ) . '>' . esc_html( str_repeat( '— ', (int) $row['depth'] ) . $row['label'] ) . '</option>';
		}
	}
}
