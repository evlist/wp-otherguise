<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Gives the entries an identifier, a label and a kind from Wikidata.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Enrich;

use Otherguise\Index\Entries;
use Otherguise\Index\Resolver;
use Otherguise\Index\Wikidata\ClassTable;
use Otherguise\Index\Wikidata\ItemReader;
use Otherguise\Index\Wikidata\Options;
use Otherguise\Index\Wikidata\TitleResolver;

defined( 'ABSPATH' ) || exit;

/**
 * The enrichment (slice 604), one batch of up to 50 entries at a time:
 *
 * 1. the titles of Wikipedia are resolved to items (one request per language);
 * 2. the label and the classes of the items are read (one request); the ancestors of the classes not yet known (one request per 50);
 * 3. **only when every request worked**, the entries are written: the key `wikidata:Q…` (a key that another entry already holds is a
 *    `duplicate`, not a merge), the label of Wikidata (unless a person changed it; the old label key stays an alias), the kind (unless a person
 *    set it or it is already something other than `other`).
 *
 * A refusal that says "wait" pauses the enrichment until the date given (never less than an exponential delay, 5 minutes at most); three
 * failures in a row stop it. Nothing is written for a failed batch: the entries stay in the queue.
 */
final class Enricher {
	public const BATCH       = 50;
	public const STOP_AFTER  = 3;
	public const RETRY_DAYS  = 7;
	public const MAX_BACKOFF = 300;

	/**
	 * The entries.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Resolves the titles.
	 *
	 * @var TitleResolver
	 */
	private $titles;

	/**
	 * Reads the items.
	 *
	 * @var ItemReader
	 */
	private $items;

	/**
	 * The table of classes.
	 *
	 * @var ClassTable
	 */
	private $classes;

	/**
	 * The options (failures, pause).
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * Tells whether a published post mentions an entry: `fn( int $entry ): bool`.
	 *
	 * @var callable
	 */
	private $has_mentions;

	/**
	 * The language of the labels.
	 *
	 * @var string
	 */
	private $language;

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
	 * @param Entries       $entries      The entries.
	 * @param TitleResolver $titles       The resolver of titles.
	 * @param ItemReader    $items        The reader of items.
	 * @param ClassTable    $classes      The table of classes.
	 * @param Options       $options      The options.
	 * @param callable      $has_mentions Whether a published post mentions an entry.
	 * @param string        $language     Language of the labels (`fr`).
	 * @param callable|null $now          Default `time`.
	 * @param callable|null $random       Default `wp_rand`.
	 */
	public function __construct( Entries $entries, TitleResolver $titles, ItemReader $items, ClassTable $classes, Options $options, $has_mentions, $language, $now = null, $random = null ) {
		$this->entries      = $entries;
		$this->titles       = $titles;
		$this->items        = $items;
		$this->classes      = $classes;
		$this->options      = $options;
		$this->has_mentions = $has_mentions;
		$this->language     = (string) $language;
		$this->now          = $now ?? 'time';
		$this->random       = $random ?? 'wp_rand';
	}

