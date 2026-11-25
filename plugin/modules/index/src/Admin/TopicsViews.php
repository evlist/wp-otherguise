<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The views of the screen of the topics.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Admin;

use Otherguise\Index\Designations;
use Otherguise\Index\Folding;
use Otherguise\Index\Kinds;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * What `TopicsScreen` prints (slice 628): the list of the topics, a topic, a motif, the motifs to resolve and the preview of an assignment. A
 * trait of the screen (it uses its stores and its helpers), kept apart so that the class stays under the size limit of a file.
 */
trait TopicsViews {
	/**
	 * What says where a topic is: its broader topics, from the highest.
	 *
	 * @param array $topic A topic.
	 * @return string
	 */
	private function chain( array $topic ) {
		$names = array();
		$up    = (int) $topic['parent'];

		for ( $level = 0; $up > 0 && $level < 8; $level++ ) {
			$above = $this->topics->get( $up );

			if ( null === $above ) {
				break;
			}

			array_unshift( $names, (string) $above['label'] );
			$up = (int) $above['parent'];
		}

		return implode( ' > ', $names );
	}

	/**
	 * The list of the topics: a search, filters, counts, 50 lines a page.
	 *
	 * @return void
	 */
	private function list_view() {
		$search = $this->text( INPUT_GET, 's' );
		$kind   = sanitize_key( (string) ( $this->input )( INPUT_GET, 'kind', false ) );
		$show   = sanitize_key( (string) ( $this->input )( INPUT_GET, 'show', false ) );
		$paged  = max( 1, absint( (string) ( $this->input )( INPUT_GET, 'paged', false ) ) );
		$hand   = $this->observations->manual_topics();
		$rows   = array();

		foreach ( $this->topics->all() as $id => $topic ) {
			$count = count( $this->observations->designations_of_topic( (int) $id ) );

			if ( ( '' !== $search && false === strpos( Folding::key( (string) $topic['label'] ), Folding::key( $search ) ) )
				|| ( '' !== $kind && ! Kinds::is_a( (string) $topic['kind'], $kind ) )
				|| ( 'empty' === $show && $count > 0 )
				|| ( 'candidates' === $show && Topics::CANDIDATE !== (string) $topic['status'] )
				|| ( 'hand' === $show && empty( $topic['kind_by_hand'] ) && empty( $topic['label_by_hand'] ) && ! isset( $hand[ (int) $id ] ) ) ) {
				continue;
			}

			$rows[] = array(
				'topic' => $topic,
				'count' => $count,
			);
		}

		usort( $rows, static fn( $a, $b ) => strcmp( Folding::key( (string) $a['topic']['label'] ), Folding::key( (string) $b['topic']['label'] ) ) );

		$pages = max( 1, (int) ceil( count( $rows ) / self::PER_PAGE ) );
		$paged = min( $paged, $pages );

		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><p>';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search a label', 'otherguise' ) . '" /> ';
		echo '<select name="kind"><option value="">' . esc_html__( 'Any kind', 'otherguise' ) . '</option>';

		// The root kind is not a filter: every kind is a sort of it, so it would show everything, which is the first entry.
		foreach ( Kinds::tree() as $row ) {
			if ( Kinds::ANY === $row['slug'] ) {
				continue;
			}

			echo '<option value="' . esc_attr( (string) $row['slug'] ) . '"' . selected( $kind, $row['slug'], false ) . '>' . esc_html( str_repeat( '— ', (int) $row['depth'] ) . $row['label'] ) . '</option>';
		}

		echo '</select> <select name="show">';

