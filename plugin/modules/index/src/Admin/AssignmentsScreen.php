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
use Otherguise\Index\Designation\Exclusion;
use Otherguise\Index\Designation\Reresolver;
use Otherguise\Index\Designation\TopicFinder;
use Otherguise\Index\Designations;
use Otherguise\Index\Kinds;
use Otherguise\Index\Resolver;
use Otherguise\Index\Mentions;
use Otherguise\Index\Observations;
use Otherguise\Index\Rest\TopicsRoute;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Otherguise → Assignments (slice 630): **the motifs and the topic each is assigned to**, in a table of WordPress (views, search, sorting, pages of 50,
 * bulk actions). A person ticks motifs and **assigns them to a topic that a field finds as one types** (a preview comes first), **confirms** what the
 * system proposed, **takes an assignment away** or **deletes** the motifs that no post observes. The mentions follow every change.
 *
 * The bulk actions of a table of WordPress post to the screen itself (their field `action` would be taken for an action of `admin-post.php`): the
 * request is handled when the page loads (`load-<page>`), then redirected; only the confirmation of an assignment posts to `admin-post.php`.
 */
final class AssignmentsScreen {
	use AssignmentsViews;

	public const PAGE  = 'otherguise-index-assignments';
	public const APPLY = 'otherguise_index_assignments_apply';
	public const NONCE = 'otherguise_index_assignments';
	public const BULK  = 'bulk-designations';