	/**
	 * The entries that wait for the enrichment: approved, mentioned by a published post, with a Wikipedia key and no Wikidata key (or a
	 * Wikidata key and no label of their own), and not looked at too recently.
	 *
	 * @param int $limit At most this many.
	 * @return int[]
	 */
	public function pending( $limit = self::BATCH ) {
		$found = array();
		$now   = (int) ( $this->now )();

		foreach ( $this->entries->all() as $id => $entry ) {
			if ( count( $found ) >= $limit ) {
				break;
			}

			if ( Entries::APPROVED !== $entry['status'] || (int) ( $this->entries->enrich_state( $id )['due'] ?? 0 ) > $now || null === $this->source_of( $entry ) ) {
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
		$until = (int) $this->options->get( 'index_enrich_pause_until', 0 );

		return $until > (int) ( $this->now )() ? $until : 0;
	}

	/**
	 * The entries that are in a state (`duplicate`, `ambiguous`, `missing`, `none`) with their label and note.
	 *
	 * @param string $state State.
	 * @return array<int, array{label:string,note:string}>
	 */
	public function with_state( $state ) {
		$found = array();

		foreach ( $this->entries->all() as $id => $entry ) {
			$kept = $this->entries->enrich_state( $id );

			if ( ( $kept['state'] ?? '' ) === $state ) {
				$found[ (int) $id ] = array(
					'label' => (string) $entry['label'],
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
	 * @param int|null $only  Only this entry (looked at again whatever its state).
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
			$entry = $this->entries->get( $id );
			$found = null === $entry ? null : $this->source_of( $entry );

			if ( null !== $found ) {
				$sources[ $id ] = $found;
			}
		}

		$resolved = $this->resolve_titles( $sources );

		if ( isset( $resolved['failure'] ) ) {
			return $this->failed( $resolved['failure'] );
		}

		$qids  = array_values( array_unique( array_filter( array_column( $resolved['items'], 'qid' ) ) ) );
		$items = array();

		if ( array() !== $qids ) {
			$read = $this->items->read( $qids, $this->language );

			if ( ! $read['response']['ok'] ) {
				return $this->failed( $read['response'] );
			}

			$items = $read['items'];
		}

		$unknown = $this->classes->unknown( array_keys( array_merge( array(), ...array_column( $items, 'classes' ) ) ) );

		foreach ( array_chunk( $unknown, ItemReader::BATCH ) as $chunk ) {
			$ancestors = $this->items->ancestors( $chunk );

			if ( ! $ancestors['response']['ok'] ) {
				return $this->failed( $ancestors['response'] );
			}

			$this->classes->remember( $ancestors['ancestors'] );
		}

		$this->options->set( 'index_enrich_failures', 0 );
		$this->options->set( 'index_enrich_pause_until', 0 );

		$report = array();

		foreach ( $resolved['items'] as $id => $outcome ) {
			$report[ $id ] = $this->apply( (int) $id, $outcome, $items[ $outcome['qid'] ] ?? null, $dry );
		}

		return $this->result( true, false, count( $report ) . ' entr' . ( 1 === count( $report ) ? 'y' : 'ies' ) . ' looked at.', $report );
	}

	/**
	 * Where the identifier of an entry comes from: its Wikipedia key, or its Wikidata key when it has no label of its own; null when the
	 * entry does not need the enrichment.
	 *
	 * @param array<string, mixed> $entry The entry.
	 * @return array{type:string,language:string,title:string,qid:string}|null
	 */
	private function source_of( array $entry ) {
		$wikipedia = null;
		$wikidata  = '';

		foreach ( $entry['keys'] as $key ) {
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
			return ItemReader::valid_id( (string) $entry['label'] ) ? array(
				'type'     => 'wikidata',
				'language' => '',
				'title'    => '',
				'qid'      => $wikidata,
			) : null;
		}

		return $wikipedia;
	}

	/**
	 * Resolves the titles of the entries, one request per language.
	 *
	 * @param array<int, array<string, string>> $sources By entry.
	 * @return array{items:array<int,array<string,string>>}|array{failure:array}
	 */
	private function resolve_titles( array $sources ) {
		$by_language = array();
		$outcomes    = array();

		foreach ( $sources as $id => $source ) {
			if ( 'wikidata' === $source['type'] ) {
				$outcomes[ $id ] = array(
					'state' => 'resolved',
					'qid'   => $source['qid'],
				);
			} else {
				$by_language[ $source['language'] ][ $id ] = $source['title'];
			}
		}

		foreach ( $by_language as $language => $titles ) {
			$answer = $this->titles->resolve( $language, array_values( $titles ) );

			if ( ! $answer['response']['ok'] ) {
				return array( 'failure' => $answer['response'] );
			}

			foreach ( $titles as $id => $title ) {
				$outcomes[ $id ] = $answer['results'][ $title ] ?? array(
					'state' => 'missing',
					'qid'   => '',
				);
			}
		}

		return array( 'items' => $outcomes );
	}

	/**
	 * Writes what was learnt about an entry (or reports it, when it is a dry run).
	 *
	 * @param int                       $id      Entry.
	 * @param array<string, string>     $outcome The state and item found for its title.
	 * @param array<string, mixed>|null $item    The label and classes of the item.
	 * @param bool                      $dry     Dry run.
	 * @return array<string, mixed> What happened.
	 */
	private function apply( $id, array $outcome, $item, $dry ) {
		$entry  = (array) $this->entries->get( $id );
		$qid    = (string) $outcome['qid'];
		$report = array(
			'label'   => (string) $entry['label'],
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

		$others = array_values( array_diff( $this->entries->with_key( 'wikidata:' . $qid ), array( $id ) ) );

		if ( array() !== $others && ! in_array( 'wikidata:' . $qid, $entry['keys'], true ) ) {
			$report['state'] = 'duplicate';
			$report['note']  = 'The entry ' . $others[0] . ' has ' . $qid . ' too.';

			return $this->keep( $id, $report, $dry );
		}

		$report['classes'] = $item['classes'];
		$deduced           = $this->classes->kind_for( array_keys( $item['classes'] ) );
		$new_label         = '' !== $item['label'] && ! $entry['label_by_hand'] && $item['label'] !== $entry['label'] ? $item['label'] : '';
		$new_kind          = null !== $deduced && ! $entry['kind_by_hand'] && in_array( (string) $entry['kind'], array( '', 'other' ), true ) && $deduced !== $entry['kind'] ? $deduced : '';

		$report['state'] = 'done';
		$report['kind']  = $new_kind;
		$report['new']   = $new_label;

		if ( ! $dry ) {
			$keys = array( 'wikidata:' . $qid );

			if ( '' !== $new_label ) {
				$this->entries->set_label( $id, $new_label );
				$keys[] = Resolver::label_key( (int) $entry['parent'], $new_label );
			}

			if ( '' !== $new_kind ) {
				$this->entries->set_kind( $id, $new_kind, false );
			}

			$this->entries->add_keys( $id, $keys );
		}

		return $this->keep( $id, $report, $dry );
	}

	/**
	 * Keeps the state of an entry and the date until which it is not looked at again.
	 *
	 * @param int                  $id     Entry.
	 * @param array<string, mixed> $report What happened.
	 * @param bool                 $dry    Dry run.
	 * @return array<string, mixed> The report.
	 */
	private function keep( $id, array $report, $dry ) {
		$now = (int) ( $this->now )();
		$due = 'done' === $report['state'] ? 0 : $now + (int) ( $this->random )( (int) ( self::RETRY_DAYS * 86400 * 0.8 ), (int) ( self::RETRY_DAYS * 86400 * 1.2 ) );

		if ( ! $dry ) {
			$this->entries->set_enrich_state(
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

		return $this->result( false, $stop, $message, array() );
	}

	/**
	 * A result.
	 *
	 * @param bool                            $ok      Whether the batch worked.
	 * @param bool                            $stop    Whether the job must stop.
	 * @param string                          $message Message.
	 * @param array<int, array<string,mixed>> $report  What happened to each entry.
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
