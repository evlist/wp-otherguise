<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The topics that nobody mentions.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Merge;

use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Finds, and deletes, the topics that no post mentions any more (slice 619): the **places** that an older chain made (*Somontano de Barbastro* under
 * *Aragon*, before the levels were read from their numbers) and that reading the pages again no longer mentions. A topic is **left alone** when a post
 * mentions it, when a topic below it is kept, when a person chose its kind or its label, when it is refused (a refused topic stops the source from making it
 * again) or when it is of another kind than the ones asked for. What goes is made again, with its keys, if a source names it again.
 */
final class TopicPruner {
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
	 * Builds the pruner.
	 *
	 * @param Topics   $topics   The topics.
	 * @param Mentions $mentions The mentions.
	 */
	public function __construct( Topics $topics, Mentions $mentions ) {
		$this->topics   = $topics;
		$this->mentions = $mentions;
	}

	/**
	 * The topics that can go.
	 *
	 * @param string[] $kinds The kinds asked for, with their sub-kinds: by default `place`.
	 * @return array<int, array{id:int,label:string,kind:string,parent:int}> The topics, the deepest first.
	 */
	public function candidates( array $kinds = array( 'place' ) ) {
		$mentioned = $this->mentions->mentioned_topics();
		$all       = $this->topics->all();
		$children  = array();

		foreach ( $all as $id => $topic ) {
			$children[ (int) $topic['parent'] ][] = (int) $id;
		}

		$keep = array();

		// A topic is kept when it is mentioned, protected, or has a child that is kept: decided from the deepest, in order of the depth.
		$depth = array();

		foreach ( $all as $id => $topic ) {
			$level = 0;
			$up    = (int) $topic['parent'];

			while ( $up > 0 && isset( $all[ $up ] ) && $level < 30 ) {
				++$level;
				$up = (int) $all[ $up ]['parent'];
			}

			$depth[ (int) $id ] = $level;
		}

		$ids = array_keys( $all );

		usort( $ids, static fn( $a, $b ) => $depth[ $b ] <=> $depth[ $a ] );

		foreach ( $ids as $id ) {
			$topic = $all[ $id ];
			$kind  = (string) $topic['kind'];
			$asked = false;

			foreach ( $kinds as $wanted ) {
				$asked = $asked || Kinds::is_a( $kind, $wanted );
			}

			$kept = ! $asked || isset( $mentioned[ $id ] ) || Topics::REFUSED === (string) $topic['status'] || ! empty( $topic['kind_by_hand'] ) || ! empty( $topic['label_by_hand'] );

			foreach ( $children[ $id ] ?? array() as $child ) {
				$kept = $kept || isset( $keep[ $child ] );
			}

			if ( $kept ) {
				$keep[ $id ] = true;
			}
		}

		$goes = array();

		foreach ( $ids as $id ) {
			if ( ! isset( $keep[ $id ] ) ) {
				$goes[] = array(
					'id'     => (int) $id,
					'label'  => (string) $all[ $id ]['label'],
					'kind'   => (string) $all[ $id ]['kind'],
					'parent' => (int) $all[ $id ]['parent'],
				);
			}
		}

		return $goes;
	}

	/**
	 * Deletes the topics that can go.
	 *
	 * @param string[] $kinds The kinds asked for.
	 * @param bool     $dry   Delete nothing.
	 * @return array<int, array> The topics that go (or would), the deepest first.
	 */
	public function prune( array $kinds = array( 'place' ), $dry = true ) {
		$goes = $this->candidates( $kinds );

		if ( ! $dry ) {
			foreach ( $goes as $topic ) {
				$this->topics->delete( $topic['id'] );
			}
		}

		return $goes;
	}
}
