<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The synchronisation of the index, built on WordPress.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Job;

use Otherguise\Index\Cli\SyncCommand;
use Otherguise\Index\Harvest\SignalStore;
use Otherguise\Index\Indexer;
use Otherguise\Index\Enrich\EnrichService;
use Otherguise\Index\Mentions;
use Otherguise\Index\Merge\TopicPruner;
use Otherguise\Index\Source\Photos;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `SyncService` with the options, the cron and the services of the module (slice 621), and registers its events and its command.
 */
final class SyncWiring {
	public const STATE = 'index_sync';
	public const JOB   = 'index_sync_job';
	public const IDS   = 'index_sync_ids';

	/**
	 * Builds the service.
	 *
	 * @param callable      $indexer   Returns the indexer.
	 * @param PagesService  $pages     The reading of the pages.
	 * @param EnrichService $enrich   The enrichment.
	 * @param TopicPruner   $pruner    The cleaning of the topics.
	 * @param Mentions      $mentions  The mentions.
	 * @param callable      $published The ids of the published posts, by page: `fn( int $offset, int $limit ): int[]`.
	 * @return SyncService
	 */
	public static function build( $indexer, PagesService $pages, EnrichService $enrich, TopicPruner $pruner, Mentions $mentions, $published ) {
		$option = static function ( $name ) {
			return static function () use ( $name ) {
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( $name, 'options' );

				$value = get_option( $name, array() );

				return is_array( $value ) ? $value : array();
			};
		};
		$store  = static function ( $name ) {
			return static function ( array $value ) use ( $name ) {
				update_option( $name, $value, false );
			};
		};
		$all    = static function () use ( $published ) {
			$ids = array();

			for ( $offset = 0; ; $offset += 500 ) {
				$page = (array) $published( $offset, 500 );
				$ids  = array_merge( $ids, $page );

				if ( count( $page ) < 500 ) {
					return array_map( 'intval', $ids );
				}
			}
		};

		return new SyncService(
			array(
				'load'            => $option( self::STATE ),
				'save'            => $store( self::STATE ),
				'load_job'        => $option( self::JOB ),
				'save_job'        => $store( self::JOB ),
				'load_ids'        => $option( self::IDS ),
				'save_ids'        => $store( self::IDS ),
				'all_posts'       => $all,
				'stale_posts'     => static fn() => self::stale( $all(), $pages->is_on() ),
				'collect'         => static function ( $id ) use ( $indexer, $pages ) {
					$sources = $indexer();
					$terms   = array_values( array_diff( $sources->source_ids(), array( 'page', Photos::SLUG ) ) );

					if ( array() !== $terms ) {
						$sources->index_post( (int) $id, $terms );
					}

					return $pages->is_on() ? $pages->process( (int) $id ) : array( 'ok' => true );
				},
				'enrich_job'      => static fn() => $enrich->is_on() ? $enrich->job( true ) : null,
				'cleanup'         => static fn() => count( $pruner->prune( array( 'any' ), false ) ),
				'purge'           => static fn() => $mentions->drop_source( 'post' )['mentions'] + $mentions->drop_source( 'image' )['mentions'] + $mentions->drop_source( 'trace' )['mentions'],
				'schedule'        => static function ( $delay ) {
					if ( false === wp_next_scheduled( SyncService::TICK_HOOK ) ) {
						wp_schedule_single_event( time() + (int) $delay, SyncService::TICK_HOOK );
					}
				},
				'auto_on'         => static fn() => '0' !== (string) get_option( SyncService::AUTO, '1' ),
				'schedule_auto'   => static function () {
					if ( false === wp_next_scheduled( SyncService::AUTO_HOOK ) ) {
						// At night, at a time that is not on the hour: the sites do not all start together.
						$next = strtotime( 'tomorrow 03:17 UTC' );

						wp_schedule_event( false === $next ? time() + HOUR_IN_SECONDS : $next, 'daily', SyncService::AUTO_HOOK );
					}
				},
				'unschedule_auto' => static function () {
					wp_clear_scheduled_hook( SyncService::AUTO_HOOK );
				},
				'now'             => 'time',
			)
		);
	}

	/**
	 * The posts that changed since they were read: never read, or read before they were modified; when the pages are not read, the posts modified since
	 * the last run started.
	 *
	 * @param int[] $ids       The published posts.
	 * @param bool  $read_page Whether the pages are read.
	 * @return int[]
	 */
	private static function stale( array $ids, $read_page ) {
		$state = get_option( self::STATE, array() );
		$since = is_array( $state ) ? (int) ( $state['started'] ?? 0 ) : 0;
		$stale = array();

		foreach ( $ids as $id ) {
			$modified = (int) strtotime( (string) get_post_field( 'post_modified_gmt', (int) $id ) . ' UTC' );

			if ( $read_page ) {
				$harvest = explode( ' ', (string) get_post_meta( (int) $id, SignalStore::HARVEST, true ), 2 );

				if ( (int) $harvest[0] <= 0 || (int) $harvest[0] < $modified ) {
					$stale[] = (int) $id;
				}
			} elseif ( $since <= 0 || $modified >= $since ) {
				$stale[] = (int) $id;
			}
		}

		return $stale;
	}

	/**
	 * Removes the options and the events of the synchronisation. Called when the module is uninstalled.
	 *
	 * @return void
	 */
	public static function uninstall() {
		foreach ( array( self::STATE, self::JOB, self::IDS, SyncService::AUTO ) as $option ) {
			delete_option( $option );
		}

		wp_clear_scheduled_hook( SyncService::AUTO_HOOK );
		wp_clear_scheduled_hook( SyncService::TICK_HOOK );
	}

	/**
	 * Registers the events of the synchronisation. Called when the module boots.
	 *
	 * @param SyncService $sync       The service.
	 * @param callable    $add_action `add_action`, or a double.
	 * @return void
	 */
	public static function register( SyncService $sync, $add_action ) {
		$add_action( SyncService::TICK_HOOK, array( $sync, 'tick' ), 10, 0 );
		$add_action( SyncService::AUTO_HOOK, array( $sync, 'auto' ), 10, 0 );
		$add_action( 'init', array( $sync, 'ensure_scheduled' ), 20, 0 );
	}

	/**
	 * Registers the command `wp otherguise index sync`.
	 *
	 * @param SyncService $sync The service.
	 * @return void
	 */
	public static function register_command( SyncService $sync ) {
		\WP_CLI::add_command( 'otherguise index sync', new SyncCommand( $sync ) );
	}
}
