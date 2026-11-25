<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The screen of the index: the state of the synchronisation and what is on.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Admin;

use Otherguise\Core\AdminMenu;
use Otherguise\Index\Job\PagesJob;
use Otherguise\Index\Job\SyncService;

defined( 'ABSPATH' ) || exit;

/**
 * Otherguise → Index (slice 622): what the synchronisation is doing (with the buttons to update, start again from scratch and stop) and the features
 * that are on (the sources, the Wikidata enrichment and its contact, the night run). The screen adds no logic: it
 * reads and writes the options that the commands write, and calls `SyncService`. Each form posts to `admin-post.php` with the capability and a nonce.
 */
final class IndexScreen {
	public const PAGE  = 'otherguise-index';
	public const SAVE  = 'otherguise_index_save';
	public const RUN   = 'otherguise_index_run';
	public const NONCE = 'otherguise_index_screen';

	/**
	 * The sources that the screen knows, by field: the slug of the source.
	 *
	 * @var array<string, string>
	 */
	public const SOURCES = array(
		'src_categories' => 'categories',
		'src_tags'       => 'tags',
		'src_page'       => 'page',
		'src_photos'     => 'photos',
	);

	/**
	 * The synchronisation.
	 *
	 * @var SyncService
	 */
	private $sync;

	/**
	 * Reads the features: `fn(): array{sources:string[],enrich:bool,contact:string,auto:bool}`.
	 *
	 * @var callable
	 */
	private $read;

	/**
	 * Writes the features (the same shape).
	 *
	 * @var callable
	 */
	private $write;

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
	 * @param SyncService   $sync     The synchronisation.
	 * @param callable      $read     Reads the features.
	 * @param callable      $write    Writes the features.
	 * @param callable|null $can      Default `current_user_can`.
	 * @param callable|null $input    Default `filter_input`.
	 * @param callable|null $verify   Default `wp_verify_nonce`.
	 * @param callable|null $redirect Default `wp_safe_redirect` then `exit`.
	 */
	public function __construct( SyncService $sync, $read, $write, $can = null, $input = null, $verify = null, $redirect = null ) {
		$this->sync     = $sync;
		$this->read     = $read;
		$this->write    = $write;
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
		$add_action( 'admin_post_' . self::SAVE, array( $this, 'save' ), 10, 0 );
		$add_action( 'admin_post_' . self::RUN, array( $this, 'run' ), 10, 0 );
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
			'title'       => __( 'Index', 'otherguise' ),
			'description' => __( 'The state of the synchronisation of the index, and what is on: the sources, the Wikidata enrichment, the night run.', 'otherguise' ),
			'capability'  => $this->capability(),
			'page'        => self::PAGE,
			'group'       => 'settings',
			'order'       => 45,
		);

