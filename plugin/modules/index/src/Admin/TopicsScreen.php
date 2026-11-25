<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The screen of the topics and of their motifs.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Admin;

use Otherguise\Core\AdminMenu;
use Otherguise\Index\Designation\Assigner;
use Otherguise\Index\Designation\TopicFinder;
use Otherguise\Index\Designations;
use Otherguise\Index\Folding;
use Otherguise\Index\Kinds;
use Otherguise\Index\Observations;
use Otherguise\Index\Resolver;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Otherguise → Topics (slice 628): **one screen to manage the topics and what is assigned to them** (Eric, 2026-11-24). The screens list **motifs**,
 * never observations.
 *
 * - **Topics**: the list (search, filters, counts), then a topic: its label, kind and parent are **edited in place** (by hand: the enrichment never replaces
 *   them), its identifiers, its motifs (**assigned to another topic** after a preview), its sub-topics, and for a motif the posts where it is found.
 * - **To resolve**: the motifs that are pending or ambiguous.
 * - **Suggestions**: the topics that may be the same (the screen of slice 618, in the vocabulary of the assignments).
 *
 * Each form posts to `admin-post.php` with the capability and a nonce.
 */
final class TopicsScreen {
	use TopicsViews;

	public const PAGE     = 'otherguise-index-topics';
	public const SAVE     = 'otherguise_index_topic_save';
	public const ASSIGN   = 'otherguise_index_topic_assign';
	public const NONCE    = 'otherguise_index_topics';
	public const PER_PAGE = 50;

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

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
	 * The assigner.
	 *
	 * @var Assigner
	 */
	private $assigner;

	/**
	 * The view of the suggestions.
	 *
	 * @var DuplicatesScreen
	 */
	private $suggestions;

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
	 * Checks a nonce.
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
	 * @param Topics           $topics       The topics.
	 * @param Designations     $designations The motifs.
	 * @param Observations     $observations The observations.
	 * @param Assigner         $assigner     The assigner.
	 * @param DuplicatesScreen $suggestions  The view of the suggestions.
	 * @param callable|null    $can          Default `current_user_can`.
	 * @param callable|null    $input        Default `filter_input`.
	 * @param callable|null    $verify       Default `wp_verify_nonce`.
	 * @param callable|null    $redirect     Default `wp_safe_redirect` then `exit`.
	 */
	public function __construct( Topics $topics, Designations $designations, Observations $observations, Assigner $assigner, DuplicatesScreen $suggestions, $can = null, $input = null, $verify = null, $redirect = null ) {
		$this->topics       = $topics;
		$this->designations = $designations;
		$this->observations = $observations;
		$this->assigner     = $assigner;
		$this->suggestions  = $suggestions;
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
	 * Registers the screen and the handlers (the one of the suggestions too).
	 *
	 * @param callable $add_action `add_action`, or a double.
	 * @return void
	 */
	public function register( $add_action ) {
		$add_action( 'admin_menu', array( $this, 'add_menu' ), 10, 0 );
		$add_action( AdminMenu::FILTER, array( $this, 'declare_screen' ), 10, 1 );
		$add_action( 'admin_post_' . self::SAVE, array( $this, 'save' ), 10, 0 );
		$add_action( 'admin_post_' . self::ASSIGN, array( $this, 'assign' ), 10, 0 );
		$this->suggestions->register( $add_action );
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
			'title'       => __( 'Topics', 'otherguise' ),
			'description' => __( 'The topics of the index, the motifs that the sources found and where each is assigned: rename a topic, assign motifs to another, look at the suggestions.', 'otherguise' ),
			'capability'  => $this->capability(),
			'page'        => self::PAGE,
			'group'       => 'settings',
			'order'       => 46,
		);

		return $screens;
	}

	/**
	 * Adds the screen to the menu. Action `admin_menu`.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page( AdminMenu::SLUG, __( 'Topics', 'otherguise' ), __( 'Topics', 'otherguise' ), $this->capability(), self::PAGE, array( $this, 'render' ) );
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
	 * The tab that a request names: `topics` when it names none.
	 *
	 * @param int $type INPUT_GET or INPUT_POST.
	 * @return string
	 */
	private function tab( $type ) {
		$tab = sanitize_key( (string) ( $this->input )( $type, 'tab', false ) );

		return '' === $tab ? 'topics' : $tab;
	}

