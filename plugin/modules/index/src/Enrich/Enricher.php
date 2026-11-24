<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Gives the topics an identifier, a label and a kind from Wikidata.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Enrich;

use Otherguise\Index\Topics;
use Otherguise\Index\Resolver;
use Otherguise\Index\Wikidata\ClassTable;
use Otherguise\Index\Wikidata\ItemReader;
use Otherguise\Index\Wikidata\Options;
use Otherguise\Index\Wikidata\TitleResolver;

defined( 'ABSPATH' ) || exit;

/**
 * The enrichment (slice 604), one batch of up to 50 topics at a time:
 *
 * 1. the titles of Wikipedia are resolved to items (one request per language);
 * 2. the label and the classes of the items are read (one request); the ancestors of the classes not yet known (one request per 50);
 * 3. **only when every request worked**, the topics are written: the key `wikidata:Q…` (a key that another topic already holds is a
 *    `duplicate`, not a merge), the label of Wikidata (unless a person changed it; the old label key stays an alias), the kind (unless a person
 *    set it or it is already something other than `other`).
 *
 * A refusal that says "wait" pauses the enrichment until the date given (never less than an exponential delay, 5 minutes at most); three
 * failures in a row stop it. Nothing is written for a failed batch: the topics stay in the queue.
 */
final class Enricher {
	public const BATCH       = 50;
	public const RETRY_DAYS  = 7;

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

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
	 * The table of classes.
	 *
	 * @var ClassTable
	 */
	private $classes;

	/**
	 * Tells whether a published post mentions a topic: `fn( int $topic ): bool`.
	 *
	 * @var callable
	 */
	private $has_mentions;

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
	 * Builds the enricher.
	 *
	 * @param Topics        $topics      The topics.
	 * @param TitleResolver $titles       The resolver of titles.
	 * @param ItemReader    $items        The reader of items.
	 * @param ClassTable    $classes      The table of classes.
	 * @param Options       $options      The options.
	 * @param callable      $has_mentions Whether a published post mentions a topic.
	 * @param string        $language     Language of the labels (`fr`).
	 * @param callable|null $now          Default `time`.
	 * @param callable|null $random       Default `wp_rand`.
	 */
	public function __construct( Topics $topics, TitleResolver $titles, ItemReader $items, ClassTable $classes, Options $options, $has_mentions, $language, $now = null, $random = null ) {
		$this->topics       = $topics;
		$this->classes      = $classes;
		$this->lookup       = new Lookup( $titles, $items, $classes, $language );
		$this->has_mentions = $has_mentions;
		$this->now          = $now ?? 'time';
		$this->failures     = new Failures( $options, $this->now );
		$this->random       = $random ?? 'wp_rand';
	}

	/**
	 * The topics that wait for the enrichment: approved, mentioned by a published post, with a Wikipedia key and no Wikidata key (or a
	 * Wikidata key and no label of their own), and not looked at too recently.
	 *
	 * @param int $limit At most this many.
	 * @return int[]
	 */
	public function pending( $limit = self::BATCH ) {
		$found = array();
		$now   = (int) ( $this->now )();

		foreach ( $this->topics->all() as $id => $topic ) {
			if ( count( $found ) >= $limit ) {
				break;
			}

			if ( Topics::APPROVED !== $topic['status'] || (int) ( $this->topics->enrich_state( $id )['due'] ?? 0 ) > $now || null === $this->source_of( $topic ) ) {
				continue;
			}

			if ( (bool) ( $this->has_mentions )( (int) $id ) ) {
				$found[] = (int) $id;
			}
		}

		return $found;
	}

	/**
	 * The date until which the enrichment is paused, 0 when it is not.
	 *
	 * @return int
	 */
	public function paused_until() {
		return $this->failures->paused_until();
	}

	/**
	 * The topics that are in a state (`duplicate`, `ambiguous`, `missing`, `none`) with their label and note.
	 *
	 * @param string $state State.
	 * @return array<int, array{label:string,note:string}>
	 */
	public function with_state( $state ) {
		$found = array();

		foreach ( $this->topics->all() as $id => $topic ) {
			$kept = $this->topics->enrich_state( $id );

			if ( ( $kept['state'] ?? '' ) === $state ) {
				$found[ (int) $id ] = array(
					'label' => (string) $topic['label'],
					'note'  => (string) ( $kept['note'] ?? '' ),
				);
			}
		}

		return $found;
	}