		foreach ( array(
			''      => __( 'All the topics', 'otherguise' ),
			'empty' => __( 'With no motif', 'otherguise' ),
			'hand'  => __( 'Chosen by hand', 'otherguise' ),
			'candidates' => __( 'Candidates (not in the index yet)', 'otherguise' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $show, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select> <button class="button">' . esc_html__( 'Filter', 'otherguise' ) . '</button></p></form>';
		/* translators: %d: number of topics. */
		echo '<p>' . esc_html( sprintf( _n( '%d topic.', '%d topics.', count( $rows ), 'otherguise' ), count( $rows ) ) ) . '</p>';

		// The topics made from what nobody classified are candidates, outside the index: approve the ones that this list shows (slice 635).
		if ( 'candidates' === $show && count( $rows ) > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::APPROVE ) . '" /><input type="hidden" name="search" value="' . esc_attr( $search ) . '" /><input type="hidden" name="kind" value="' . esc_attr( $kind ) . '" />';
			/* translators: %d: number of topics. */
			echo '<p><button class="button button-primary">' . esc_html( sprintf( __( 'Approve the %d topics of this list: they enter the index', 'otherguise' ), count( $rows ) ) ) . '</button></p></form>';
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Label', 'otherguise' ) . '</th><th>' . esc_html__( 'Kind', 'otherguise' ) . '</th><th>' . esc_html__( 'Under', 'otherguise' ) . '</th><th>' . esc_html__( 'Status', 'otherguise' ) . '</th><th>' . esc_html__( 'Motifs', 'otherguise' ) . '</th></tr></thead><tbody>';

		foreach ( array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $row ) {
			$topic = $row['topic'];

			echo '<tr><td><a href="' . esc_url( $this->url( array( 'topic' => (int) $topic['id'] ) ) ) . '">' . esc_html( (string) $topic['label'] ) . '</a></td><td>' . esc_html( (string) Kinds::label( (string) $topic['kind'] ) ) . '</td><td>' . esc_html( $this->chain( $topic ) ) . '</td><td>' . esc_html( (string) $topic['status'] ) . '</td><td>' . esc_html( (string) $row['count'] ) . '</td></tr>';
		}

		echo '</tbody></table>';

		for ( $page = 1; $page <= $pages && $pages > 1; $page++ ) {
			if ( $page === $paged ) {
				echo '<strong>' . esc_html( (string) $page ) . '</strong> ';

				continue;
			}

			$args = array_filter(
				array(
					's'     => $search,
					'kind'  => $kind,
					'show'  => $show,
					'paged' => $page,
				)
			);

			echo '<a href="' . esc_url( $this->url( $args ) ) . '">' . esc_html( (string) $page ) . '</a> ';
		}
	}

	/**
	 * A topic: what a person edits, its identifiers, its motifs and its sub-topics.
	 *
	 * @param int $id The topic.
	 * @return void
	 */
	private function topic_view( $id ) {
		$topic = $this->topics->get( $id );

		if ( null === $topic ) {
			echo '<p>' . esc_html__( 'This topic does not exist.', 'otherguise' ) . '</p>';

			return;
		}

		echo '<p><a href="' . esc_url( $this->url() ) . '">&larr; ' . esc_html__( 'All the topics', 'otherguise' ) . '</a></p>';
		echo '<h2>' . esc_html( (string) $topic['label'] ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '" /><input type="hidden" name="topic" value="' . esc_attr( (string) $id ) . '" />';
		echo '<p><label>' . esc_html__( 'Label', 'otherguise' ) . ' <input name="label" required size="40" value="' . esc_attr( (string) $topic['label'] ) . '" /></label>' . ( ! empty( $topic['label_by_hand'] ) ? ' <em>' . esc_html__( 'chosen by hand', 'otherguise' ) . '</em>' : '' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Kind', 'otherguise' ) . ' <select name="kind">';

		foreach ( Kinds::tree() as $row ) {
			echo '<option value="' . esc_attr( (string) $row['slug'] ) . '"' . selected( (string) $topic['kind'], $row['slug'], false ) . '>' . esc_html( str_repeat( '— ', (int) $row['depth'] ) . $row['label'] ) . '</option>';
		}

		echo '</select></label>' . ( ! empty( $topic['kind_by_hand'] ) ? ' <em>' . esc_html__( 'chosen by hand', 'otherguise' ) . '</em>' : '' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Under the topic number (0 for none)', 'otherguise' ) . ' <input name="parent" type="number" min="0" value="' . esc_attr( (string) (int) $topic['parent'] ) . '" /></label> ' . esc_html( $this->chain( $topic ) ) . '</p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save', 'otherguise' ) . '</button></p></form>';

		$keys = array_values( array_filter( array_map( 'strval', (array) $topic['keys'] ), static fn( $key ) => 0 !== strpos( $key, 'label:' ) ) );

		echo '<p><strong>' . esc_html__( 'Identifiers', 'otherguise' ) . '</strong> ' . esc_html( array() === $keys ? '—' : implode( ', ', $keys ) ) . '</p>';
		echo '<h3>' . esc_html__( 'Motifs', 'otherguise' ) . '</h3>';
		$this->designations_table( array_keys( $this->observations->designations_of_topic( $id ) ), $id, 'topics' );

		$children = $this->topics->all( array( 'parent' => $id ) );

		echo '<h3>' . esc_html__( 'Sub-topics', 'otherguise' ) . '</h3>';

		if ( array() === $children ) {
			echo '<p>' . esc_html__( 'None.', 'otherguise' ) . '</p>';

			return;
		}

		echo '<ul>';

		foreach ( $children as $child ) {
			echo '<li><a href="' . esc_url( $this->url( array( 'topic' => (int) $child['id'] ) ) ) . '">' . esc_html( (string) $child['label'] ) . '</a> (' . esc_html( (string) Kinds::label( (string) $child['kind'] ) ) . ')</li>';
		}

		echo '</ul>';
	}

	/**
	 * A table of motifs with the form that assigns the ticked ones (or all of a topic) to a topic.
	 *
	 * @param int[]  $ids   The motifs.
	 * @param int    $topic The topic they are shown under (0 for none).
	 * @param string $tab   The tab to come back to.
	 * @return void
	 */
	private function designations_table( array $ids, $topic, $tab ) {
		if ( array() === $ids ) {
			echo '<p>' . esc_html__( 'None.', 'otherguise' ) . '</p>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ASSIGN ) . '" /><input type="hidden" name="op" value="preview" /><input type="hidden" name="topic" value="' . esc_attr( (string) $topic ) . '" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		echo '<table class="widefat striped"><thead><tr><th></th><th>' . esc_html__( 'Source', 'otherguise' ) . '</th><th>' . esc_html__( 'Motif', 'otherguise' ) . '</th><th>' . esc_html__( 'Posts', 'otherguise' ) . '</th><th>' . esc_html__( 'Assigned by', 'otherguise' ) . '</th><th>' . esc_html__( 'State', 'otherguise' ) . '</th></tr></thead><tbody>';

		foreach ( array_slice( $ids, 0, 200 ) as $id ) {
			$one = $this->designations->get( (int) $id );

			if ( null === $one ) {
				continue;
			}

			$have  = $this->observations->assignment( (int) $id );
			$posts = count( $this->observations->posts_of( (int) $id ) );

			echo '<tr><td><input type="checkbox" name="designation[]" value="' . esc_attr( (string) $id ) . '" /></td><td>' . esc_html( (string) $one['source'] ) . '</td><td><a href="' . esc_url(
				$this->url(
					array(
						'designation' => (int) $id,
						'topic' => $topic,
					)
				)
			) . '">' . esc_html( (string) ( $one['evidence']['label'] ?? $one['key'] ) ) . '</a> <code>' . esc_html( (string) $one['key'] ) . '</code></td><td>' . esc_html( (string) $posts ) . ( 0 === $posts ? ' ' . esc_html__( '(no observation now)', 'otherguise' ) : '' ) . '</td><td>' . esc_html( null === $have ? '—' : (string) $have['by'] ) . '</td><td>' . esc_html( (string) $one['state'] ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( count( $ids ) > 200 ) {
			/* translators: %d: number of motifs. */
			echo '<p>' . esc_html( sprintf( __( 'The first 200 of %d motifs are shown.', 'otherguise' ), count( $ids ) ) ) . '</p>';
		}

		echo '<p><label>' . esc_html__( 'Assign the ticked ones to the topic (its number, or its label)', 'otherguise' ) . ' <input name="to" size="30" /></label> ' . esc_html__( 'or to a new topic:', 'otherguise' ) . ' <input name="new_label" placeholder="' . esc_attr__( 'Label', 'otherguise' ) . '" /> <select name="new_kind">';

		foreach ( Kinds::tree() as $row ) {
			echo '<option value="' . esc_attr( (string) $row['slug'] ) . '"' . selected( Kinds::ANY, $row['slug'], false ) . '>' . esc_html( str_repeat( '— ', (int) $row['depth'] ) . $row['label'] ) . '</option>';
		}

		echo '</select> <input name="new_parent" type="number" min="0" placeholder="' . esc_attr__( 'Under the topic number', 'otherguise' ) . '" /></p>';
		echo '<p><button class="button">' . esc_html__( 'Preview the assignment of the ticked ones', 'otherguise' ) . '</button>';

		if ( $topic > 0 ) {
			echo ' <button class="button" name="all" value="1">' . esc_html__( 'Preview the assignment of all the motifs of this topic', 'otherguise' ) . '</button>';
		}

		echo '</p></form>';
	}

	/**
	 * A motif: the posts where it is found.
	 *
	 * @param int $id The motif.
	 * @return void
	 */
	private function designation_view( $id ) {
		$one = $this->designations->get( $id );

		if ( null === $one ) {
			echo '<p>' . esc_html__( 'This motif does not exist.', 'otherguise' ) . '</p>';

			return;
		}

		$topic = absint( (string) ( $this->input )( INPUT_GET, 'topic', false ) );
		$have  = $this->observations->assignment( $id );
		$goes  = null === $have ? null : $this->topics->get( $have['topic'] );
		$posts = $this->observations->posts_of( $id );
		$paged = max( 1, absint( (string) ( $this->input )( INPUT_GET, 'paged', false ) ) );

		sort( $posts );

		echo '<p><a href="' . esc_url( $this->url( $topic > 0 ? array( 'topic' => $topic ) : array() ) ) . '">&larr; ' . esc_html__( 'Back', 'otherguise' ) . '</a></p>';
		echo '<h2>' . esc_html( (string) ( $one['evidence']['label'] ?? $one['key'] ) ) . '</h2><p><code>' . esc_html( (string) $one['key'] ) . '</code> — ' . esc_html( (string) $one['source'] ) . ' — ' . esc_html( (string) $one['state'] ) . '</p>';
		echo '<p>' . esc_html__( 'Assigned to', 'otherguise' ) . ' ' . ( null === $goes ? '—' : '<a href="' . esc_url( $this->url( array( 'topic' => (int) $goes['id'] ) ) ) . '">' . esc_html( (string) $goes['label'] ) . '</a> (' . esc_html( (string) $have['by'] ) . ')' ) . '</p>';
		/* translators: %d: number of posts. */
		echo '<h3>' . esc_html( sprintf( _n( 'Found in %d post', 'Found in %d posts', count( $posts ), 'otherguise' ), count( $posts ) ) ) . '</h3><ul>';

		foreach ( array_slice( $posts, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $post ) {
			$title = (string) get_the_title( (int) $post );

			echo '<li><a href="' . esc_url( (string) get_edit_post_link( (int) $post, 'raw' ) ) . '">' . esc_html( '' === $title ? (string) $post : $title ) . '</a></li>';
		}

		echo '</ul>';

		$pages = (int) ceil( count( $posts ) / self::PER_PAGE );

		for ( $page = 2; $page <= $pages; $page++ ) {
			echo '<a href="' . esc_url(
				$this->url(
					array(
						'designation' => $id,
						'topic' => $topic,
						'paged' => $page,
					)
				)
			) . '">' . esc_html( (string) $page ) . '</a> ';
		}
	}

	/**
	 * The motifs that a person has to assign: pending and ambiguous.
	 *
	 * @return void
	 */
	private function resolve_view() {
		$ids = array();

		foreach ( array( Designations::AMBIGUOUS, Designations::PENDING ) as $state ) {
			$ids = array_merge( $ids, array_keys( $this->designations->all( array( 'state' => $state ) ) ) );
		}

		echo '<p>' . esc_html__( 'A motif is pending while Wikidata has not answered (it resolves by itself), and ambiguous when several topics fit: assign it to the right one.', 'otherguise' ) . '</p>';
		$this->designations_table( $ids, 0, 'resolve' );
	}

	/**
	 * The preview of an assignment, then the button that does it.
	 *
	 * @return void
	 */
	private function preview_view() {
		$ids    = $this->numbers( INPUT_GET, 'ids' );
		$to     = absint( (string) ( $this->input )( INPUT_GET, 'to', false ) );
		$from   = absint( (string) ( $this->input )( INPUT_GET, 'topic', false ) );
		$tab    = $this->tab( INPUT_GET );
		$target = $this->topics->get( $to );
		$posts  = array();
		$count  = 0;

		foreach ( $ids as $id ) {
			$where = $this->observations->posts_of( $id );
			$count += count( $where );
			$posts  = array_merge( $posts, $where );
		}

		$label = null === $target ? (string) $this->text( INPUT_GET, 'new_label' ) : (string) $target['label'];

		echo '<h2>' . esc_html__( 'Assign', 'otherguise' ) . '</h2>';
		/* translators: 1: motifs, 2: observations, 3: posts, 4: topic label. */
		echo '<p>' . esc_html( sprintf( __( '%1$d motif(s), %2$d observation(s) in %3$d post(s), will be assigned to "%4$s"%5$s. Nothing is deleted: the motifs and the observations stay, and the mentions of the posts follow.', 'otherguise' ), count( $ids ), $count, count( array_unique( $posts ) ), $label, null === $target ? ' ' . __( '(a new topic)', 'otherguise' ) : '' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ASSIGN ) . '" /><input type="hidden" name="op" value="apply" /><input type="hidden" name="topic" value="' . esc_attr( (string) $from ) . '" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" /><input type="hidden" name="to" value="' . esc_attr( (string) $to ) . '" />';
		echo '<input type="hidden" name="all" value="' . esc_attr( '1' === (string) ( $this->input )( INPUT_GET, 'all', false ) ? '1' : '' ) . '" />';

		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="designation[]" value="' . esc_attr( (string) $id ) . '" />';
		}

		foreach ( array( 'new_label', 'new_kind', 'new_parent' ) as $name ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $this->text( INPUT_GET, $name ) ) . '" />';
		}

		echo '<p><label><input type="checkbox" name="force" value="1" /> ' . esc_html__( 'Assign them anyway, although their kinds are not one a sort of the other', 'otherguise' ) . '</label></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Assign', 'otherguise' ) . '</button> <a href="' . esc_url(
			$this->url(
				array_filter(
					array(
						'tab' => $tab,
						'topic' => $from,
					)
				)
			)
		) . '">' . esc_html__( 'Cancel', 'otherguise' ) . '</a></p></form>';
	}
}
