<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The designations that waited for Wikidata.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Enrich;

use Otherguise\Index\Candidate;
use Otherguise\Index\Designations;
use Otherguise\Index\Mentions;
use Otherguise\Index\Observations;
use Otherguise\Index\Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * The queue of the designations that are `pending` because Wikidata had not answered when their post was read (slice 627): a batch at a time, they are
 * looked up, then **resolved with what Wikidata said** (the topic is made complete, or found by its identifier), assigned, and the mentions of the posts
 * that observe them are made. **The pages are not read again.**
 */
final class DesignationRetry {
	/**
	 * The designations.
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
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * The resolver.
	 *
	 * @var Resolver
	 */
	private $resolver;

	/**
	 * The enrichment.
	 *
	 * @var DesignationEnricher
	 */
	private $enrichment;

	/**
	 * Builds the queue.
	 *
	 * @param Designations        $designations The designations.
	 * @param Observations        $observations The observations.
	 * @param Mentions            $mentions     The mentions.
	 * @param Resolver            $resolver     The resolver.
	 * @param DesignationEnricher $enrichment   The enrichment.
	 */
	public function __construct( Designations $designations, Observations $observations, Mentions $mentions, Resolver $resolver, DesignationEnricher $enrichment ) {
		$this->designations = $designations;
		$this->observations = $observations;
		$this->mentions     = $mentions;
		$this->resolver     = $resolver;
		$this->enrichment   = $enrichment;
	}

	/**
	 * The designations that wait: `pending`, with a link to Wikipedia and no identifier of Wikidata, not looked at too recently.
	 *
	 * @param int $limit At most this many.
	 * @return array<int, array{type:string,language:string,title:string,qid:string}> By designation.
	 */
	public function pending( $limit = DesignationEnricher::BATCH ) {
		$found = array();

		foreach ( $this->designations->all( array( 'state' => Designations::PENDING ) ) as $id => $one ) {
			if ( count( $found ) >= $limit ) {
				break;
			}

			$source = DesignationEnricher::source_of( (array) ( $one['evidence']['keys'] ?? array() ) );

			if ( null !== $source && ! $this->enrichment->known( (int) $id ) ) {
				$found[ (int) $id ] = $source;
			}
		}

		return $found;
	}

	/**
	 * Looks up one batch and resolves what was answered.
	 *
	 * @return array{ok:bool,stop:bool,message:string,processed:int,report:array<int,string>}
	 */
	public function run_batch() {
		$ask = $this->pending();

		if ( array() === $ask ) {
			return $this->result( true, false, 'Nothing to enrich.', array() );
		}

		$asked  = $this->enrichment->look_up( $ask );
		$report = array();

		foreach ( array_keys( $asked['done'] ) as $id ) {
			$report[ $id ] = $this->resolve( (int) $id );
		}

		return $this->result( $asked['ok'], $asked['stop'], $asked['ok'] ? count( $report ) . ' designation(s) looked at.' : $asked['message'], $report );
	}

	/**
	 * Resolves a designation with what Wikidata said, and makes the mentions of its posts.
	 *
	 * @param int $id Designation.
	 * @return string What happened: `assigned` or `ambiguous`.
	 */
	private function resolve( $id ) {
		$one = (array) $this->designations->get( $id );
		$was = $one['evidence'] ?? array();

		$candidate = ( new Candidate( (string) ( $was['label'] ?? '' ), (string) ( $was['kind'] ?? 'any' ), (array) ( $was['keys'] ?? array() ) ) )->from( (string) $one['source'] );
		$candidate = $this->enrichment->completed( $candidate, $this->enrichment->learnt( $id ) );
		$done      = $this->resolver->resolve( $candidate, true, false );

		if ( $done['topic'] <= 0 ) {
			$this->designations->set_state(
				$id,
				Designations::AMBIGUOUS,
				array(
					'topic' => 0,
					'why'   => (string) ( $candidate->keys[0] ?? 'label' ),
				)
			);

			return Designations::AMBIGUOUS;
		}

		$this->observations->assign( $id, $done['topic'], Observations::SYSTEM );
		$this->designations->set_state(
			$id,
			Designations::ASSIGNED,
			array(
				'topic' => $done['topic'],
				'why'   => (string) ( $candidate->keys[0] ?? 'label' ),
			)
		);

		foreach ( $this->observations->posts_of( $id ) as $post ) {
			$this->mentions->derive_post( (int) $post );
		}

		return Designations::ASSIGNED;
	}

	/**
	 * A result, as the enrichment of the topics gives.
	 *
	 * @param bool               $ok      Whether the batch worked.
	 * @param bool               $stop    Whether the job must stop.
	 * @param string             $message Message.
	 * @param array<int, string> $report  What happened to each designation.
	 * @return array{ok:bool,stop:bool,message:string,processed:int,report:array<int,string>}
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
