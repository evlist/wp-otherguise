<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The screen where the topics that may be the same are looked at and merged.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Admin;

use Otherguise\Core\AdminMenu;
use Otherguise\Index\Kinds;
use Otherguise\Index\Merge\DuplicateFinder;
use Otherguise\Index\Merge\IgnoredPairs;
use Otherguise\Index\Merge\TopicMerger;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Otherguise → Index duplicates (slice 618): the groups of topics that may be one, with for each topic its kind, its place in the hierarchy and the
 * number of posts that mention it. For a group a person chooses the topic that stays and the ones that go in it, **looks at a preview** (what would be moved:
 * the mentions, the children, the keys, the kind) and **confirms with one click**; or says that **they are not doubles** (the pairs are kept and not offered
 * again). A second view lists the **safe merges** (an unclassified topic with no parent against a single topic that has a place and a kind) to be checked
 * and done in one click. A merge cannot be undone. Saved through `admin-post.php`, with the capability and a nonce.
 */
final class DuplicatesScreen {
	public const PAGE     = 'otherguise-index-duplicates';
	public const ACTION   = 'otherguise_index_duplicates';
	public const NONCE    = 'otherguise_index_duplicates';
	public const PER_PAGE = 20;

	/**
	 * The finder.
	 *
	 * @var DuplicateFinder
	 */
	private $finder;

	/**
	 * The merger.
	 *
	 * @var TopicMerger
	 */
	private $merger;

	/**
	 * The pairs that are not doubles.
	 *
	 * @var IgnoredPairs
	 */
	private $ignored;

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * Counts the posts that mention a topic: `fn( int $id ): int`.
	 *
	 * @var callable
	 */
	private $count;

	/**
	 * Tells whether the current user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Reads a field of the request: `fn( int $type, string $name, bool $is_array ): string|array|null`.
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
	 * @param DuplicateFinder $finder   The finder.
	 * @param TopicMerger     $merger   The merger.
	 * @param IgnoredPairs    $ignored  The pairs that are not doubles.
	 * @param Topics          $topics   The topics.
	 * @param callable        $count    Counts the posts that mention a topic.
	 * @param callable|null   $can      Default `current_user_can`.
	 * @param callable|null   $input    Reads a field of the request; default `filter_input`.
	 * @param callable|null   $verify   Checks a nonce; default `wp_verify_nonce`.
	 * @param callable|null   $redirect Default `wp_safe_redirect` then `exit`.
	 */
	public function __construct( DuplicateFinder $finder, TopicMerger $merger, IgnoredPairs $ignored, Topics $topics, $count, $can = null, $input = null, $verify = null, $redirect = null ) {
		$this->finder   = $finder;
		$this->merger   = $merger;
		$this->ignored  = $ignored;
		$this->topics   = $topics;
		$this->count    = $count;
		$this->can      = $can ?? 'current_user_can';
		$this->input    = $input ?? static function ( $type, $name, $is_array ) {
			return filter_input( $type, $name, FILTER_DEFAULT, $is_array ? FILTER_REQUIRE_ARRAY : FILTER_FLAG_NONE );
		};
		$this->verify   = $verify ?? 'wp_verify_nonce';
		$this->redirect = $redirect ?? static function ( $url ) {
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
			'title'       => __( 'Index duplicates', 'otherguise' ),
			'description' => __( 'Topics that may be the same: look at them, merge them, or say that they are not doubles.', 'otherguise' ),
			'capability'  => $this->capability(),
			'page'        => self::PAGE,
			'group'       => 'settings',
			'order'       => 47,
		);

		return $screens;
	}

