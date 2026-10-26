<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The background conversion of a post to PDF.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Pdf;

use Otherguise\Books\Render\Renderer;
use Otherguise\Books\Settings\PageFormat;
use Otherguise\Books\Settings\RendererSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Starts, runs and reads the conversion of a post to PDF by the rendering server (slice 220); a post that is not published is converted through its private link, as a preview (slice 403). A conversion takes tens of
 * seconds, so it runs as a WP-Cron event (`books_render_pdf`) and the editor asks for its state. The PDF is kept under a key made from
 * what it depends on (the post and its last change, the mode, the page format, the settings of the conversion): the same key is served
 * from the files without a new conversion. Nothing here calls WordPress: the post, the addresses, the scheduling and the renderer are
 * given as functions.
 */
final class PdfJobs {
	/**
	 * Name of the cron event.
	 */
	public const EVENT = 'books_render_pdf';

	/**
	 * Largest PDF, in bytes, that is sent back to the server to count its pages when the file itself could not tell (it has to be in
	 * memory for that).
	 */
	public const COUNT_BY_SERVER_MAX = 20971520;

	/**
	 * Seconds after which a conversion that says it is queued or running is taken for lost, and may be started again.
	 */
	public const STALE = 900;

	/**
	 * Settings of the server.
	 *
	 * @var RendererSettings
	 */
	private $settings;

	/**
	 * Format of the page.
	 *
	 * @var PageFormat
	 */
	private $format;

	/**
	 * Files.
	 *
	 * @var PdfStore
	 */
	private $store;

	/**
	 * States.
	 *
	 * @var JobState
	 */
	private $state;

	/**
	 * Receives a post id, whether to make a private link and whether to ask the capability of the current user (false in the job of WP-Cron, which has no user); returns what is known of a post: `status`, `modified`, `slug`, `url` (its permalink, or its private link when it is not published), `preview`, `available`, `allowed`; null when it does not exist.
	 *
	 * @var callable
	 */
	private $post_info;

	/**
	 * Tells whether a mode exists: receives its slug.
	 *
	 * @var callable
	 */
	private $mode_exists;

	/**
	 * Builds the address to convert: receives the permalink and the slug of the mode.
	 *
	 * @var callable
	 */
	private $url_for;

	/**
	 * Builds the renderer: receives the address of the server.
	 *
	 * @var callable
	 */
	private $make_renderer;

	/**
	 * Schedules the cron event: receives the post id, the mode and the key.
	 *
	 * @var callable
	 */
	private $schedule;

	/**
	 * Gives the time.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Replaces the beginning of the address to convert by the one by which the server reaches the site (slice 225): receives the address.
	 *
	 * @var callable
	 */
	private $reach;

	/**
	 * Receives a post id; returns the rules of the book project that covers it (`project` id, `format`, `css`), or null (slice 230).
	 *
	 * @var callable
	 */
	private $rules;

	/**
	 * Builds the jobs.
	 *
	 * @param RendererSettings $settings      Settings of the server.
	 * @param PageFormat       $format        Format of the page.
	 * @param PdfStore         $store         Files.
	 * @param JobState         $state         States.
	 * @param callable         $post_info     Receives a post id; returns `status`, `modified`, `slug`, `url`, or null.
	 * @param callable         $mode_exists   Receives a slug; tells whether the mode exists.
	 * @param callable         $url_for       Receives the permalink and the mode; returns the address to convert.
	 * @param callable         $make_renderer Receives the address of the server; returns a `Renderer`.
	 * @param callable         $schedule      Receives the post id, the mode and the key; schedules the event.
	 * @param callable|null    $now           Defaults to `time`.
	 * @param callable|null    $reach         Receives the address to convert; returns the address by which the server reaches it (the same by default).
	 * @param callable|null    $rules         Receives a post id; returns the rules of its book project (`project`, `format`, `css`) or null (none by default).
	 */
	public function __construct( RendererSettings $settings, PageFormat $format, PdfStore $store, JobState $state, $post_info, $mode_exists, $url_for, $make_renderer, $schedule, $now = null, $reach = null, $rules = null ) {
		$this->settings      = $settings;
		$this->format        = $format;
		$this->store         = $store;
		$this->state         = $state;
		$this->post_info     = $post_info;
		$this->mode_exists   = $mode_exists;
		$this->url_for       = $url_for;
		$this->make_renderer = $make_renderer;
		$this->schedule      = $schedule;
		$this->now           = $now ?? 'time';
		$this->reach         = $reach ?? static function ( $address ) {
			return $address;
		};
		$this->rules         = $rules ?? static function () {
			return null;
		};
	}

