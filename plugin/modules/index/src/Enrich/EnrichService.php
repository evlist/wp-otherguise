<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The WordPress side of the Wikidata enrichment.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Enrich;

use Otherguise\Index\Topics;
use Otherguise\Index\Job\PagesJob;
use Otherguise\Index\Mentions;
use Otherguise\Index\Wikidata\Client;
use Otherguise\Index\Wikidata\ClassTable;
use Otherguise\Index\Wikidata\ItemReader;
use Otherguise\Index\Wikidata\Options;
use Otherguise\Index\Wikidata\TitleResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the pieces of the enrichment on WordPress (slice 604): the client with its rules of good use, the enricher on the topics and the
 * mentions, the job on the option and the cron, and the switch. **Off until enabled** (`index_enrich`): a site that did not ask sends nothing.
 */
final class EnrichService {
	public const TICK_HOOK = 'otherguise_index_enrich';
	public const SWITCH    = 'index_enrich';
	public const JOB       = 'index_enrich_job';

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * Builds the service.
	 *
	 * @param Topics   $topics  The topics.
	 * @param Mentions $mentions The mentions.
	 */
	public function __construct( Topics $topics, Mentions $mentions ) {
		$this->topics  = $topics;
		$this->mentions = $mentions;
	}

	/**
	 * Whether the enrichment is on.
	 *
	 * @return bool
	 */
	public function is_on() {
		return '1' === (string) get_option( self::SWITCH, '' );
	}

	/**
	 * Turns the enrichment on or off.
	 *
	 * @param bool $on On or off.
	 * @return true
	 */
	public function set_on( $on ) {
		update_option( self::SWITCH, $on ? '1' : '0' );

		return true;
	}

	/**
	 * The table of classes.
	 *
	 * @return ClassTable
	 */
	public function classes() {
		return new ClassTable( new Options() );
	}

	/**
	 * The reader of items (also used to print the labels of the table).
	 *
	 * @return ItemReader
	 */
	public function items() {
		return new ItemReader( $this->client() );
	}

	/**
	 * The client, with the rules of good use.
	 *
	 * @return Client
	 */
	public function client() {
		return new Client( new Options(), null, null, defined( 'OTHERGUISE_VERSION' ) ? (string) OTHERGUISE_VERSION : '0' );
	}

	/**
	 * The enricher.
	 *
	 * @return Enricher
	 */
	public function enricher() {
		$client = $this->client();

		return new Enricher(
			$this->topics,
			new TitleResolver( $client ),
			new ItemReader( $client ),
			$this->classes(),
			new Options(),
			fn( $id ) => array() !== $this->mentions->of_topic( (int) $id ),
			substr( (string) get_locale(), 0, 2 )
		);
	}

	/**
	 * Enriches one batch, for the job: refused when the enrichment is off.
	 *
	 * @param int $unit Ignored: the unit is the next batch of the queue.
	 * @return array{ok:bool,stop:bool,message:string,processed:int}
	 */
	public function process( $unit ) {
		unset( $unit );

		if ( ! $this->is_on() ) {
			return array(
				'ok'        => false,
				'stop'      => true,
				'message'   => 'The enrichment is off (wp otherguise index enrich enable).',
				'processed' => 0,
			);
		}

		$done = $this->enricher()->run_batch();

		return array(
			'ok'        => $done['ok'],
			'stop'      => $done['stop'],
			'message'   => $done['message'],
			'processed' => $done['processed'],
		);
	}

	/**
	 * The job, on the option and the cron: its units are batches of the queue, which changes as topics are done.
	 *
	 * @param bool $foreground Whether it runs in the foreground: it schedules nothing.
	 * @return PagesJob
	 */
	public function job( $foreground ) {
		return new PagesJob(
			static function () {
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( self::JOB, 'options' );

				return get_option( self::JOB, array() );
			},
			static function ( array $state ) {
				update_option( self::JOB, $state, false );
			},
			fn() => array() === $this->enricher()->pending( 1 ) ? array() : array( 1 ),
			fn() => count( $this->enricher()->pending( 100000 ) ),
			array( $this, 'process' ),
			static function ( $delay ) use ( $foreground ) {
				if ( ! $foreground && false === wp_next_scheduled( self::TICK_HOOK ) ) {
					wp_schedule_single_event( time() + (int) $delay, self::TICK_HOOK );
				}
			}
		);
	}

	/**
	 * A tick of the job. Action `otherguise_index_enrich`.
	 *
	 * @return void
	 */
	public function tick() {
		$this->job( false )->tick( time() );
	}
}
