<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the screen of the assignments prints besides its table.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Admin;

use Otherguise\Index\Designations;

defined( 'ABSPATH' ) || exit;

/**
 * The notices and the confirmation of an assignment (slices 630 and 631): a trait of `AssignmentsScreen` (it uses its stores and its helpers), kept apart so
 * that the class stays under the size limit of a file.
 */
trait AssignmentsViews {
	/**
	 * Prints the hidden fields that carry what the page was showing (the view, the search, the sort, the page).
	 *
	 * @return void
	 */
	private function print_preserved_fields() {
		foreach ( $this->preserved( INPUT_GET ) as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
	}

	/**
	 * The notices that say what was done.
	 *
	 * @return void
	 */
	private function notices() {
		$error   = sanitize_text_field( (string) ( $this->input )( INPUT_GET, 'error', false ) );
		$did     = sanitize_key( (string) ( $this->input )( INPUT_GET, 'did', false ) );
		$count   = absint( (string) ( $this->input )( INPUT_GET, 'count', false ) );
		$skipped = absint( (string) ( $this->input )( INPUT_GET, 'skipped', false ) );
		$words   = array(
			/* translators: %d: number of designations. */
			'assign'   => __( '%d designation(s) assigned; the mentions of their posts follow.', 'otherguise' ),
			/* translators: %d: number of designations. */
			'choose'   => __( '%d choice(s) applied: the topics are made where they were missing, and the mentions of the posts follow.', 'otherguise' ),
			/* translators: %d: number of designations. */
			'confirm'  => __( '%d assignment(s) confirmed: a new reading will not change them.', 'otherguise' ),
			/* translators: %d: number of designations. */
			'unassign' => __( '%d assignment(s) taken away: the designations are to resolve, and a new reading may propose a topic again.', 'otherguise' ),
			/* translators: %d: number of designations. */
			'delete'   => __( '%d designation(s) deleted.', 'otherguise' ),
		);

		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		} elseif ( isset( $words[ $did ] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( $words[ $did ], $count ) . ( $skipped > 0 ? ' ' . sprintf( /* translators: %d: number skipped. */ __( '%d left as they were (not concerned).', 'otherguise' ), $skipped ) : '' ) ) . '</p></div>';
		}
	}

	/**
	 * The preview of an assignment, then the button that does it.
	 *
	 * @return void
	 */
	private function confirmation() {
		$ids    = $this->numbers( INPUT_GET, 'ids' );
		$target = $this->topics->get( absint( (string) ( $this->input )( INPUT_GET, 'to', false ) ) );
		$new    = $this->text( INPUT_GET, 'new_label' );
		$posts  = array();
		$count  = 0;

		foreach ( $ids as $id ) {
			$where  = $this->observations->posts_of( $id );
			$count += count( $where );
			$posts  = array_merge( $posts, $where );
		}

		if ( ( null === $target && '' === $new ) || array() === $ids ) {
			echo '<p>' . esc_html__( 'Nothing to assign.', 'otherguise' ) . '</p><p><a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Back', 'otherguise' ) . '</a></p>';

			return;
		}

		echo '<h2>' . esc_html__( 'Assign', 'otherguise' ) . '</h2>';
		/* translators: 1: designations, 2: observations, 3: posts, 4: topic label. */
		echo '<p>' . esc_html( sprintf( __( '%1$d designation(s), %2$d observation(s) in %3$d post(s), will be assigned to "%4$s"%5$s. Nothing is deleted: the designations and the observations stay, and the mentions of the posts follow.', 'otherguise' ), count( $ids ), $count, count( array_unique( $posts ) ), null === $target ? $new : (string) $target['label'], null === $target ? ' ' . __( '(a new topic)', 'otherguise' ) : '' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::APPLY ) . '" /><input type="hidden" name="to" value="' . esc_attr( null === $target ? '' : (string) $target['id'] ) . '" />';

		foreach ( array( 'new_label', 'new_kind', 'new_parent' ) as $name ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $this->text( INPUT_GET, $name ) ) . '" />';
		}

		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="designation[]" value="' . esc_attr( (string) $id ) . '" />';
		}

		echo '<p><label><input type="checkbox" name="force" value="1" /> ' . esc_html__( 'Assign them anyway, although their kinds are not one a sort of the other', 'otherguise' ) . '</label></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Assign', 'otherguise' ) . '</button> <a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Cancel', 'otherguise' ) . '</a></p></form>';
	}
}
