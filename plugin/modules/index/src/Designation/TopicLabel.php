<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The label of a topic as the index shows it.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * The label of a topic **as the index shows it** (slice 619 for the rule, slice 639 for the use on the screens): the label, then **the broader topic in parentheses**,
 * *Aragon (Espagne)*; the broader topic is the nearest one that is not of a skipped kind (by default the third divisions: *A Guarda (Pontevedra)*, not *A Guarda
 * (O Baixo Miño)*), else the direct one. The filter `index_context_skipped_kinds` changes the kinds. One rule for the index and for the screens, so that a person finds on
 * a screen the label that the index prints.
 */
final class TopicLabel {
	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * Builds the labeller.
	 *
	 * @param Topics $topics The topics.
	 */
	public function __construct( Topics $topics ) {
		$this->topics = $topics;
	}

	/**
	 * The broader topic that a label shows in parentheses.
	 *
	 * @param array $topic A topic.
	 * @return array|null Null when the topic has no broader topic.
	 */
	public function context( array $topic ) {
		if ( (int) $topic['parent'] <= 0 ) {
			return null;
		}

		$skipped = apply_filters( 'index_context_skipped_kinds', array( 'admin3' ) );
		$skipped = is_array( $skipped ) ? array_map( 'strval', $skipped ) : array( 'admin3' );
		$direct  = $this->topics->get( (int) $topic['parent'] );
		$above   = $direct;

		for ( $level = 0; null !== $above && in_array( (string) $above['kind'], $skipped, true ) && $level < 10; $level++ ) {
			$above = (int) $above['parent'] > 0 ? $this->topics->get( (int) $above['parent'] ) : null;
		}

		return null === $above ? $direct : $above;
	}

	/**
	 * The label of the index: the label, and the broader topic in parentheses.
	 *
	 * @param array $topic A topic.
	 * @return string
	 */
	public function of( array $topic ) {
		$above = $this->context( $topic );

		return null === $above ? (string) $topic['label'] : (string) $topic['label'] . ' (' . $above['label'] . ')';
	}
}
