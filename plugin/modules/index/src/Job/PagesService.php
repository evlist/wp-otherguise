<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Reads a post through its web page, and the hooks of the job, on WordPress.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Job;

use Otherguise\Index\Harvest\PageFetcher;
use Otherguise\Index\Harvest\PageReader;
use Otherguise\Index\Harvest\Signal;
use Otherguise\Index\Harvest\SignalStore;
use Otherguise\Index\Source\Page;

defined( 'ABSPATH' ) || exit;

/**
 * The WordPress side of slice 602: reading one post (fetch, read, keep, index), the job built on the options and the cron, the event
 * scheduled when a post is saved, and the switch of the source.
 */
final class PagesService {
	public const TICK_HOOK   = 'otherguise_index_pages';
	public const SINGLE_HOOK = 'otherguise_index_page';
	public const OPTION      = 'index_pages_job';
	public const SOURCES     = 'index_sources';
	public const SAVE_DELAY  = 30;

	/**
	 * Returns the indexer: `fn(): Indexer`.
	 *
	 * @var callable
	 */
	private $indexer;

	/**
	 * Returns the post types that are indexed: `fn(): string[]`.
	 *
	 * @var callable
	 */
	private $post_types;

	/**
	 * Returns the ids of the published posts by page: `fn( int $offset, int $limit ): int[]`.
	 *
	 * @var callable
	 */
	private $posts;

	/**
	 * The fetcher.
	 *
	 * @var PageFetcher
	 */
	private $fetcher;

	/**
	 * The reader.
	 *
	 * @var PageReader
	 */
	private $reader;

	/**
	 * The store of the signals.
	 *
	 * @var SignalStore
	 */
	private $store;

	/**
	 * What to do with the signals of a page that was read: `fn( int $post_id, Signal[] $signals ): void` (slice 606: the photographs).
	 *
	 * @var callable|null
	 */
	private $on_read;

	/**
	 * Builds the service.
	 *
	 * @param callable      $indexer    Returns the indexer.
	 * @param callable      $post_types Returns the post types.
	 * @param callable      $posts      Ids of the published posts, by page.
	 * @param PageFetcher   $fetcher    The fetcher.
	 * @param PageReader    $reader     The reader.
	 * @param SignalStore   $store      The store.
	 * @param callable|null $on_read  Receives the signals of a page that was read.
	 */
	public function __construct( $indexer, $post_types, $posts, PageFetcher $fetcher, PageReader $reader, SignalStore $store, $on_read = null ) {
		$this->on_read    = $on_read;
		$this->indexer    = $indexer;
		$this->post_types = $post_types;
		$this->posts      = $posts;
		$this->fetcher    = $fetcher;
		$this->reader     = $reader;
		$this->store      = $store;
	}

	/**
	 * Whether the source `page` is on.
	 *
	 * @return bool
	 */
	public function is_on() {
		$indexer = ( $this->indexer )();

		return in_array( Page::SLUG, $indexer->source_ids(), true );
	}

	/**
	 * Reads one post through its web page.
	 *
	 * @param int $post_id Post.
	 * @return array{ok:bool,message:string}
	 */
	public function process( $post_id ) {
		if ( ! $this->is_on() ) {
			return array(
				'ok'      => false,
				'message' => 'The source page is off (wp otherguise index pages enable).',
			);
		}

		if ( 'publish' !== get_post_status( (int) $post_id ) ) {
			return array(
				'ok'      => false,
				'message' => 'The post is not published.',
			);
		}

		$page = $this->fetcher->fetch( (int) $post_id );

		if ( '' !== $page['error'] ) {
			return array(
				'ok'      => false,
				'message' => $page['error'],
			);
		}

		$signals = $this->reader->signals( $page['html'], (int) $post_id );

		if ( ! $this->store->save( (int) $post_id, $signals ) ) {
			$this->store->touch( (int) $post_id );
		}

		$indexer = ( $this->indexer )();
		$indexer->index_post( (int) $post_id, array( Page::SLUG ) );

		if ( null !== $this->on_read ) {
			( $this->on_read )( (int) $post_id, $signals );
		}

		return array(
			'ok'      => true,
			'message' => count( $signals ) . ' signal(s)',
		);
	}

	/**
	 * What the page of a post says, without keeping anything (the dry run of slice 606).
	 *
	 * @param int $post_id Post.
	 * @return array{signals:Signal[],error:string}
	 */
	public function read( $post_id ) {
		$page = $this->fetcher->fetch( (int) $post_id );

		return array(
			'signals' => '' === $page['error'] ? $this->reader->signals( $page['html'], (int) $post_id ) : array(),
			'error'   => (string) $page['error'],
		);
	}

	/**
	 * The job, on the option and the cron.
	 *
	 * @param bool $foreground Whether it runs in the foreground: it schedules nothing.
	 * @return PagesJob
	 */
	public function job( $foreground ) {
		return new PagesJob(
			static function () {
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( self::OPTION, 'options' );

				return get_option( self::OPTION, array() );
			},
			static function ( array $state ) {
				update_option( self::OPTION, $state, false );
			},
			$this->posts,
			function () {
				return count( (array) ( $this->posts )( 0, 100000 ) );
			},
			array( $this, 'process' ),
			static function ( $delay ) use ( $foreground ) {
				if ( ! $foreground && false === wp_next_scheduled( self::TICK_HOOK ) ) {
					wp_schedule_single_event( time() + (int) $delay, self::TICK_HOOK );
				}
			}
		);
	}

	/**
	 * A tick of the job. Action `otherguise_index_pages`.
	 *
	 * @return void
	 */
	public function tick() {
		$this->job( false )->tick( time() );
	}

	/**
	 * Reads one post, from the event scheduled when it was saved. Action `otherguise_index_page`.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public function single( $post_id ) {
		$this->process( (int) $post_id );
	}

	/**
	 * A post was saved: its page will be read in a little while, not during the save. Action `wp_after_insert_post`.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public function on_save( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) || ! in_array( get_post_type( $post_id ), (array) ( $this->post_types )(), true ) || ! $this->is_on() ) {
			return;
		}

		if ( false === wp_next_scheduled( self::SINGLE_HOOK, array( $post_id ) ) ) {
			wp_schedule_single_event( time() + self::SAVE_DELAY, self::SINGLE_HOOK, array( $post_id ) );
		}
	}

	/**
	 * Turns the source on or off.
	 *
	 * @param bool $on On or off.
	 * @return true
	 */
	public function set_source( $on ) {
		$sources = get_option( self::SOURCES, array( 'categories', 'tags' ) );
		$sources = is_array( $sources ) ? $sources : array( 'categories', 'tags' );
		$sources = array_values( array_diff( $sources, array( Page::SLUG ) ) );

		if ( $on ) {
			$sources[] = Page::SLUG;
		}

		update_option( self::SOURCES, $sources );

		return true;
	}
}
