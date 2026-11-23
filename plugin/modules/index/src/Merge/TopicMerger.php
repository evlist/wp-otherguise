<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The merge of topics.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Merge;

use Otherguise\Index\Folding;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Resolver;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Makes two topics one (slice 616): the topic that stays takes **the mentions** of the other, its **keys** (so that a source that names the one that went
 * away finds the one that stayed: the resolver does not make it again), its **children**, and, when it is not classified or less precise, its **kind**.
 * Two children with the same label are merged in turn. Then the topic goes away. Nothing is changed with `dry`: the report says what would be.
 */
final class TopicMerger {
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
	 * Builds the merger.
	 *
	 * @param Topics   $topics   The topics.
	 * @param Mentions $mentions The mentions.
	 */
	public function __construct( Topics $topics, Mentions $mentions ) {
		$this->topics   = $topics;
		$this->mentions = $mentions;
	}

	/**
	 * Merges a topic into another.
	 *
	 * @param int  $keep  The topic that stays.
	 * @param int  $go    The topic that goes away.
	 * @param bool $dry   Change nothing, say what would be.
	 * @param bool $force Merge two kinds that are not one a sort of the other.
	 * @return array{ok:bool,error:string,mentions_moved:int,mentions_joined:int,children_moved:int,children_merged:int,keys:int,kind:string,lines:string[]}
	 */
	public function merge( $keep, $go, $dry = false, $force = false ) {
		$report = array(
			'ok'              => false,
			'error'           => '',
			'mentions_moved'  => 0,
			'mentions_joined' => 0,
			'children_moved'  => 0,
			'children_merged' => 0,
			'keys'            => 0,
			'kind'            => '',
			'lines'           => array(),
		);
		$a      = $this->topics->get( (int) $keep );
		$b      = $this->topics->get( (int) $go );

		if ( null === $a || null === $b || (int) $keep === (int) $go ) {
			$report['error'] = null === $a || null === $b ? 'Both topics must exist.' : 'A topic is not merged into itself.';

			return $report;
		}

		if ( $this->is_below( (int) $keep, (int) $go ) ) {
			$report['error'] = 'The topic that stays is below the one that goes: merge the children first, or the other way round.';

			return $report;
		}

		if ( ! $force && ! Kinds::compatible( (string) $a['kind'], (string) $b['kind'] ) ) {
			$report['error'] = 'The kinds ' . $a['kind'] . ' and ' . $b['kind'] . ' are not one a sort of the other: use --force to merge them anyway.';

			return $report;
		}

		$this->do_merge( (int) $keep, (int) $go, $dry, $report, 0 );

		$report['ok'] = true;

		return $report;
	}

	/**
	 * Merges, and recurses on the children that have the same label.
	 *
	 * @param int                  $keep   The topic that stays.
	 * @param int                  $go     The topic that goes away.
	 * @param bool                 $dry    Change nothing.
	 * @param array<string, mixed> $report The report (modified).
	 * @param int                  $depth  Level of recursion.
	 * @return void
	 */
	private function do_merge( $keep, $go, $dry, array &$report, $depth ) {
		$a = $this->topics->get( $keep );
		$b = $this->topics->get( $go );

		if ( null === $a || null === $b || $depth > 20 ) {
			return;
		}

		$report['lines'][] = str_repeat( '  ', $depth ) . $b['label'] . ' (' . $go . ') into ' . $a['label'] . ' (' . $keep . ')';

		// The children first: those with a label that the other has are merged, the others go under the topic that stays.
		$mine = array();

		foreach ( $this->topics->all( array( 'parent' => $keep ) ) as $child ) {
			$mine[ Folding::key( (string) $child['label'] ) ] = (int) $child['id'];
		}

		foreach ( $this->topics->all( array( 'parent' => $go ) ) as $child ) {
			$same = $mine[ Folding::key( (string) $child['label'] ) ] ?? 0;

			if ( $same > 0 && $same !== (int) $child['id'] ) {
				++$report['children_merged'];
				$this->do_merge( $same, (int) $child['id'], $dry, $report, $depth + 1 );

				continue;
			}

			++$report['children_moved'];

			if ( ! $dry ) {
				$this->topics->set_parent( (int) $child['id'], $keep );
				// The key that a source makes for this label under the topic that stays.
				$this->topics->add_keys( (int) $child['id'], array( Resolver::label_key( $keep, (string) $child['label'] ) ) );
			}
		}

		$counts                    = $this->mentions->move_topic( $go, $keep, $dry );
		$report['mentions_moved']  += $counts['moved'];
		$report['mentions_joined'] += $counts['merged'];

		$keys = array_values( array_diff( (array) $b['keys'], (array) $a['keys'] ) );
		$keys = array_values( array_unique( array_merge( $keys, array( Resolver::label_key( (int) $a['parent'], (string) $b['label'] ) ) ) ) );
		$keys = array_values( array_diff( $keys, (array) $a['keys'] ) );

		$report['keys'] += count( $keys );

		$kind = (string) $a['kind'];

		// A topic that is not classified, or less precise, takes the kind of the other, unless a person chose it.
		if ( empty( $a['kind_by_hand'] ) && $b['kind'] !== $a['kind'] && Kinds::is_a( (string) $b['kind'], $kind ) ) {
			$kind = (string) $b['kind'];
		}

		$report['kind'] = $kind;

		if ( $dry ) {
			return;
		}

		$this->topics->add_keys( $keep, $keys );

		if ( $kind !== $a['kind'] ) {
			$this->topics->set_kind( $keep, $kind, false );
		}

		$this->topics->delete( $go );
	}

	/**
	 * Whether a topic is below another, at any depth.
	 *
	 * @param int $id    The topic.
	 * @param int $above The topic that may be above it.
	 * @return bool
	 */
	private function is_below( $id, $above ) {
		for ( $level = 0; $level < 30; $level++ ) {
			$topic = $this->topics->get( $id );

			if ( null === $topic || (int) $topic['parent'] <= 0 ) {
				return false;
			}

			if ( (int) $topic['parent'] === $above ) {
				return true;
			}

			$id = (int) $topic['parent'];
		}

		return false;
	}
}