	/**
	 * Enriches one batch.
	 *
	 * @param bool     $dry   Report what would be written and write nothing (the requests are made).
	 * @param int|null $only  Only this topic (looked at again whatever its state).
	 * @return array{ok:bool,stop:bool,message:string,processed:int,report:array<int,array<string,mixed>>}
	 */
	public function run_batch( $dry = false, $only = null ) {
		$until = $this->paused_until();

		if ( $until > 0 ) {
			return $this->result( false, true, 'Paused until ' . gmdate( 'Y-m-d H:i:s', $until ) . ' UTC (the service asked to wait).', array() );
		}

		$ids = null === $only ? $this->pending( self::BATCH ) : array( (int) $only );

		if ( array() === $ids ) {
			return $this->result( true, false, 'Nothing to enrich.', array() );
		}

		$sources = array();

		foreach ( $ids as $id ) {
			$topic = $this->topics->get( $id );
			$found = null === $topic ? null : $this->source_of( $topic );

			if ( null !== $found ) {
				$sources[ $id ] = $found;
			}
		}

		$asked = $this->lookup->run( $sources );

		if ( ! $asked['ok'] ) {
			return $this->failed( $asked['response'] );
		}

		$this->failures->succeeded();

		$report = array();

		foreach ( $asked['outcomes'] as $id => $outcome ) {
			$report[ $id ] = $this->apply( (int) $id, $outcome, $asked['items'][ $outcome['qid'] ] ?? null, $dry );
		}

		return $this->result( true, false, count( $report ) . ' topic' . ( 1 === count( $report ) ? '' : 's' ) . ' looked at.', $report );
	}

	/**
	 * Where the identifier of a topic comes from: its Wikipedia key, or its Wikidata key when it has no label of its own; null when the
	 * topic does not need the enrichment.
	 *
	 * @param array<string, mixed> $topic The topic.
	 * @return array{type:string,language:string,title:string,qid:string}|null
	 */
	private function source_of( array $topic ) {
		$wikipedia = null;
		$wikidata  = '';

		foreach ( $topic['keys'] as $key ) {
			if ( null === $wikipedia && 0 === strpos( $key, 'wikipedia:' ) ) {
				$parts = explode( ':', $key, 3 );

				if ( 3 === count( $parts ) && TitleResolver::valid_language( $parts[1] ) && '' !== $parts[2] ) {
					$wikipedia = array(
						'type'     => 'wikipedia',
						'language' => $parts[1],
						'title'    => $parts[2],
						'qid'      => '',
					);
				}
			} elseif ( '' === $wikidata && 0 === strpos( $key, 'wikidata:' ) && ItemReader::valid_id( substr( $key, 9 ) ) ) {
				$wikidata = substr( $key, 9 );
			}
		}

		if ( '' !== $wikidata ) {
			return ItemReader::valid_id( (string) $topic['label'] ) ? array(
				'type'     => 'wikidata',
				'language' => '',
				'title'    => '',
				'qid'      => $wikidata,
			) : null;
		}

		return $wikipedia;
	}