	/**
	 * Asks for the PDF of a post: serves the files when the same conversion was made, else queues it.
	 *
	 * @param int    $post_id Post.
	 * @param string $mode    Slug of the mode.
	 * @param bool   $force   Make a new conversion even when the files hold one.
	 * @return array<string, mixed>|\WP_Error The state.
	 */
	public function request( $post_id, $mode, $force = false ) {
		$check = $this->check( $post_id, $mode, true );

		if ( $check instanceof \WP_Error ) {
			return $check;
		}

		$key     = $this->key( $post_id, $mode, $check );
		$state   = $this->state->get( $post_id, $mode );
		$preview = ! empty( $check['preview'] );

		if ( ! $force && $this->store->has( $key ) ) {
			if ( ( $state['key'] ?? '' ) !== $key || 'done' !== ( $state['status'] ?? '' ) ) {
				$state = $this->fresh( $key, 'done', $preview );
				$this->state->set( $post_id, $mode, $state );
			}

			return $this->present( $state );
		}

		if ( ! $force && ( $state['key'] ?? '' ) === $key && in_array( $state['status'] ?? '', array( 'queued', 'running' ), true ) && ! $this->is_stale( $state ) ) {
			return $this->present( $state );
		}

		$state = $this->fresh( $key, 'queued', $preview );
		$this->state->set( $post_id, $mode, $state );
		( $this->schedule )( $post_id, $mode, $key );

		return $this->present( $state );
	}

	/**
	 * Gives the state of the conversion of a post for what it is now.
	 *
	 * @param int    $post_id Post.
	 * @param string $mode    Slug of the mode.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function status( $post_id, $mode ) {
		$check = $this->check( $post_id, $mode );

		if ( $check instanceof \WP_Error ) {
			return $check;
		}

		$key     = $this->key( $post_id, $mode, $check );
		$state   = $this->state->get( $post_id, $mode );
		$preview = ! empty( $check['preview'] );

		if ( ( $state['key'] ?? '' ) !== $key || ( 'done' === ( $state['status'] ?? '' ) && ! $this->store->has( $key ) ) ) {
			return $this->present( $this->store->has( $key ) ? $this->fresh( $key, 'done', $preview ) : $this->fresh( $key, 'none', $preview ) );
		}

		if ( in_array( $state['status'] ?? '', array( 'queued', 'running' ), true ) && $this->is_stale( $state ) ) {
			$state['status']  = 'failed';
			$state['message'] = __( 'The conversion did not finish: is WP-Cron running?', 'otherguise' );
		}

		return $this->present( $state );
	}

	/**
	 * Runs the conversion. Action `books_render_pdf` of WP-Cron.
	 *
	 * @param int    $post_id Post.
	 * @param string $mode    Slug of the mode.
	 * @param string $key     Key of the conversion that was queued.
	 * @return void
	 */
	public function run( $post_id, $mode, $key ) {
		$state = $this->state->get( $post_id, $mode );

		if ( ( $state['key'] ?? '' ) !== $key || 'queued' !== ( $state['status'] ?? '' ) ) {
			return;
		}

		$state['status']     = 'running';
		$state['started_at'] = ( $this->now )();
		$this->state->set( $post_id, $mode, $state );

		$info = ( $this->post_info )( $post_id, true, false );

		if ( ! is_array( $info ) || ( 'publish' !== ( $info['status'] ?? '' ) && empty( $info['available'] ) ) ) {
			$this->fail( $post_id, $mode, $state, __( 'The post is not published, and it cannot have a private link.', 'otherguise' ) );
			return;
		}

		if ( '' === (string) ( $info['url'] ?? '' ) ) {
			$this->fail( $post_id, $mode, $state, __( 'The private link of the post could not be made.', 'otherguise' ) );
			return;
		}

		$settings = $this->settings->get();
		$renderer = ( $this->make_renderer )( $settings[ RendererSettings::URL ] );

		if ( '' === $settings[ RendererSettings::URL ] || ! $renderer instanceof Renderer ) {
			$this->fail( $post_id, $mode, $state, __( 'No rendering server is configured.', 'otherguise' ) );
			return;
		}

		$part = $this->store->partial_path( $key );

		if ( '' === $part ) {
			$this->fail( $post_id, $mode, $state, __( 'The PDF could not be written in the uploads directory.', 'otherguise' ) );
			return;
		}

		$rules   = ( $this->rules )( $post_id );
		$url     = ( $this->url_for )( $info['url'], $mode );
		$started = microtime( true );
		$result  = $renderer->convert_url_to_file(
			( $this->reach )( is_array( $rules ) ? add_query_arg( array( 'books_project' => (int) $rules['project'] ), $url ) : $url ),
			$this->format_for( $rules ),
			array(
				'prefer_css_page_size' => is_array( $rules ),
				'media'           => $settings[ RendererSettings::MEDIA ],
				'wait_delay'      => $settings[ RendererSettings::WAIT_DELAY ],
				'wait_expression' => $settings[ RendererSettings::WAIT_EXPRESSION ],
				'wait_selector'   => $settings[ RendererSettings::WAIT_SELECTOR ],
				'timeout'         => 300,
			),
			$part
		);

		\Otherguise\Books\Merge\MergeLog::write(
			'pdf_convert',
			array(
				'post'    => (int) $post_id,
				'mode'    => $mode,
				'seconds' => round( microtime( true ) - $started, 2 ),
				'ok'      => ! ( $result instanceof \WP_Error ),
				'error'   => $result instanceof \WP_Error ? $result->get_error_message() : '',
				'pdf_kb'  => $result instanceof \WP_Error ? 0 : (int) round( (int) filesize( $part ) / 1024 ),
			)
		);

		if ( $result instanceof \WP_Error ) {
			$this->store->discard( $key );
			$this->fail( $post_id, $mode, $state, $result->get_error_message() );
			return;
		}

		if ( ! $this->store->commit( $key ) ) {
			$this->fail( $post_id, $mode, $state, __( 'The PDF could not be written in the uploads directory.', 'otherguise' ) );
			return;
		}

		$state['status']      = 'done';
		$state['message']     = '';
		$state['pages']       = $this->count_pages( $renderer, $key );
		$state['finished_at'] = ( $this->now )();
		$this->state->set( $post_id, $mode, $state );
	}