	/**
	 * Adds the screen to the menu. Action `admin_menu`.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			AdminMenu::SLUG,
			__( 'Index duplicates', 'otherguise' ),
			__( 'Index duplicates', 'otherguise' ),
			$this->capability(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Does what a form asked: merges, merges the safe ones, or keeps a group as not doubles. Action `admin_post_otherguise_index_duplicates`.
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

		$op   = sanitize_key( (string) ( $this->input )( INPUT_POST, 'op', false ) );
		$done = array();

		if ( 'ignore' === $op ) {
			$done['ignored'] = $this->ignored->add_group( array_map( 'absint', (array) ( $this->input )( INPUT_POST, 'ids', true ) ) );
		} elseif ( 'merge' === $op ) {
			$done = $this->merge_all( array( array( absint( (string) ( $this->input )( INPUT_POST, 'keep', false ) ), array_map( 'absint', (array) ( $this->input )( INPUT_POST, 'go', true ) ) ) ), '' !== (string) ( $this->input )( INPUT_POST, 'force', false ) );
		} elseif ( 'merge_safe' === $op ) {
			$done = $this->merge_all( $this->safe_choice( (array) ( $this->input )( INPUT_POST, 'safe', true ) ), false );
		}

		( $this->redirect )( add_query_arg( array_merge( array( 'page' => self::PAGE ), $done ), admin_url( 'admin.php' ) ) );
	}

	/**
	 * Merges what was asked and says what was done.
	 *
	 * @param array<int, array{0:int,1:int[]}> $merges A topic that stays and the topics that go in it, for each.
	 * @param bool                             $force  Merge kinds that are not one a sort of the other.
	 * @return array<string, int|string> `merged`, `moved`, `joined` and `error`.
	 */
	private function merge_all( array $merges, $force ) {
		$total = array(
			'merged' => 0,
			'moved'  => 0,
			'joined' => 0,
			'error'  => '',
		);

		foreach ( $merges as $merge ) {
			foreach ( $merge[1] as $go ) {
				$report = $this->merger->merge( (int) $merge[0], (int) $go, false, $force );

				if ( ! $report['ok'] ) {
					$total['error'] = '' === $total['error'] ? $go . ': ' . $report['error'] : $total['error'];

					continue;
				}

				++$total['merged'];
				$total['moved']  += $report['mentions_moved'];
				$total['joined'] += $report['mentions_joined'];
			}
		}

		return $total;
	}

	/**
	 * What the form of the safe merges asked, kept only where it is still what the finder calls safe.
	 *
	 * @param array<int, string> $posted The values `keep:go,go` of the checked lines.
	 * @return array<int, array{0:int,1:int[]}>
	 */
	private function safe_choice( array $posted ) {
		$safe = array();

		foreach ( $this->finder->groups() as $group ) {
			if ( null !== $group['safe'] ) {
				$safe[ $group['safe']['keep'] . ':' . implode( ',', $group['safe']['go'] ) ] = array( $group['safe']['keep'], $group['safe']['go'] );
			}
		}

		return array_values( array_intersect_key( $safe, array_flip( array_map( 'strval', $posted ) ) ) );
	}