	/**
	 * The motifs.
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
	 * The choices among the topics that a level hesitated between.
	 *
	 * @var Reresolver
	 */
	private $reresolver;

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
	 * @param Designations  $designations The motifs.
	 * @param Observations  $observations The observations.
	 * @param Topics        $topics       The topics.
	 * @param Assigner      $assigner     The assigner.
	 * @param Mentions      $mentions     The mentions.
	 * @param Reresolver    $reresolver   The choices among the topics that a level hesitated between.
	 * @param callable      $make_table   Makes the table.
	 * @param callable      $script_url   The address of a script of the screen.
	 * @param callable|null $can         Default `current_user_can`.
	 * @param callable|null $input       Default `filter_input`.
	 * @param callable|null $verify      Default `wp_verify_nonce`.
	 * @param callable|null $redirect    Default `wp_safe_redirect` then `exit`.
	 */
	public function __construct( Designations $designations, Observations $observations, Topics $topics, Assigner $assigner, Mentions $mentions, Reresolver $reresolver, $make_table, $script_url, $can = null, $input = null, $verify = null, $redirect = null ) {
		$this->designations = $designations;
		$this->observations = $observations;
		$this->topics       = $topics;
		$this->assigner     = $assigner;
		$this->mentions     = $mentions;
		$this->reresolver   = $reresolver;
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
			'description' => __( 'The motifs that the sources found and the topic each is assigned to: assign, confirm, take away.', 'otherguise' ),
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

		wp_enqueue_script( 'otherguise-topic-picker', ( $this->script_url )( 'topic-picker.js' ), array(), '632.1', true );
		wp_add_inline_script(
			'otherguise-topic-picker',
			'window.otherguiseTopics = ' . wp_json_encode(
				array(
					'root'  => esc_url_raw( rest_url() ),
					'path'  => TopicsRoute::REST_NAMESPACE . '/topics',
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'edit'  => TopicsRoute::REST_NAMESPACE . '/motifs/',
					'words' => $this->quick_edit_words(),
				)
			) . ';',
			'before'
		);
		wp_enqueue_script( 'otherguise-quick-edit', ( $this->script_url )( 'quick-edit.js' ), array( 'otherguise-topic-picker' ), '633.1', true );
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
	 * What the page was showing, to come back to it: the view, the search, the sort and the page.
	 *
	 * @param int $type INPUT_GET or INPUT_POST.
	 * @return array<string, string>
	 */
	private function preserved( $type ) {
		$kept = array();

		foreach ( array( 'view', 's', 'orderby', 'order', 'paged' ) as $name ) {
			$value = sanitize_text_field( (string) ( $this->input )( $type, $name, false ) );

			if ( '' !== $value ) {
				$kept[ $name ] = $value;
			}
		}

		return $kept;
	}

	/**
	 * The topic that the new topic goes under: the number that the field of the page put in `new_parent`, else what was typed in its label.
	 *
	 * @return array{id:int,error:string} `id` is 0 for none, and -1 when what was typed names no topic (or several).
	 */
	private function parent() {
		$id = absint( (string) ( $this->input )( INPUT_POST, 'new_parent', false ) );

		if ( $id > 0 && null !== $this->topics->get( $id ) ) {
			return array(
				'id'    => $id,
				'error' => '',
			);
		}

		$typed = $this->text( INPUT_POST, 'new_parent_label' );

		if ( '' === $typed ) {
			return array(
				'id'    => 0,
				'error' => '',
			);
		}

		$found = $this->finder->find( $typed );

		return array(
			'id'    => $found['id'] > 0 ? $found['id'] : -1,
			'error' => $found['error'],
		);
	}

	/**
	 * Reads a text field of the request.
	 *
	 * @param int    $type INPUT_GET or INPUT_POST.
	 * @param string $name The field.
	 * @return string
	 */
	private function text( $type, $name ) {
		return sanitize_text_field( (string) ( $this->input )( $type, $name, false ) );
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

		$back = $this->preserved( INPUT_POST );

		// The choices are made in the lists of the lines: a line with a choice counts, ticked or not.
		if ( 'choose' === $action ) {
			( $this->redirect )( $this->url( array_merge( $back, $this->choose() ) ) );

			return;
		}

		$ids = $this->numbers( INPUT_POST, 'designation' );

		if ( array() === $ids ) {
			( $this->redirect )( $this->url( array_merge( $back, array( 'error' => __( 'Tick at least one motif.', 'otherguise' ) ) ) ) );

			return;
		}

		if ( 'assign' === $action && '' !== $this->text( INPUT_POST, 'new_label' ) ) {
			$parent = $this->parent();

			if ( $parent['id'] < 0 ) {
				( $this->redirect )( $this->url( array_merge( $back, array( 'error' => $parent['error'] ) ) ) );

				return;
			}

			( $this->redirect )(
				$this->url(
					array_filter(
						array_merge(
							$back,
							array(
								'confirm'    => 1,
								'ids'        => implode( ',', $ids ),
								'to'         => 0,
								'new_label'  => $this->text( INPUT_POST, 'new_label' ),
								'new_kind'   => sanitize_key( (string) ( $this->input )( INPUT_POST, 'new_kind', false ) ),
								'new_parent' => $parent['id'],
							)
						)
					)
				)
			);

			return;
		}

		if ( 'assign' === $action ) {
			$found = $this->target( INPUT_POST );

			( $this->redirect )(
				$this->url(
					array_merge(
						$back,
						$found['id'] > 0 ? array(
							'confirm' => 1,
							'ids'     => implode( ',', $ids ),
							'to'      => $found['id'],
						) : array( 'error' => $found['error'] )
					)
				)
			);

			return;
		}

		( $this->redirect )( $this->url( array_merge( $back, $this->bulk( $action, $ids ) ) ) );
	}

	/**
	 * Applies the choices made in the lists of the lines: for each line with a choice, the topic is pinned to the level that hesitated, the motif is resolved
	 * again and assigned by hand.
	 *
	 * @return array<string, int|string> What to say.
	 */
	private function choose() {
		$choices = (array) ( $this->input )( INPUT_POST, 'choice', true );
		$done    = 0;
		$error   = '';

		foreach ( $choices as $id => $topic ) {
			if ( absint( (string) $topic ) <= 0 ) {
				continue;
			}

			$result = $this->reresolver->choose( absint( (string) $id ), absint( (string) $topic ) );

			if ( $result['ok'] ) {
				++$done;
			} elseif ( '' === $error ) {
				$error = $result['error'];
			}
		}

		if ( 0 === $done && '' === $error ) {
			$error = __( 'Choose a topic in at least one list.', 'otherguise' );
		}

		return array_filter(
			array(
				'did'   => $done > 0 ? 'choose' : '',
				'count' => $done > 0 ? $done : '',
				'error' => $error,
			),
			static fn( $value ) => '' !== $value
		);
	}

	/**
	 * Does a bulk action that needs no confirmation.
	 *
	 * @param string $action `confirm`, `unassign` or `delete`.
	 * @param int[]  $ids    The motifs.
	 * @return array<string, int|string> What to say.
	 */
	private function bulk( $action, array $ids ) {
		$done  = 0;
		$skip  = 0;
		$posts = array();

		// Taking motifs out of the index, and back (slice 635).
		if ( in_array( $action, array( 'exclude', 'include' ), true ) ) {
			$exclusion = new Exclusion( $this->designations, $this->observations, $this->mentions );
			$count     = 'exclude' === $action ? $exclusion->exclude( $ids )['designations'] : $exclusion->include( $ids );

			return array(
				'did'     => $action,
				'count'   => $count,
				'skipped' => count( $ids ) - $count,
			);
		}

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

		$back  = $this->preserved( INPUT_POST );
		$ids   = $this->numbers( INPUT_POST, 'designation' );
		$new   = $this->text( INPUT_POST, 'new_label' );
		$found = '' !== $new ? array(
			'id'    => 0,
			'error' => '',
		) : $this->target( INPUT_POST );

		if ( array() === $ids || ( '' === $new && $found['id'] <= 0 ) ) {
			( $this->redirect )( $this->url( array_merge( $back, array( 'error' => array() === $ids ? __( 'Tick at least one motif.', 'otherguise' ) : $found['error'] ) ) ) );

			return;
		}

		$force   = '1' === (string) ( $this->input )( INPUT_POST, 'force', false );
		$created = 0;

		if ( '' !== $new ) {
			$kind    = sanitize_key( (string) ( $this->input )( INPUT_POST, 'new_kind', false ) );
			$kind    = '' !== $kind && Kinds::is_kind( $kind ) ? $kind : Kinds::ANY;
			$parent  = absint( (string) ( $this->input )( INPUT_POST, 'new_parent', false ) );
			$parent  = $parent > 0 && null !== $this->topics->get( $parent ) ? $parent : 0;
			$created = $this->topics->create( $new, $kind, $parent, Topics::APPROVED, array( Resolver::label_key( $parent, $new ) ) );

			$this->topics->set_kind( $created, $kind, true );

			$found['id'] = $created;
		}

		$done = $this->assigner->assign( $ids, $found['id'], false, $force );

		// A topic that was made for an assignment that was refused is not kept.
		if ( $created > 0 && ! $done['ok'] ) {
			$this->topics->delete( $created );
		}

		( $this->redirect )(
			$this->url(
				array_merge(
					$back,
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

			$table->prepare_items();
			echo '<p class="description">' . esc_html__( 'Search motifs (top right) looks in the label, the key (which holds the source and, for a place, its chain of names), the topic it is assigned to, the identifier of Wikidata and the state, within the view chosen above. To assign the ticked lines, fill the box "Where Assign puts the ticked lines", then choose "Assign to the topic in the box above". For a motif that hesitated, pick the topic in the list of its line, then "Apply the choices".', 'otherguise' ) . '</p>';
			$table->views();
			echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';

			foreach ( array_diff_key(
				$this->preserved( INPUT_GET ),
				array(
					's' => 1,
					'paged' => 1,
				)
			) as $name => $value ) {
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
			}

			$table->search_box( __( 'Search motifs', 'otherguise' ), 'assignment' );
			echo '</form><form method="post" action="' . esc_url( $this->url() ) . '">';
			$this->print_preserved_fields();
			$table->target_fields();
			$table->display();
			echo '</form>';
		}

		echo '</div>';
	}
}