	/**
	 * Reads a list of numbers of the request.
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
	 * The address of this screen.
	 *
	 * @param array<string, mixed> $args The arguments.
	 * @return string
	 */
	private function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The capability and the nonce of a form: the request stops when one is missing.
	 *
	 * @return bool Whether the request may go on.
	 */
	private function allowed() {
		if ( ! (bool) ( $this->can )( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'otherguise' ), '', array( 'response' => 403 ) );

			return false;
		}

		$nonce = (string) ( $this->input )( INPUT_POST, '_wpnonce', false );

		if ( '' === $nonce || ! (bool) ( $this->verify )( $nonce, self::NONCE ) ) {
			wp_die( esc_html__( 'This form has expired: reload the page.', 'otherguise' ), '', array( 'response' => 403 ) );

			return false;
		}

		return true;
	}

	/**
	 * Saves the label, the kind and the parent of a topic: what a person chose is **by hand**, and the enrichment never replaces it. Action
	 * `admin_post_otherguise_index_topic_save`.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! $this->allowed() ) {
			return;
		}

		$id    = absint( (string) ( $this->input )( INPUT_POST, 'topic', false ) );
		$topic = $this->topics->get( $id );
		$label = $this->text( INPUT_POST, 'label' );
		$kind  = sanitize_key( (string) ( $this->input )( INPUT_POST, 'kind', false ) );
		$above = absint( (string) ( $this->input )( INPUT_POST, 'parent', false ) );
		$error = '';

		if ( null === $topic ) {
			$error = __( 'This topic does not exist.', 'otherguise' );
		} elseif ( '' === $label ) {
			$error = __( 'A topic needs a label.', 'otherguise' );
		} elseif ( '' !== $kind && ! Kinds::is_kind( $kind ) ) {
			$error = __( 'This kind does not exist.', 'otherguise' );
		} elseif ( $above > 0 && null === $this->topics->get( $above ) ) {
			$error = __( 'The topic above does not exist.', 'otherguise' );
		} elseif ( $above > 0 && $this->is_below_or_is( $above, $id ) ) {
			$error = __( 'A topic cannot be put under itself or under one of its own sub-topics.', 'otherguise' );
		}

		if ( '' === $error && null !== $topic ) {
			if ( $label !== $topic['label'] ) {
				$this->topics->set_label( $id, $label, true );
				$this->topics->add_keys( $id, array( Resolver::label_key( $above, $label ) ) );
			}

			if ( '' !== $kind && $kind !== $topic['kind'] ) {
				$this->topics->set_kind( $id, $kind, true );
			}

			if ( $above !== (int) $topic['parent'] ) {
				$this->topics->set_parent( $id, $above );
			}
		}

		( $this->redirect )(
			$this->url(
				array_filter(
					array(
						'topic' => $id,
						'saved' => '' === $error ? 1 : 0,
						'error' => $error,
					),
					static fn( $value ) => '' !== $value
				)
			)
		);
	}

	/**
	 * Whether a topic is another one or below it.
	 *
	 * @param int $id    The topic.
	 * @param int $other The other topic.
	 * @return bool
	 */
	private function is_below_or_is( $id, $other ) {
		for ( $level = 0; $id > 0 && $level < 30; $level++ ) {
			if ( $id === $other ) {
				return true;
			}

			$topic = $this->topics->get( $id );
			$id    = null === $topic ? 0 : (int) $topic['parent'];
		}

		return false;
	}

	/**
	 * Assigns motifs to a topic: the first step makes a preview, the second does it. Action `admin_post_otherguise_index_topic_assign`.
	 *
	 * @return void
	 */
	public function assign() {
		if ( ! $this->allowed() ) {
			return;
		}

		$op      = sanitize_key( (string) ( $this->input )( INPUT_POST, 'op', false ) );
		$from    = absint( (string) ( $this->input )( INPUT_POST, 'topic', false ) );
		$ids     = $this->numbers( INPUT_POST, 'designation' );
		$back    = array(
			'tab'   => $this->tab( INPUT_POST ),
			'topic' => $from,
		);
		$new     = array(
			'new_label'  => $this->text( INPUT_POST, 'new_label' ),
			'new_kind'   => sanitize_key( (string) ( $this->input )( INPUT_POST, 'new_kind', false ) ),
			'new_parent' => absint( (string) ( $this->input )( INPUT_POST, 'new_parent', false ) ),
		);
		$to_text = $this->text( INPUT_POST, 'to' );

		if ( '' !== (string) ( $this->input )( INPUT_POST, 'all', false ) && $from > 0 ) {
			$ids = array_keys( $this->observations->designations_of_topic( $from ) );
		}

		if ( array() === $ids ) {
			( $this->redirect )( $this->url( array_merge( $back, array( 'error' => __( 'Tick at least one motif.', 'otherguise' ) ) ) ) );

			return;
		}

		if ( '' !== $new['new_label'] ) {
			$target = 0;
		} else {
			$found  = $this->target( $to_text );
			$target = $found['id'];

			if ( $target <= 0 ) {
				( $this->redirect )( $this->url( array_merge( $back, array( 'error' => $found['error'] ) ) ) );

				return;
			}
		}

		$args = array_merge(
			$back,
			array(
				'ids'   => implode( ',', $ids ),
				'to'    => $target,
				'force' => '' !== (string) ( $this->input )( INPUT_POST, 'force', false ) ? 1 : 0,
				'all'   => '' !== (string) ( $this->input )( INPUT_POST, 'all', false ) ? 1 : 0,
			),
			array_filter( $new )
		);

		if ( 'apply' !== $op ) {
			( $this->redirect )( $this->url( array_merge( $args, array( 'preview' => 1 ) ) ) );

			return;
		}

		if ( $target <= 0 ) {
			$target = $this->topics->create(
				$new['new_label'],
				'' !== $new['new_kind'] && Kinds::is_kind( $new['new_kind'] ) ? $new['new_kind'] : Kinds::ANY,
				$new['new_parent'] > 0 && null !== $this->topics->get( $new['new_parent'] ) ? $new['new_parent'] : 0,
				Topics::APPROVED,
				array( Resolver::label_key( $new['new_parent'], $new['new_label'] ) )
			);
			$this->topics->set_kind( $target, '' !== $new['new_kind'] && Kinds::is_kind( $new['new_kind'] ) ? $new['new_kind'] : Kinds::ANY, true );
		}

		$done = $this->assigner->assign( $ids, $target, false, 1 === $args['force'], 1 === $args['all'] ? $from : 0 );

		( $this->redirect )(
			$this->url(
				array_merge(
					$back,
					array(
						'assigned' => $done['ok'] ? $done['designations'] : 0,
						'posts'    => $done['posts'],
						'error'    => $done['error'],
					)
				)
			)
		);
	}

	/**
	 * Finds the topic that a person means by an identifier or a label.
	 *
	 * @param string $text An identifier, or a label.
	 * @return array{id:int,error:string}
	 */
	private function target( $text ) {
		return ( new TopicFinder( $this->topics ) )->find( $text );
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

		$tab = sanitize_key( (string) ( $this->input )( INPUT_GET, 'tab', false ) );
		$tab = in_array( $tab, array( 'resolve', 'suggestions' ), true ) ? $tab : 'topics';

		echo '<div class="wrap"><h1>' . esc_html__( 'Topics', 'otherguise' ) . '</h1>';
		$this->notices();
		echo '<h2 class="nav-tab-wrapper">';

		foreach ( array(
			'topics'      => __( 'Topics', 'otherguise' ),
			'resolve'     => __( 'To resolve', 'otherguise' ),
			'suggestions' => __( 'Suggestions', 'otherguise' ),
		) as $slug => $label ) {
			echo '<a class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $this->url( array( 'tab' => $slug ) ) ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '</h2>';

		if ( 'suggestions' === $tab ) {
			$this->suggestions->body();
		} elseif ( 'resolve' === $tab ) {
			$this->resolve_view();
		} elseif ( '' !== (string) ( $this->input )( INPUT_GET, 'preview', false ) ) {
			$this->preview_view();
		} elseif ( absint( (string) ( $this->input )( INPUT_GET, 'designation', false ) ) > 0 ) {
			$this->designation_view( absint( (string) ( $this->input )( INPUT_GET, 'designation', false ) ) );
		} elseif ( absint( (string) ( $this->input )( INPUT_GET, 'topic', false ) ) > 0 ) {
			$this->topic_view( absint( (string) ( $this->input )( INPUT_GET, 'topic', false ) ) );
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
		$error    = $this->text( INPUT_GET, 'error' );
		$saved    = (string) ( $this->input )( INPUT_GET, 'saved', false );
		$assigned = (string) ( $this->input )( INPUT_GET, 'assigned', false );

		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		} elseif ( '1' === $saved ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'otherguise' ) . '</p></div>';
		} elseif ( '' !== $assigned ) {
			/* translators: 1: motifs assigned, 2: posts whose mentions follow. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%1$d motif(s) assigned; the mentions of %2$d post(s) follow.', 'otherguise' ), absint( $assigned ), absint( (string) ( $this->input )( INPUT_GET, 'posts', false ) ) ) ) . '</p></div>';
		}
	}
}