	/**
	 * Prints the screen: the list of the groups, the preview of a merge, or the safe merges.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! (bool) ( $this->can )( $this->capability() ) ) {
			return;
		}

		$view = sanitize_key( (string) ( $this->input )( INPUT_GET, 'view', false ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'Index duplicates', 'otherguise' ) . '</h1>';
		$this->notices();

		if ( 'preview' === $view ) {
			$this->preview();
		} elseif ( 'safe' === $view ) {
			$this->safe_view();
		} else {
			$this->list_view();
		}

		echo '</div>';
	}

	/**
	 * The notices that say what was done.
	 *
	 * @return void
	 */
	private function notices() {
		$merged  = ( $this->input )( INPUT_GET, 'merged', false );
		$ignored = ( $this->input )( INPUT_GET, 'ignored', false );
		$error   = sanitize_text_field( (string) ( $this->input )( INPUT_GET, 'error', false ) );

		if ( null !== $merged && false !== $merged && '' !== (string) $merged ) {
			/* translators: 1: topics merged, 2: mentions moved, 3: mentions joined to one that was there. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%1$d topic(s) merged: %2$d mention(s) moved, %3$d joined to a mention that was already there.', 'otherguise' ), absint( $merged ), absint( (string) ( $this->input )( INPUT_GET, 'moved', false ) ), absint( (string) ( $this->input )( INPUT_GET, 'joined', false ) ) ) ) . '</p></div>';
		}

		if ( null !== $ignored && false !== $ignored && '' !== (string) $ignored ) {
			/* translators: %d: number of pairs. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( '%d pair kept as not doubles.', '%d pairs kept as not doubles.', absint( $ignored ), 'otherguise' ), absint( $ignored ) ) ) . '</p></div>';
		}

		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
	}

	/**
	 * The address of this screen.
	 *
	 * @param array<string, mixed> $args The arguments.
	 * @return string
	 */
	private function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

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
	 * The list of the groups, with a form for each.
	 *
	 * @return void
	 */
	private function list_view() {
		$search = sanitize_text_field( (string) ( $this->input )( INPUT_GET, 's', false ) );
		$page   = max( 1, absint( (string) ( $this->input )( INPUT_GET, 'paged', false ) ) );
		$groups = array_values(
			array_filter(
				$this->finder->groups(),
				static function ( $group ) use ( $search ) {
					if ( '' === $search ) {
						return true;
					}

					foreach ( $group['members'] as $member ) {
						if ( false !== stripos( (string) $member['label'], $search ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);
		$safe   = count( array_filter( $groups, static fn( $group ) => null !== $group['safe'] ) );
		$pages  = max( 1, (int) ceil( count( $groups ) / self::PER_PAGE ) );

		/* translators: 1: number of groups, 2: number of pairs that were kept as not doubles. */
		echo '<p>' . esc_html( sprintf( __( '%1$d group(s) of topics that may be the same; %2$d pair(s) kept as not doubles. A merge cannot be undone: save the database first.', 'otherguise' ), count( $groups ), $this->ignored->count() ) ) . '</p>';

		if ( $safe > 0 ) {
			/* translators: %d: number of safe merges. */
			echo '<p><a class="button button-primary" href="' . esc_url( $this->url( array( 'view' => 'safe' ) ) ) . '">' . esc_html( sprintf( __( 'Look at the %d safe merge(s)', 'otherguise' ), $safe ) ) . '</a> ' . esc_html__( 'An unclassified topic with no parent against a single topic that has a place and a kind.', 'otherguise' ) . '</p>';
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><p class="search-box"><input type="search" name="s" value="' . esc_attr( $search ) . '" /> <button class="button">' . esc_html__( 'Search', 'otherguise' ) . '</button></p></form>';

		foreach ( array_slice( $groups, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $group ) {
			$this->group( $group );
		}

		if ( $pages > 1 ) {
			echo '<p>' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg(
							array(
								'page' => self::PAGE,
								's'    => $search,
							),
							admin_url( 'admin.php' )
						) . '&paged=%#%',
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			) . '</p>';
		}
	}

	/**
	 * One group: the topics, the choice of the one that stays, and the buttons.
	 *
	 * @param array $group The group.
	 * @return void
	 */
	private function group( array $group ) {
		$ids  = array_map( static fn( $member ) => (int) $member['id'], $group['members'] );
		$keep = null !== $group['safe'] ? $group['safe']['keep'] : $ids[0];

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin:1em 0;"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="view" value="preview" />';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Stays', 'otherguise' ) . '</th><th>' . esc_html__( 'Goes in it', 'otherguise' ) . '</th><th>' . esc_html__( 'Topic', 'otherguise' ) . '</th><th>' . esc_html__( 'Kind', 'otherguise' ) . '</th><th>' . esc_html__( 'Under', 'otherguise' ) . '</th><th>' . esc_html__( 'Posts', 'otherguise' ) . '</th></tr></thead><tbody>';

		foreach ( $group['members'] as $member ) {
			$id = (int) $member['id'];

			echo '<tr><td><input type="radio" name="keep" value="' . esc_attr( (string) $id ) . '"' . checked( $keep, $id, false ) . ' /></td>';
			echo '<td><input type="checkbox" name="go[]" value="' . esc_attr( (string) $id ) . '"' . checked( null !== $group['safe'] && in_array( $id, $group['safe']['go'], true ), true, false ) . ' /></td>';
			echo '<td>' . esc_html( (string) $member['label'] ) . ' <small>#' . esc_html( (string) $id ) . '</small></td><td>' . esc_html( Kinds::label( (string) $member['kind'] ) ) . '</td><td>' . esc_html( $this->chain( $member ) ) . '</td><td>' . esc_html( (string) ( $this->count )( $id ) ) . '</td></tr>';
		}

		echo '</tbody></table><p><button class="button">' . esc_html__( 'Preview the merge', 'otherguise' ) . '</button></p></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:-0.5em 0 1.5em;">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="op" value="ignore" />';

		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="ids[]" value="' . esc_attr( (string) $id ) . '" />';
		}

		echo '<button class="button-link">' . esc_html__( 'These are not doubles', 'otherguise' ) . '</button></form>';
	}

	/**
	 * The preview of a merge: what would be moved, then the button that does it.
	 *
	 * @return void
	 */
	private function preview() {
		$keep = absint( (string) ( $this->input )( INPUT_GET, 'keep', false ) );
		$go   = array_values( array_diff( array_map( 'absint', (array) ( $this->input )( INPUT_GET, 'go', true ) ), array( $keep ) ) );

		if ( $keep <= 0 || array() === $go ) {
			echo '<p>' . esc_html__( 'Choose the topic that stays and at least one topic that goes in it.', 'otherguise' ) . '</p><p><a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Back to the list', 'otherguise' ) . '</a></p>';

			return;
		}

		$topic = $this->topics->get( $keep );

		echo '<h2>' . esc_html__( 'Preview', 'otherguise' ) . '</h2>';
		echo '<p>' . esc_html( sprintf( /* translators: %s: label of the topic that stays. */ __( 'The topic that stays: %s', 'otherguise' ), null === $topic ? (string) $keep : (string) $topic['label'] ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Goes in it', 'otherguise' ) . '</th><th>' . esc_html__( 'Mentions moved', 'otherguise' ) . '</th><th>' . esc_html__( 'Joined to one already there', 'otherguise' ) . '</th><th>' . esc_html__( 'Children moved', 'otherguise' ) . '</th><th>' . esc_html__( 'Keys', 'otherguise' ) . '</th><th>' . esc_html__( 'Kind then', 'otherguise' ) . '</th></tr></thead><tbody>';

		$kind_problem = false;
		$any_ok       = false;

		foreach ( $go as $id ) {
			$report = $this->merger->merge( $keep, $id, true, true );
			$other  = $this->topics->get( $id );
			$plain  = $this->merger->merge( $keep, $id, true, false );

			if ( ! $report['ok'] ) {
				echo '<tr><td colspan="6">' . esc_html( (string) $id . ': ' . $report['error'] ) . '</td></tr>';

				continue;
			}

			$any_ok        = true;
			$kind_problem  = $kind_problem || ! $plain['ok'];
			echo '<tr><td>' . esc_html( null === $other ? (string) $id : (string) $other['label'] ) . ( $plain['ok'] ? '' : ' <strong>' . esc_html__( '(kinds that cannot be one)', 'otherguise' ) . '</strong>' ) . '</td><td>' . esc_html( (string) $report['mentions_moved'] ) . '</td><td>' . esc_html( (string) $report['mentions_joined'] ) . '</td><td>' . esc_html( (string) ( $report['children_moved'] + $report['children_merged'] ) ) . '</td><td>' . esc_html( (string) $report['keys'] ) . '</td><td>' . esc_html( Kinds::label( (string) $report['kind'] ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( ! $any_ok ) {
			echo '<p><a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Back to the list', 'otherguise' ) . '</a></p>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="op" value="merge" /><input type="hidden" name="keep" value="' . esc_attr( (string) $keep ) . '" />';

		foreach ( $go as $id ) {
			echo '<input type="hidden" name="go[]" value="' . esc_attr( (string) $id ) . '" />';
		}

		if ( $kind_problem ) {
			echo '<p><label><input type="checkbox" name="force" value="1" /> ' . esc_html__( 'Merge them anyway, although their kinds are not one a sort of the other', 'otherguise' ) . '</label></p>';
		}

		echo '<p>' . esc_html__( 'A merge cannot be undone.', 'otherguise' ) . '</p><p><button class="button button-primary">' . esc_html__( 'Merge', 'otherguise' ) . '</button> <a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Cancel', 'otherguise' ) . '</a></p></form>';
	}

	/**
	 * The safe merges, to be checked and done in one click.
	 *
	 * @return void
	 */
	private function safe_view() {
		$page = max( 1, absint( (string) ( $this->input )( INPUT_GET, 'paged', false ) ) );
		$safe = array_values( array_filter( $this->finder->groups(), static fn( $group ) => null !== $group['safe'] ) );

		echo '<h2>' . esc_html__( 'Safe merges', 'otherguise' ) . '</h2><p>' . esc_html__( 'Each line puts an unclassified topic that has no parent into the single topic of the group that has a place and a kind. Uncheck what you do not want; a merge cannot be undone.', 'otherguise' ) . '</p>';

		if ( array() === $safe ) {
			echo '<p>' . esc_html__( 'None.', 'otherguise' ) . '</p><p><a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Back to the list', 'otherguise' ) . '</a></p>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="op" value="merge_safe" />';
		echo '<table class="widefat striped"><thead><tr><th></th><th>' . esc_html__( 'Stays', 'otherguise' ) . '</th><th>' . esc_html__( 'Under', 'otherguise' ) . '</th><th>' . esc_html__( 'Goes in it', 'otherguise' ) . '</th><th>' . esc_html__( 'Mentions moved', 'otherguise' ) . '</th></tr></thead><tbody>';

		foreach ( array_slice( $safe, ( $page - 1 ) * self::PER_PAGE * 2, self::PER_PAGE * 2 ) as $group ) {
			$keep   = $this->topics->get( $group['safe']['keep'] );
			$names  = array();
			$moving = 0;

			foreach ( $group['safe']['go'] as $go ) {
				$topic   = $this->topics->get( $go );
				$names[] = null === $topic ? (string) $go : (string) $topic['label'];
				$moving += (int) ( $this->count )( $go );
			}

			echo '<tr><td><input type="checkbox" name="safe[]" value="' . esc_attr( $group['safe']['keep'] . ':' . implode( ',', $group['safe']['go'] ) ) . '" checked="checked" /></td><td>' . esc_html( null === $keep ? '' : (string) $keep['label'] . ' (' . Kinds::label( (string) $keep['kind'] ) . ')' ) . '</td><td>' . esc_html( null === $keep ? '' : $this->chain( $keep ) ) . '</td><td>' . esc_html( implode( ', ', $names ) ) . '</td><td>' . esc_html( (string) $moving ) . '</td></tr>';
		}

		echo '</tbody></table><p><button class="button button-primary">' . esc_html__( 'Merge the checked lines', 'otherguise' ) . '</button> <a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Cancel', 'otherguise' ) . '</a></p></form>';

		$pages = max( 1, (int) ceil( count( $safe ) / ( self::PER_PAGE * 2 ) ) );

		if ( $pages > 1 ) {
			echo '<p>' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg(
							array(
								'page' => self::PAGE,
								'view' => 'safe',
							),
							admin_url( 'admin.php' )
						) . '&paged=%#%',
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			) . '</p>';
		}
	}
}