	/**
	 * Says where the PDF of a post is, for the route that sends it: the path of its file, its name for the browser, its number of pages.
	 * The bytes are not read here.
	 *
	 * @param int    $post_id Post.
	 * @param string $mode    Slug of the mode.
	 * @return array{path:string, filename:string, pages:int|null, preview:bool, size:int}|\WP_Error
	 */
	public function download( $post_id, $mode ) {
		$check = $this->check( $post_id, $mode );

		if ( $check instanceof \WP_Error ) {
			return $check;
		}

		$key = $this->key( $post_id, $mode, $check );

		if ( ! $this->store->has( $key ) ) {
			return new \WP_Error( 'books_pdf_not_ready', __( 'The PDF is not ready.', 'otherguise' ), array( 'status' => 409 ) );
		}

		$state = $this->state->get( $post_id, $mode );
		$name  = trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( (string) $check['slug'] ) ), '-' );

		return array(
			'path'     => $this->store->path( $key ),
			'filename' => ( '' !== $name ? $name : 'post-' . (int) $post_id ) . ( ! empty( $check['preview'] ) ? '-preview' : '' ) . '.pdf',
			'pages'    => ( $state['key'] ?? '' ) === $key && isset( $state['pages'] ) ? $state['pages'] : null,
			'preview'  => ! empty( $check['preview'] ),
			'size'     => $this->store->size( $key ),
		);
	}

	/**
	 * Counts the pages of a PDF file: by reading the file, which keeps a large PDF out of memory; by asking the server for a small one
	 * when the file has no page object that can be read.
	 *
	 * @param Renderer $renderer The renderer.
	 * @param string   $key      Key of the file.
	 * @return int|null
	 */
	private function count_pages( Renderer $renderer, $key ) {
		$path  = $this->store->path( $key );
		$pages = PdfPages::count( $path );

		if ( null !== $pages || $this->store->size( $key ) > self::COUNT_BY_SERVER_MAX ) {
			return $pages;
		}

		$bytes = file_get_contents( $path );

		return false === $bytes ? null : $renderer->page_count( $bytes );
	}

	/**
	 * Deletes the files that are older than the number of days of the settings. Daily cron event.
	 *
	 * @return int Number of files deleted.
	 */
	public function cleanup() {
		return $this->store->cleanup( (int) $this->settings->get()[ RendererSettings::KEEP_DAYS ] );
	}

	/**
	 * Checks that a conversion can be asked for: a server, an existing mode, a published post or a post that can have a private link that the user may make
	 * (slice 403: it is converted as a preview). Returns what is known of the post.
	 *
	 * @param int    $post_id Post.
	 * @param string $mode    Slug of the mode.
	 * @param bool   $create  Make the private link of a post that is not published (only when the conversion is asked for).
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check( $post_id, $mode, $create = false ) {
		if ( ! $this->settings->is_configured() ) {
			return new \WP_Error( 'books_pdf_disabled', __( 'No rendering server is configured.', 'otherguise' ), array( 'status' => 501 ) );
		}

		if ( ! is_string( $mode ) || ! ( $this->mode_exists )( $mode ) ) {
			return new \WP_Error( 'books_pdf_unknown_mode', __( 'This mode does not exist.', 'otherguise' ), array( 'status' => 400 ) );
		}

		$info = ( $this->post_info )( $post_id, $create );

		if ( ! is_array( $info ) ) {
			return new \WP_Error( 'books_pdf_not_found', __( 'This post does not exist.', 'otherguise' ), array( 'status' => 404 ) );
		}

		if ( 'publish' !== ( $info['status'] ?? '' ) && empty( $info['available'] ) ) {
			return new \WP_Error( 'books_pdf_not_published', __( 'Only a published post can be converted to PDF, unless it can have a private link (the Access module, with the private links switched on).', 'otherguise' ), array( 'status' => 400 ) );
		}

		if ( 'publish' !== ( $info['status'] ?? '' ) && empty( $info['allowed'] ?? true ) ) {
			return new \WP_Error( 'books_pdf_link_forbidden', __( 'You may not make the private link that the PDF of a post that is not published needs (Settings, Otherguise access).', 'otherguise' ), array( 'status' => 403 ) );
		}

		if ( $create && '' === (string) ( $info['url'] ?? '' ) ) {
			return new \WP_Error( 'books_pdf_no_link', __( 'The private link of this post could not be made.', 'otherguise' ), array( 'status' => 400 ) );
		}

		return $info;
	}

	/**
	 * The key of the cache: what the PDF depends on.
	 *
	 * @param int                  $post_id Post.
	 * @param string               $mode    Slug of the mode.
	 * @param array<string, mixed> $info    What is known of the post.
	 * @return string
	 */
	private function key( $post_id, $mode, array $info ) {
		$settings = $this->settings->get();

		unset( $settings[ RendererSettings::URL ], $settings[ RendererSettings::KEEP_DAYS ] );

		$rules = ( $this->rules )( $post_id );

		$parts = array(
			'version'  => 1,
			'post'     => (int) $post_id,
			'modified' => (string) ( $info['modified'] ?? '' ),
			'mode'     => $mode,
			'preview'  => ! empty( $info['preview'] ),
			'format'   => $this->format_for( $rules ),
			'settings' => $settings,
		);

		// Added only for a book project, so that the PDF files of the posts that have none keep their key.
		if ( is_array( $rules ) ) {
			$parts['rules'] = array( (int) $rules['project'], md5( (string) $rules['css'] ) );
		}

		return md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * The format of the page: the one of the book project that covers the post, else the one of the settings.
	 *
	 * @param array<string, mixed>|null $rules The rules of the project, or null.
	 * @return array<string, float>
	 */
	private function format_for( $rules ) {
		return is_array( $rules ) && is_array( $rules['format'] ?? null ) ? $rules['format'] : $this->format->get();
	}

	/**
	 * Builds a state.
	 *
	 * @param string $key     Key.
	 * @param string $status  Status.
	 * @param bool   $preview The post is not published: the PDF is a preview.
	 * @return array<string, mixed>
	 */
	private function fresh( $key, $status, $preview = false ) {
		return array(
			'status'    => $status,
			'key'       => $key,
			'message'   => '',
			'pages'     => null,
			'preview'   => (bool) $preview,
			'queued_at' => ( $this->now )(),
		);
	}

	/**
	 * Marks a conversion as failed.
	 *
	 * @param int                  $post_id Post.
	 * @param string               $mode    Slug of the mode.
	 * @param array<string, mixed> $state   State.
	 * @param string               $message Message.
	 * @return void
	 */
	private function fail( $post_id, $mode, array $state, $message ) {
		$state['status']      = 'failed';
		$state['message']     = (string) $message;
		$state['finished_at'] = ( $this->now )();
		$this->state->set( $post_id, $mode, $state );
	}

	/**
	 * Tells whether a queued or running conversion is taken for lost.
	 *
	 * @param array<string, mixed> $state State.
	 * @return bool
	 */
	private function is_stale( array $state ) {
		$since = (int) ( $state['started_at'] ?? $state['queued_at'] ?? 0 );

		return ( $this->now )() - $since > self::STALE;
	}

	/**
	 * What is sent to the editor: the state without the key, with the seconds elapsed.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private function present( array $state ) {
		$from = (int) ( $state['started_at'] ?? $state['queued_at'] ?? ( $this->now )() );
		$to   = (int) ( $state['finished_at'] ?? ( $this->now )() );

		return array(
			'status'  => (string) ( $state['status'] ?? 'none' ),
			'message' => (string) ( $state['message'] ?? '' ),
			'pages'   => isset( $state['pages'] ) ? (int) $state['pages'] : null,
			'preview' => ! empty( $state['preview'] ),
			'elapsed' => max( 0, $to - $from ),
		);
	}
}
