<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The topic that stands for "not in the index".
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * **`_None_`** (slice 641), the topic that a motif is assigned to when a person says **it is not for the index** (a tag that says nothing of what an index lists):
 * an assignment like the others, by hand, so that a reading never changes it, and so that the screen of the assignments shows and handles it like any topic
 * (the filter *Topic*, *Change topic*, the sort). **What makes it different is kept to a few rules**: its status is `refused` (the resolver never finds it by a label,
 * the index lists only the approved topics, the enrichment and the doubles leave it alone); **a motif assigned to it makes no mention**, so no post appears in the
 * index through it (`Mentions::skips()`); the cleaning never deletes it and it is neither merged nor renamed; it is made **the first time it is used**. The name begins
 * with an underscore: in the lists, which fold the case and keep the punctuation, it comes **before every letter**, apart from the other topics, and no real topic
 * can be called so.
 */
final class NoneTopic {
	public const KEY   = 'reserved:none';
	public const LABEL = '_None_';

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * The number of the topic, once it is known to exist (0 is never kept: another instance may make the topic).
	 *
	 * @var int
	 */
	private $id = 0;

	/**
	 * Builds the service.
	 *
	 * @param Topics $topics The topics.
	 */
	public function __construct( Topics $topics ) {
		$this->topics = $topics;
	}

	/**
	 * The number of the topic.
	 *
	 * @param bool $make Make it when it does not exist yet.
	 * @return int 0 when it does not exist (and was not asked to be made).
	 */
	public function id( $make = false ) {
		if ( $this->id > 0 ) {
			return $this->id;
		}

		$found = $this->topics->with_key( self::KEY );

		if ( array() !== $found ) {
			$this->id = (int) reset( $found );
		} elseif ( $make ) {
			$this->id = max( 0, (int) $this->topics->create( self::LABEL, Kinds::ANY, 0, Topics::REFUSED, array( self::KEY ) ) );
		}

		return $this->id;
	}

	/**
	 * Whether a number is the one of the topic: **the topic itself says it** (its reserved key), so that nothing has to be remembered, which would go stale when another
	 * instance makes the topic. The topics are read once for the labels, so the question is cheap.
	 *
	 * @param int $topic A topic.
	 * @return bool
	 */
	public function is_id( $topic ) {
		$found = (int) $topic > 0 ? $this->topics->get( (int) $topic ) : null;

		return null !== $found && self::is_reserved( $found );
	}

	/**
	 * Whether a topic is the reserved one.
	 *
	 * @param array $topic A topic.
	 * @return bool
	 */
	public static function is_reserved( array $topic ) {
		return in_array( self::KEY, (array) ( $topic['keys'] ?? array() ), true );
	}

	/**
	 * What `Mentions` asks to know which topics make no mention: `fn( int $topic ): bool`.
	 *
	 * @param Topics $topics The topics.
	 * @return callable
	 */
	public static function skipper( Topics $topics ) {
		$none = new self( $topics );

		return static fn( $topic ) => $none->is_id( (int) $topic );
	}
}
