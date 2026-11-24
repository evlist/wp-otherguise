<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The enrichment of a designation, before the topic is made.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Enrich;

use Otherguise\Index\Candidate;
use Otherguise\Index\Designations;
use Otherguise\Index\Wikidata\ItemReader;
use Otherguise\Index\Wikidata\TitleResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Asks Wikidata about a designation that carries a link to Wikipedia **before the topic is made** (slice 627): the item (`wikidata:Q…`), its label and the
 * kind that its classes give. **Once per designation, not once per occurrence**: the answer is kept with the designation, and a link repeated in fifty posts
 * costs one question. **No topic is created unenriched**: while the service asks to wait, the designation stays `pending`.
 */
final class DesignationEnricher {
	public const BATCH      = 50;
	public const RETRY_DAYS = 7;

	/**
	 * The questions to the services.
	 *
	 * @var Lookup
	 */
	private $lookup;

	/**
	 * The failures and the pause.
	 *
	 * @var Failures
	 */
	private $failures;

	/**
	 * The designations.
	 *
	 * @var Designations
	 */
	private $designations;

	/**
	 * Returns the time in seconds.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Returns a random integer between two bounds.
	 *
	 * @var callable
	 */
	private $random;

	/**
	 * Builds the service.
	 *
	 * @param Lookup        $lookup       The questions to the services.
	 * @param Failures      $failures     The failures and the pause.
	 * @param Designations  $designations The designations.
	 * @param callable|null $now          Default `time`.
	 * @param callable|null $random       Default `wp_rand`.
	 */
	public function __construct( Lookup $lookup, Failures $failures, Designations $designations, $now = null, $random = null ) {
		$this->lookup       = $lookup;
		$this->failures     = $failures;
		$this->designations = $designations;
		$this->now          = $now ?? 'time';
		$this->random       = $random ?? 'wp_rand';
	}

	/**
	 * The title of Wikipedia that a candidate carries, when it has no identifier of Wikidata: what the enrichment can look up.
	 *
	 * @param array<int, string> $keys The keys of a candidate.
	 * @return array{type:string,language:string,title:string,qid:string}|null
	 */
	public static function source_of( array $keys ) {
		$found = null;

		foreach ( $keys as $key ) {
			if ( 0 === strpos( $key, 'wikidata:' ) ) {
				return null;
			}

			if ( null === $found && 0 === strpos( $key, 'wikipedia:' ) ) {
				$parts = explode( ':', $key, 3 );

				if ( 3 === count( $parts ) && TitleResolver::valid_language( $parts[1] ) && '' !== $parts[2] ) {
					$found = array(
						'type'     => 'wikipedia',
						'language' => $parts[1],
						'title'    => $parts[2],
						'qid'      => '',
					);
				}
			}
		}

		return $found;
	}

	/**
	 * Whether the designation was looked at and does not need to be again for now: an answer, or a "not found" that is not due.
	 *
	 * @param int $id Designation.
	 * @return bool
	 */
	public function known( $id ) {
		$state = $this->designations->enrichment( (int) $id );

		return array() !== $state && ( 'done' === $state['state'] || (int) ( $state['due'] ?? 0 ) > (int) ( $this->now )() );
	}

	/**
	 * Whether the enrichment is paused (a service asked to wait).
	 *
	 * @return bool
	 */
	public function paused() {
		return $this->failures->paused_until() > 0;
	}

	/**
	 * Asks about designations and keeps the answers. **Nothing is kept for a batch whose requests failed.**
	 *
	 * @param array<int, array{type:string,language:string,title:string,qid:string}> $sources By designation.
	 * @return array{ok:bool,stop:bool,message:string,done:array<int,array<string,mixed>>}
	 */
	public function look_up( array $sources ) {
		$until = $this->failures->paused_until();

		if ( $until > 0 ) {
			return array(
				'ok'      => false,
				'stop'    => true,
				'message' => 'Paused until ' . gmdate( 'Y-m-d H:i:s', $until ) . ' UTC (the service asked to wait).',
				'done'    => array(),
			);
		}

		$done = array();

		foreach ( array_chunk( $sources, self::BATCH, true ) as $chunk ) {
			$asked = $this->lookup->run( $chunk );

			if ( ! $asked['ok'] ) {
				$failed = $this->failures->failed( $asked['response'] );

				return array(
					'ok'      => false,
					'stop'    => $failed['stop'],
					'message' => $failed['message'],
					'done'    => $done,
				);
			}

			$this->failures->succeeded();

			foreach ( $asked['outcomes'] as $id => $outcome ) {
				$done[ (int) $id ] = $this->keep( (int) $id, $outcome, $asked['items'][ $outcome['qid'] ] ?? null );
			}
		}

		return array(
			'ok'      => true,
			'stop'    => false,
			'message' => count( $done ) . ' designation(s) looked at.',
			'done'    => $done,
		);
	}

	/**
	 * Keeps what was learnt about a designation.
	 *
	 * @param int                       $id      Designation.
	 * @param array<string, string>     $outcome The state and item found for its title.
	 * @param array<string, mixed>|null $item    The label and classes of the item.
	 * @return array<string, mixed>
	 */
	private function keep( $id, array $outcome, $item ) {
		$now   = (int) ( $this->now )();
		$found = 'resolved' === $outcome['state'] && null !== $item;
		$state = array(
			'state' => $found ? 'done' : ( 'resolved' === $outcome['state'] ? 'none' : (string) $outcome['state'] ),
			'qid'   => $found ? (string) $outcome['qid'] : '',
			'label' => $found ? (string) $item['label'] : '',
			'kind'  => $found ? (string) ( $this->lookup->classes()->kind_for( array_keys( $item['classes'] ) ) ?? '' ) : '',
			'date'  => $now,
			'due'   => $found ? 0 : $now + (int) ( $this->random )( (int) ( self::RETRY_DAYS * 86400 * 0.8 ), (int) ( self::RETRY_DAYS * 86400 * 1.2 ) ),
		);

		$this->designations->set_enrichment( $id, $state );

		return $state;
	}

	/**
	 * The candidate that Wikidata completes: the identifier first (the strongest key), the label of Wikidata unless it would only change the case or drop
	 * a qualifier, the kind that the classes give when the candidate has none of its own.
	 *
	 * @param Candidate            $candidate The candidate.
	 * @param array<string, mixed> $learnt    What was learnt (`done`).
	 * @return Candidate
	 */
	public function completed( Candidate $candidate, array $learnt ) {
		if ( 'done' !== ( $learnt['state'] ?? '' ) || ! ItemReader::valid_id( (string) $learnt['qid'] ) ) {
			return $candidate;
		}

		$label = '' !== $learnt['label'] && Enricher::renames( $candidate->label, (string) $learnt['label'] ) ? (string) $learnt['label'] : $candidate->label;
		$kind  = '' !== $learnt['kind'] && in_array( $candidate->kind, array( '', 'any', 'other' ), true ) ? (string) $learnt['kind'] : $candidate->kind;
		$done  = new Candidate( $label, $kind, array_merge( array( 'wikidata:' . $learnt['qid'] ), $candidate->keys ), $candidate->parent, $candidate->strength );

		return null === $candidate->source ? $done : $done->from( $candidate->source, $candidate->via, $candidate->modes );
	}

	/**
	 * What was learnt about a designation.
	 *
	 * @param int $id Designation.
	 * @return array<string, mixed>
	 */
	public function learnt( $id ) {
		return $this->designations->enrichment( (int) $id );
	}
}
