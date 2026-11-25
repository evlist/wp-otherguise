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

use Otherguise\Index\Designation\TopicLabel;
use Otherguise\Index\Designations;
use Otherguise\Index\Kinds;

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
	 * The words of the quick edit (the script has none of its own).
	 *
	 * @return array<string, string>
	 */
	private function quick_edit_words() {
		return array(
			'change'    => __( 'Change topic', 'otherguise' ),
			'close'     => __( 'Close topics', 'otherguise' ),
			'choose'    => __( '— a close topic —', 'otherguise' ),
			'search'    => __( 'or look for another topic (type a name)', 'otherguise' ),
			'update'    => __( 'Update', 'otherguise' ),
			'cancel'    => __( 'Cancel', 'otherguise' ),
			'anyway'    => __( 'Assign anyway', 'otherguise' ),
			'nothing'   => __( 'Choose a topic.', 'otherguise' ),
			'failed'    => __( 'The topic could not be changed.', 'otherguise' ),
			'working'   => __( 'Working…', 'otherguise' ),
			'exclude'   => __( '_None_ (not in the index)', 'otherguise' ),
			'excluded'  => __( '_None_ · not in the index', 'otherguise' ),
		);
	}

	/**
	 * The first motif of a list whose topic has a kind that excludes the kind of the topic that it is going to, or null (slice 633: the warning is said
	 * only when it is true).
	 *
	 * @param int[]  $ids  The motifs.
	 * @param string $kind The kind of the topic that they go to.
	 * @return array{motif:int,was:string,target:string}|null
	 */
	private function kind_conflict( array $ids, $kind ) {
		foreach ( $ids as $id ) {
			$have = $this->observations->assignment( (int) $id );
			$was  = null === $have ? null : $this->topics->get( (int) $have['topic'] );

			if ( null !== $was && ! Kinds::compatible( (string) $was['kind'], (string) $kind ) ) {
				return array(
					'designation' => (int) $id,
					'was'         => (string) $was['kind'],
					'target'      => (string) $kind,
				);
			}
		}

		return null;
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
			/* translators: %d: number of motifs. */
			'assign'   => __( '%d motif(s) assigned; the mentions of their posts follow.', 'otherguise' ),
			/* translators: %d: number of motifs. */
			'choose'   => __( '%d choice(s) applied: the topics are made where they were missing, and the mentions of the posts follow.', 'otherguise' ),
			/* translators: %d: number of motifs. */
			'confirm'  => __( '%d assignment(s) confirmed: a new reading will not change them.', 'otherguise' ),
			/* translators: %d: number of motifs. */
			'unassign' => __( '%d assignment(s) taken away: the motifs are to resolve, and a new reading may propose a topic again.', 'otherguise' ),
			/* translators: %d: number of topics. */
			'approve'  => __( '%d topic(s) approved: they enter the index.', 'otherguise' ),
			/* translators: %d: number of motifs. */
			'exclude'  => __( '%d motif(s) excluded from the index: they are assigned to _None_, the mentions of their posts are gone, and a reading leaves them alone.', 'otherguise' ),
			/* translators: %d: number of motifs. */
			'include'  => __( '%d motif(s) included again: the next reading proposes a topic.', 'otherguise' ),
			/* translators: %d: number of motifs. */
			'delete'   => __( '%d motif(s) deleted.', 'otherguise' ),
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
			echo '<p>' . esc_html__( 'Nothing to assign.', 'otherguise' ) . '</p><p><a href="' . esc_url( $this->url( $this->preserved( INPUT_GET ) ) ) . '">' . esc_html__( 'Back', 'otherguise' ) . '</a></p>';

			return;
		}

		echo '<h2>' . esc_html__( 'Assign', 'otherguise' ) . '</h2>';
		/* translators: 1: motifs, 2: observations, 3: posts, 4: topic label. */
		echo '<p>' . esc_html( sprintf( __( '%1$d motif(s), %2$d observation(s) in %3$d post(s), will be assigned to "%4$s"%5$s. Nothing is deleted: the motifs and the observations stay, and the mentions of the posts follow.', 'otherguise' ), count( $ids ), $count, count( array_unique( $posts ) ), null === $target ? $new : ( new TopicLabel( $this->topics ) )->of( $target ), null === $target ? ' ' . __( '(a new topic)', 'otherguise' ) : '' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::APPLY ) . '" /><input type="hidden" name="to" value="' . esc_attr( null === $target ? '' : (string) $target['id'] ) . '" />';

		foreach ( array( 'new_label', 'new_kind', 'new_parent' ) as $name ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $this->text( INPUT_GET, $name ) ) . '" />';
		}

		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="designation[]" value="' . esc_attr( (string) $id ) . '" />';
		}

		$conflict = $this->kind_conflict( $ids, null === $target ? ( Kinds::is_kind( $this->text( INPUT_GET, 'new_kind' ) ) ? $this->text( INPUT_GET, 'new_kind' ) : Kinds::ANY ) : (string) $target['kind'] );

		// The warning is there only when it is true: a motif that is assigned to a topic whose kind cannot be the one of the new topic.
		if ( null !== $conflict ) {
			$old = $this->designations->get( $conflict['designation'] );

			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: a motif, 2: the kind of its topic, 3: the kind of the topic chosen. */
					__( '"%1$s" is assigned to a topic of the kind %2$s, and the topic chosen is of the kind %3$s: one is not a sort of the other. Tick the box to assign anyway.', 'otherguise' ),
					null === $old ? (string) $conflict['designation'] : (string) ( $old['evidence']['label'] ?? $old['key'] ),
					(string) Kinds::label( $conflict['was'] ),
					(string) Kinds::label( $conflict['target'] )
				)
			) . '</p></div>';
			echo '<p><label><input type="checkbox" name="force" value="1" /> ' . esc_html__( 'Assign them anyway', 'otherguise' ) . '</label></p>';
		}

		$this->print_preserved_fields();
		echo '<p><button class="button button-primary">' . esc_html__( 'Assign', 'otherguise' ) . '</button> <a href="' . esc_url( $this->url( $this->preserved( INPUT_GET ) ) ) . '">' . esc_html__( 'Cancel', 'otherguise' ) . '</a></p></form>';
	}
}