		return $screens;
	}

	/**
	 * Adds the screen to the menu. Action `admin_menu`.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page( AdminMenu::SLUG, __( 'Index', 'otherguise' ), __( 'Index', 'otherguise' ), $this->capability(), self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * The features that the form says, over the ones that are saved: the sources that the screen does not know (a plugin's) are kept, the photographs
	 * turn the pages on.
	 *
	 * @param callable $field Reads a posted field.
	 * @return array{sources:string[],enrich:bool,contact:string,auto:bool}
	 */
	private function features_from( $field ) {
		$current = (array) ( $this->read )();
		$sources = array_values( array_diff( (array) ( $current['sources'] ?? array() ), array_values( self::SOURCES ) ) );

		foreach ( self::SOURCES as $name => $slug ) {
			if ( '' !== $field( $name ) ) {
				$sources[] = $slug;
			}
		}

		if ( in_array( 'photos', $sources, true ) && ! in_array( 'page', $sources, true ) ) {
			$sources[] = 'page';
		}

		return array(
			'sources' => array_values( array_unique( $sources ) ),
			'enrich'  => '' !== $field( 'enrich' ),
			'contact' => sanitize_text_field( $field( 'contact' ) ),
			'auto'    => '' !== $field( 'auto' ),
		);
	}

	/**
	 * Saves what is on. Action `admin_post_otherguise_index_save`.
	 *
	 * @return void
	 */
	public function save() {
		$this->done(
			self::SAVE,
			function ( $field ) {
				( $this->write )( $this->features_from( $field ) );
				$this->sync->ensure_scheduled();

				return 'saved';
			}
		);
	}

	/**
	 * Updates, starts again from scratch or stops. Action `admin_post_otherguise_index_run`.
	 *
	 * @return void
	 */
	public function run() {
		$this->done(
			self::RUN,
			function ( $field ) {
				$operation = $field( 'op' );

				if ( 'stop' === $operation ) {
					$this->sync->stop();

					return 'stopped';
				}

				if ( 'start' !== $operation && 'scratch' !== $operation ) {
					return 'The operation is not known.';
				}

				return $this->sync->start( 'scratch' === $operation )['started'] ? 'started' : 'A synchronisation is already running.';
			}
		);
	}

	/**
	 * The common part of the handlers: the capability, the nonce, the operation, then back to the screen with the outcome.
	 *
	 * @param string   $action    The action.
	 * @param callable $operation Receives a function that reads a posted field; returns what was done (`saved`, `started`, `stopped`) or the reason it failed.
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

		$result = (string) $operation( fn( $name ) => (string) ( $this->input )( INPUT_POST, $name ) );
		$ok     = in_array( $result, array( 'saved', 'started', 'stopped' ), true );

		( $this->redirect )(
			add_query_arg(
				array(
					'page'   => self::PAGE,
					'done'   => $ok ? $result : '',
					'failed' => $ok ? '' : rawurlencode( $result ),
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

		echo '<div class="wrap"><h1>' . esc_html__( 'Index', 'otherguise' ) . '</h1>';
		$this->print_notice();
		$this->print_state();
		$this->print_features();
		echo '</div>';
	}

	/**
	 * Prints the outcome of the last form.
	 *
	 * @return void
	 */
	private function print_notice() {
		$failed = (string) ( $this->input )( INPUT_GET, 'failed' );
		$done   = (string) ( $this->input )( INPUT_GET, 'done' );
		$words  = array(
			'saved'   => __( 'Saved. A new source shows in the index after an update of the posts that changed, or after starting again from scratch.', 'otherguise' ),
			'started' => __( 'The synchronisation started: WP-Cron does the rest.', 'otherguise' ),
			'stopped' => __( 'The synchronisation was stopped.', 'otherguise' ),
		);

		if ( '' !== $failed ) {
			echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( $failed ) ) . '</p></div>';
		} elseif ( isset( $words[ $done ] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $words[ $done ] ) . '</p></div>';
		}
	}

	/**
	 * Prints the state of the synchronisation and its buttons.
	 *
	 * @return void
	 */
	private function print_state() {
		$status  = $this->sync->status();
		$state   = $status['state'];
		$running = PagesJob::RUNNING === $state['status'];

		echo '<h2>' . esc_html__( 'Synchronisation', 'otherguise' ) . '</h2>';
		echo '<p><strong>' . esc_html( $this->status_text( $state ) ) . '</strong></p>';

		if ( $running ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: posts read, 2: posts to read, 3: posts that failed. */
					__( 'Reading: %1$d of %2$d posts, %3$d failed.', 'otherguise' ),
					(int) $status['read']['offset'],
					(int) $status['read']['total'],
					count( (array) $status['read']['failed'] )
				)
			) . '</p>';

			if ( null !== $status['enrich'] ) {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: 1: topics done, 2: topics to enrich. */
						__( 'Enrichment: %1$d of %2$d topics.', 'otherguise' ),
						(int) $status['enrich']['offset'],
						(int) $status['enrich']['total']
					)
				) . '</p>';
			}
		}

		if ( $state['finished'] > 0 ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: date, 2: posts read, 3: topics deleted. */
					__( 'Last run finished at %1$s UTC: %2$d posts read, %3$d topics deleted.', 'otherguise' ),
					gmdate( 'Y-m-d H:i:s', (int) $state['finished'] ),
					(int) $state['collected'],
					(int) $state['deleted']
				)
			) . '</p>';
		}

		echo '<p>';
		$this->print_run_button( 'start', __( 'Update', 'otherguise' ), true, $running );
		$this->print_run_button( 'scratch', __( 'Start again from scratch', 'otherguise' ), false, $running, __( 'This drops what the sources said (never what a person made) and reads every post again. Go on?', 'otherguise' ) );
		$this->print_run_button( 'stop', __( 'Stop', 'otherguise' ), false, ! $running );
		echo ' <a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Refresh', 'otherguise' ) . '</a></p>';

		if ( array() !== $state['log'] ) {
			echo '<pre>' . esc_html( implode( "\n", $state['log'] ) ) . '</pre>';
		}
	}

	/**
	 * A sentence that says where the synchronisation is.
	 *
	 * @param array<string, mixed> $state The state.
	 * @return string
	 */
	private function status_text( array $state ) {
		if ( PagesJob::RUNNING === $state['status'] ) {
			/* translators: 1: phase (collect, enrich, cleanup). */
			return sprintf( __( 'Running, phase: %1$s%2$s.', 'otherguise' ), (string) $state['phase'], $state['scratch'] ? ' ' . __( '(from scratch)', 'otherguise' ) : '' );
		}

		if ( PagesJob::STOPPED === $state['status'] ) {
			return __( 'Stopped.', 'otherguise' );
		}

		return PagesJob::DONE === $state['status'] ? __( 'Up to date: the last run is over.', 'otherguise' ) : __( 'Nothing was run yet.', 'otherguise' );
	}

	/**
	 * Prints one button of the run form.
	 *
	 * @param string $operation The operation.
	 * @param string $label     The label.
	 * @param bool   $primary   Whether it is the main button.
	 * @param bool   $disabled  Whether it is disabled.
	 * @param string $confirm   A question to ask first.
	 * @return void
	 */
	private function print_run_button( $operation, $label, $primary, $disabled, $confirm = '' ) {
		echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' === $confirm ? '' : ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' ) . '>';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RUN ) . '" /><input type="hidden" name="op" value="' . esc_attr( $operation ) . '" />';
		echo '<button class="button' . ( $primary ? ' button-primary' : '' ) . '"' . ( $disabled ? ' disabled' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
	}

	/**
	 * Prints the form of what is on.
	 *
	 * @return void
	 */
	private function print_features() {
		$features = (array) ( $this->read )();
		$sources  = (array) ( $features['sources'] ?? array() );
		$labels   = array(
			'src_categories' => __( 'The categories', 'otherguise' ),
			'src_tags'       => __( 'The tags', 'otherguise' ),
			'src_page'       => __( 'The pages: the marks (data-index, in the text or in JSON) and the links to Wikipedia', 'otherguise' ),
			'src_photos'     => __( 'The photographs and the tracks (they need the pages, which are turned on with them)', 'otherguise' ),
		);

		echo '<h2>' . esc_html__( 'What is on', 'otherguise' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '" />';
		echo '<h3>' . esc_html__( 'Sources', 'otherguise' ) . '</h3>';

		foreach ( self::SOURCES as $name => $slug ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( in_array( $slug, $sources, true ), true, false ) . ' /> ' . esc_html( $labels[ $name ] ) . '</label></p>';
		}

		echo '<h3>' . esc_html__( 'Wikidata', 'otherguise' ) . '</h3>';
		echo '<p><label><input type="checkbox" name="enrich" value="1"' . checked( ! empty( $features['enrich'] ), true, false ) . ' /> ' . esc_html__( 'Enrich the topics (identifier, label, kind) from Wikidata', 'otherguise' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Contact sent to Wikimedia', 'otherguise' ) . ' <input name="contact" size="40" value="' . esc_attr( (string) ( $features['contact'] ?? '' ) ) . '" /></label></p>';

		if ( ! empty( $features['enrich'] ) && '' === trim( (string) ( $features['contact'] ?? '' ) ) ) {
			echo '<p><em>' . esc_html__( 'No contact: the address of the site is sent. Wikimedia asks for a way to reach the operator (an e-mail address or a page).', 'otherguise' ) . '</em></p>';
		}

		echo '<h3>' . esc_html__( 'Night run', 'otherguise' ) . '</h3>';
		echo '<p><label><input type="checkbox" name="auto" value="1"' . checked( ! empty( $features['auto'] ), true, false ) . ' /> ' . esc_html__( 'Every night, read the posts that changed, enrich and clean up', 'otherguise' ) . '</label></p>';

		echo '<p><button class="button button-primary">' . esc_html__( 'Save', 'otherguise' ) . '</button></p></form>';
	}
}