	/**
	 * Writes what was learnt about a topic (or reports it, when it is a dry run).
	 *
	 * @param int                       $id      Topic.
	 * @param array<string, string>     $outcome The state and item found for its title.
	 * @param array<string, mixed>|null $item    The label and classes of the item.
	 * @param bool                      $dry     Dry run.
	 * @return array<string, mixed> What happened.
	 */
	private function apply( $id, array $outcome, $item, $dry ) {
		$topic  = (array) $this->topics->get( $id );
		$qid    = (string) $outcome['qid'];
		$report = array(
			'label'   => (string) $topic['label'],
			'state'   => (string) $outcome['state'],
			'qid'     => $qid,
			'classes' => array(),
			'kind'    => '',
			'new'     => '',
			'note'    => '',
		);

		if ( 'resolved' !== $outcome['state'] || null === $item ) {
			$report['state'] = 'resolved' === $outcome['state'] ? 'none' : $report['state'];

			return $this->keep( $id, $report, $dry );
		}

		$others = array_values( array_diff( $this->topics->with_key( 'wikidata:' . $qid ), array( $id ) ) );

		if ( array() !== $others && ! in_array( 'wikidata:' . $qid, $topic['keys'], true ) ) {
			$report['state'] = 'duplicate';
			$report['note']  = 'The topic ' . $others[0] . ' has ' . $qid . ' too.';

			return $this->keep( $id, $report, $dry );
		}

		$report['classes'] = $item['classes'];
		$deduced           = $this->classes->kind_for( array_keys( $item['classes'] ) );
		$new_label         = '' !== $item['label'] && ! $topic['label_by_hand'] && self::renames( (string) $topic['label'], (string) $item['label'] ) ? $item['label'] : '';
		$new_kind          = null !== $deduced && ! $topic['kind_by_hand'] && in_array( (string) $topic['kind'], array( '', 'any', 'other' ), true ) && $deduced !== $topic['kind'] ? $deduced : '';

		$report['state'] = 'done';
		$report['kind']  = $new_kind;
		$report['new']   = $new_label;

		if ( ! $dry ) {
			$keys = array( 'wikidata:' . $qid );

			if ( '' !== $new_label ) {
				$this->topics->set_label( $id, $new_label );
				$keys[] = Resolver::label_key( (int) $topic['parent'], $new_label );
			}

			if ( '' !== $new_kind ) {
				$this->topics->set_kind( $id, $new_kind, false );
			}

			$this->topics->add_keys( $id, $keys );
		}

		return $this->keep( $id, $report, $dry );
	}

	/**
	 * Whether the label of Wikidata should replace the label of a topic: not when it only changes the case (`Têt` and `TÊT`), and not when the topic has a
	 * qualifier in parentheses that Wikidata's label does not carry (`Moulins (Allier)` is not renamed `Moulins`: the qualifier tells it from its namesakes).
	 *
	 * @param string $current The label of the topic.
	 * @param string $wikidata The label that Wikidata gives.
	 * @return bool
	 */
	public static function renames( $current, $wikidata ) {
		if ( $current === $wikidata || mb_strtolower( $current ) === mb_strtolower( $wikidata ) ) {
			return false;
		}

		return ! ( 1 === preg_match( '/\([^)]+\)\s*$/u', $current ) && 1 !== preg_match( '/\([^)]+\)\s*$/u', $wikidata ) );
	}

	/**
	 * Keeps the state of a topic and the date until which it is not looked at again.
	 *
	 * @param int                  $id     Topic.
	 * @param array<string, mixed> $report What happened.
	 * @param bool                 $dry    Dry run.
	 * @return array<string, mixed> The report.
	 */
	private function keep( $id, array $report, $dry ) {
		$now = (int) ( $this->now )();
		$due = 'done' === $report['state'] ? 0 : $now + (int) ( $this->random )( (int) ( self::RETRY_DAYS * 86400 * 0.8 ), (int) ( self::RETRY_DAYS * 86400 * 1.2 ) );

		if ( ! $dry ) {
			$this->topics->set_enrich_state(
				$id,
				array(
					'state' => $report['state'],
					'date'  => $now,
					'due'   => $due,
					'qid'   => $report['qid'],
					'note'  => $report['note'],
				)
			);
		}

		return $report;
	}

	/**
	 * Counts a failure and decides whether to stop: a refusal that says "wait" pauses the enrichment, three failures in a row stop it.
	 *
	 * @param array<string, mixed> $response The answer of the client.
	 * @return array{ok:bool,stop:bool,message:string,processed:int,report:array}
	 */
	private function failed( array $response ) {
		$failed = $this->failures->failed( $response );

		return $this->result( false, $failed['stop'], $failed['message'], array() );
	}

	/**
	 * A result.
	 *
	 * @param bool                            $ok      Whether the batch worked.
	 * @param bool                            $stop    Whether the job must stop.
	 * @param string                          $message Message.
	 * @param array<int, array<string,mixed>> $report  What happened to each topic.
	 * @return array{ok:bool,stop:bool,message:string,processed:int,report:array}
	 */
	private function result( $ok, $stop, $message, array $report ) {
		return array(
			'ok'        => $ok,
			'stop'      => $stop,
			'message'   => $message,
			'processed' => count( $report ),
			'report'    => $report,
		);
	}
}
