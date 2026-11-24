<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The failures of the services of Wikimedia, and the pause that follows.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Enrich;

use Otherguise\Index\Wikidata\Options;

defined( 'ABSPATH' ) || exit;

/**
 * What the enrichment does when a service does not answer (slices 604 and 627): a refusal that says "wait" pauses the enrichment until the date given
 * (never less than an exponential delay, 5 minutes at most); three failures in a row stop it. **The same counters serve the enrichment of the topics and
 * the enrichment before the creation of a topic**: a service that asks to wait is waited for by both.
 */
final class Failures {
	public const STOP_AFTER  = 3;
	public const MAX_BACKOFF = 300;

	/**
	 * The options (failures, pause).
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * Returns the time in seconds.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Builds the service.
	 *
	 * @param Options       $options The options.
	 * @param callable|null $now     Default `time`.
	 */
	public function __construct( Options $options, $now = null ) {
		$this->options = $options;
		$this->now     = $now ?? 'time';
	}

	/**
	 * The date until which the enrichment is paused, 0 when it is not.
	 *
	 * @return int
	 */
	public function paused_until() {
		$until = (int) $this->options->get( 'index_enrich_pause_until', 0 );

		return $until > (int) ( $this->now )() ? $until : 0;
	}

	/**
	 * Forgets the failures: a request worked.
	 *
	 * @return void
	 */
	public function succeeded() {
		$this->options->set( 'index_enrich_failures', 0 );
		$this->options->set( 'index_enrich_pause_until', 0 );
	}

	/**
	 * Counts a failure and decides whether to stop.
	 *
	 * @param array<string, mixed> $response The answer of the client.
	 * @return array{stop:bool,message:string}
	 */
	public function failed( array $response ) {
		$failures = (int) $this->options->get( 'index_enrich_failures', 0 ) + 1;
		$retry    = 'retry' === $response['class'];
		$stop     = $retry || 'fatal' === $response['class'] || $failures >= self::STOP_AFTER;
		$message  = (string) $response['error'];

		$this->options->set( 'index_enrich_failures', $failures );

		if ( $retry ) {
			$wait  = max( (int) $response['retry_after'], min( self::MAX_BACKOFF, 5 * ( 2 ** ( $failures - 1 ) ) ) );
			$until = (int) ( $this->now )() + $wait;

			$this->options->set( 'index_enrich_pause_until', $until );
			$message .= ' Paused until ' . gmdate( 'Y-m-d H:i:s', $until ) . ' UTC.';
		} elseif ( $stop ) {
			$message .= ' Stopped after ' . $failures . ' failure(s) in a row.';
		}

		return array(
			'stop'    => $stop,
			'message' => $message,
		);
	}
}
