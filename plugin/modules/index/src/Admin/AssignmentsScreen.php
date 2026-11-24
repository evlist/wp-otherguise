<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The screen of the assignments.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Admin;

use Otherguise\Core\AdminMenu;
use Otherguise\Index\Designation\Assigner;
use Otherguise\Index\Designation\TopicFinder;
use Otherguise\Index\Designations;
use Otherguise\Index\Mentions;
use Otherguise\Index\Observations;
use Otherguise\Index\Rest\TopicsRoute;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Otherguise → Assignments (slice 630): **the designations and the topic each is assigned to**, in a table of WordPress (views, search, sorting, pages of 50,
 * bulk actions). A person ticks designations and **assigns them to a topic that a field finds as one types** (a preview comes first), **confirms** what the
 * system proposed, **takes an assignment away** or **deletes** the designations that no post observes. The mentions follow every change.
 *
 * The bulk actions of a table of WordPress post to the screen itself (their field `action` would be taken for an action of `admin-post.php`): the
 * request is handled when the page loads (`load-<page>`), then redirected; only the confirmation of an assignment posts to `admin-post.php`.
 */
final class AssignmentsScreen {
	public const PAGE  = 'otherguise-index-assignments';
	public const APPLY = 'otherguise_index_assignments_apply';
	public const NONCE = 'otherguise_index_assignments';
	public const BULK  = 'bulk-designations';

	/**
	 * The designations.
	 *
	 * @var Designations
	 */
	private $designations;

	/**
	 * The observations and assignments.
	 *
	 * @var Observations
	 */
	private $observations;

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * The assigner.
	 *
	 * @var Assigner
	 */
	private $assigner;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * The finder of topics.
	 *
	 * @var TopicFinder
	 */
	private $finder;

	/**
	 * Makes the table: `fn(): object` with `prepare_items()`, `views()`, `search_box( $text, $id )` and `display()`.
	 *
	 * @var callable
	 */
	private $make_table;

	/**
	 * The address of a script of the screen: `fn( string $file ): string`.
	 *
	 * @var callable
	 */
	private $script_url;

	/**
	 * Tells whether the current user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Reads a field of the request: `fn( int $type, string $name, bool $is_array ): mixed`.
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
	 * The suffix of the page, once it is in the menu.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Builds the screen.
	 *
	 * @param Designations  $designations The designations.
	 * @param Observations  $observations The observations.
	 * @param Topics        $topics       The topics.
	 * @param Assigner      $assigner     The assigner.
	 * @param Mentions      $mentions     The mentions.
	 * @param callable      $make_table   Makes the table.
	 * @param callable      $script_url   The address of a script of the screen.
	 * @param callable|null $can         Default `current_user_can`.
	 * @param callable|null $input       Default `filter_input`.
	 * @param callable|null $verify      Default `wp_verify_nonce`.
	 * @param callable|null $redirect    Default `wp_safe_redirect` then `exit`.
	 */
	public function __construct( Designations $designations, Observations $observations, Topics $topics, Assigner $assigner, Mentions $mentions, $make_table, $script_url, $can = null, $input = null, $verify = null, $redirect = null ) {
		$this->designations = $designations;
		$this->observations = $observations;
		$this->topics       = $topics;
		$this->assigner     = $assigner;
		$this->mentions     = $mentions;
		$this->finder       = new TopicFinder( $topics );
		$this->make_table   = $make_table;
		$this->script_url   = $script_url;
		$this->can          = $can ?? 'current_user_can';
		$this->input        = $input ?? static function ( $type, $name, $is_array ) {
			return filter_input( $type, $name, FILTER_DEFAULT, $is_array ? FILTER_REQUIRE_ARRAY : FILTER_FLAG_NONE );
		};
		$this->verify       = $verify ?? 'wp_verify_nonce';
		$this->redirect     = $redirect ?? static function ( $url ) {
			wp_safe_redirect( $url );
			exit;
		};
	}

	/**
	 * Registers the screen, its script and its handlers.
	 *
	 * @param callable $add_action `add_action`, or a double.
	 * @return void
	 */
	public function register( $add_action ) {
		$add_action( 'admin_menu', array( $this, 'add_menu' ), 10, 0 );
		$add_action( AdminMenu::FILTER, array( $this, 'declare_screen' ), 10, 1 );
		$add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 10, 1 );
		$add_action( 'admin_post_' . self::APPLY, array( $this, 'apply' ), 10, 0 );
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
			'title'       => __( 'Assignments', 'otherguise' ),
			'description' => __( 'What the sources designated and the topic each designation is assigned to: assign, confirm, take away.', 'otherguise' ),
			'capability'  => $this->capability(),
			'page'        => self::PAGE,
			'group'       => 'settings',
			'order'       => 45,
		);

		return $screens;
	}

	/**
	 * Adds the screen to the menu, and handles the bulk actions when it loads. Action `admin_menu`.
	 *
	 * @return void
	 */
	public function add_menu() {
		$hook = add_submenu_page( AdminMenu::SLUG, __( 'Assignments', 'otherguise' ), __( 'Assignments', 'otherguise' ), $this->capability(), self::PAGE, array( $this, 'render' ) );

		if ( is_string( $hook ) && '' !== $hook ) {
			$this->hook = $hook;
			add_action( 'load-' . $hook, array( $this, 'process' ), 10, 0 );
		}
	}

	/**
	 * Loads the script that finds the topics, on this screen only. Action `admin_enqueue_scripts`.
	 *
	 * @param string $hook The page that is loaded.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_script( 'otherguise-topic-picker', ( $this->script_url )( 'topic-picker.js' ), array(), '630.1', true );
		wp_add_inline_script(
			'otherguise-topic-picker',
			'window.otherguiseTopics = ' . wp_json_encode(
				array(
					'root'  => esc_url_raw( rest_url() ),
					'path'  => TopicsRoute::REST_NAMESPACE . '/topics',
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The address of this screen.
	 *
	 * @param array<string, mixed> $args The arguments.
	 * @return string
	 */
	public function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The numbers of a field of the request.
	 *
	 * @param int    $type INPUT_GET or INPUT_POST.
	 * @param string $name The field (an array, or a list separated by commas).
	 * @return int[]
	 */
	private function numbers( $type, $name ) {
		$value = ( $this->input )( $type, $name, true );
		$value = is_array( $value ) && array() !== $value ? $value : explode( ',', (string) ( $this->input )( $type, $name, false ) );

		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	/**
	 * The topic that a request names: the number that the field of the page put in `to`, else what was typed, a number or a label.
	 *
	 * @param int $type INPUT_GET or INPUT_POST.
	 * @return array{id:int,error:string}
	 */
	private function target( $type ) {
		$to = absint( (string) ( $this->input )( $type, 'to', false ) );

		if ( $to > 0 && null !== $this->topics->get( $to ) ) {
			return array(
				'id'    => $to,
				'error' => '',
			);
		}

		return $this->finder->find( sanitize_text_field( (string) ( $this->input )( $type, 'to_label', false ) ) );
	}

	/**
	 * Handles the bulk actions of the table when the page loads: the capability and the nonce, then the action, then a redirection. Action `load-<page>`.
	 *
	 * @return void
	 */
	public function process() {
		$action = sanitize_key( (string) ( $this->input )( INPUT_POST, 'action', false ) );
		$action = in_array( $action, array( '', '-1' ), true ) ? sanitize_key( (string) ( $this->input )( INPUT_POST, 'action2', false ) ) : $action;

		if ( '' === $action || '-1' === $action ) {
			return;
		}

		if ( ! (bool) ( $this->can )( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'otherguise' ), '', array( 'response' => 403 ) );

			return;
		}

		$nonce = (string) ( $this->input )( INPUT_POST, '_wpnonce', false );

		if ( '' === $nonce || ! (bool) ( $this->verify )( $nonce, self::BULK ) ) {
			wp_die( esc_html__( 'This form has expired: reload the page.', 'otherguise' ), '', array( 'response' => 403 ) );

			return;
		}

		$ids = $this->numbers( INPUT_POST, 'designation' );

		if ( array() === $ids ) {
			( $this->redirect )( $this->url( array( 'error' => __( 'Tick at least one designation.', 'otherguise' ) ) ) );

			return;
		}

		$back = array_filter( array( 'view' => sanitize_key( (string) ( $this->input )( INPUT_POST, 'view', false ) ) ) );

		if ( 'assign' === $action ) {
			$found = $this->target( INPUT_POST );

			( $this->redirect )(
				$this->url(
					$found['id'] > 0 ? array(
						'confirm' => 1,
						'ids'     => implode( ',', $ids ),
						'to'      => $found['id'],
					) : array( 'error' => $found['error'] )
				)
			);

			return;
		}

		( $this->redirect )( $this->url( array_merge( $back, $this->bulk( $action, $ids ) ) ) );
	}

	/**
	 * Does a bulk action that needs no confirmation.
	 *
	 * @param string $action `confirm`, `unassign` or `delete`.
	 * @param int[]  $ids    The designations.
	 * @return array<string, int|string> What to say.
	 */
	private function bulk( $action, array $ids ) {
		$done  = 0;
		$skip  = 0;
		$posts = array();

		if ( 'delete' === $action ) {
			$counts = $this->observations->post_counts( $ids );
		}

		foreach ( $ids as $id ) {
			$have = $this->observations->assignment( $id );

			if ( null === $this->designations->get( $id ) || ( 'confirm' === $action && null === $have ) || ( 'unassign' === $action && null === $have ) || ( 'delete' === $action && 0 !== ( $counts[ $id ] ?? 0 ) ) ) {
				++$skip;

				continue;
			}

			if ( 'confirm' === $action && null !== $have ) {
				$this->observations->assign( $id, $have['topic'], Observations::HAND );
			} elseif ( 'unassign' === $action ) {
				$posts = array_merge( $posts, $this->observations->posts_of( $id ) );
				$this->observations->unassign( $id );
				$this->designations->set_state(
					$id,
					Designations::AMBIGUOUS,
					array(
						'topic' => 0,
						'why'   => 'unassigned by hand',
					)
				);
			} elseif ( 'delete' === $action ) {
				$this->observations->unassign( $id );
				$this->designations->delete( $id );
			}

			++$done;
		}

		foreach ( array_unique( $posts ) as $post ) {
			$this->mentions->derive_post( (int) $post );
		}

		return array(
			'did'     => $action,
			'count'   => $done,
			'skipped' => $skip,
		);
	}

	/**
	 * Does the assignment that the confirmation asked. Action `admin_post_otherguise_index_assignments_apply`.
	 *
	 * @return void
	 */
	public function apply() {
		if ( ! (bool) ( $this->can )( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'otherguise' ), '', array( 'response' => 403 ) );

			return;
		}

		$nonce = (string) ( $this->input )( INPUT_POST, '_wpnonce', false );

		if ( '' === $nonce || ! (bool) ( $this->verify )( $nonce, self::NONCE ) ) {
			wp_die( esc_html__( 'This form has expired: reload the page.', 'otherguise' ), '', array( 'response' => 403 ) );

			return;
		}

		$ids   = $this->numbers( INPUT_POST, 'designation' );
		$found = $this->target( INPUT_POST );

		if ( array() === $ids || $found['id'] <= 0 ) {
			( $this->redirect )( $this->url( array( 'error' => array() === $ids ? __( 'Tick at least one designation.', 'otherguise' ) : $found['error'] ) ) );

			return;
		}

		$force = '1' === (string) ( $this->input )( INPUT_POST, 'force', false );
		$done  = $this->assigner->assign( $ids, $found['id'], false, $force );

		( $this->redirect )(
			$this->url(
				array_filter(
					array(
						'did'   => $done['ok'] ? 'assign' : '',
						'count' => $done['ok'] ? $done['designations'] : '',
						'posts' => $done['ok'] ? $done['posts'] : '',
						'error' => $done['error'],
					),
					static fn( $value ) => '' !== $value
				)
			)
		);
	}

	/**
	 * Prints the screen: the table, or the confirmation of an assignment.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! (bool) ( $this->can )( $this->capability() ) ) {
			return;
		}

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Assignments', 'otherguise' ) . '</h1><hr class="wp-header-end" />';
		$this->notices();

		if ( '' !== (string) ( $this->input )( INPUT_GET, 'confirm', false ) ) {
			$this->confirmation();
		} else {
			$table = ( $this->make_table )();
			$view  = sanitize_key( (string) ( $this->input )( INPUT_GET, 'view', false ) );

			$table->prepare_items();
			$table->views();
			echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />' . ( '' === $view ? '' : '<input type="hidden" name="view" value="' . esc_attr( $view ) . '" />' );
			$table->search_box( __( 'Search', 'otherguise' ), 'assignment' );
			echo '</form><form method="post" action="' . esc_url( $this->url() ) . '">' . ( '' === $view ? '' : '<input type="hidden" name="view" value="' . esc_attr( $view ) . '" />' );
			$table->display();
			echo '</form>';
		}

		echo '</div>';
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
		$posts  = array();
		$count  = 0;

		foreach ( $ids as $id ) {
			$where  = $this->observations->posts_of( $id );
			$count += count( $where );
			$posts  = array_merge( $posts, $where );
		}

		if ( null === $target || array() === $ids ) {
			echo '<p>' . esc_html__( 'Nothing to assign.', 'otherguise' ) . '</p><p><a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Back', 'otherguise' ) . '</a></p>';

			return;
		}

		echo '<h2>' . esc_html__( 'Assign', 'otherguise' ) . '</h2>';
		/* translators: 1: designations, 2: observations, 3: posts, 4: topic label. */
		echo '<p>' . esc_html( sprintf( __( '%1$d designation(s), %2$d observation(s) in %3$d post(s), will be assigned to "%4$s". Nothing is deleted: the designations and the observations stay, and the mentions of the posts follow.', 'otherguise' ), count( $ids ), $count, count( array_unique( $posts ) ), (string) $target['label'] ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::APPLY ) . '" /><input type="hidden" name="to" value="' . esc_attr( (string) $target['id'] ) . '" />';

		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="designation[]" value="' . esc_attr( (string) $id ) . '" />';
		}

		echo '<p><label><input type="checkbox" name="force" value="1" /> ' . esc_html__( 'Assign them anyway, although their kinds are not one a sort of the other', 'otherguise' ) . '</label></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Assign', 'otherguise' ) . '</button> <a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Cancel', 'otherguise' ) . '</a></p></form>';
	}
}
