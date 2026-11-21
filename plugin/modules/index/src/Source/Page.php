<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the rendered page of a post says.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Source;

use Otherguise\Index\Candidate;
use Otherguise\Index\Harvest\Signal;
use Otherguise\Index\Harvest\SignalStore;
use Otherguise\Index\Kinds;
use Otherguise\Index\Source;

defined( 'ABSPATH' ) || exit;

/**
 * The source `page` (slice 602): it reads the signals that the job stored for the post (a database source, no request here) and turns into
 * candidates the marks `data-index` and the links to Wikipedia. The other signals are stored but not used yet.
 */
final class Page implements Source {
	public const SLUG = 'page';

	/**
	 * The store of the signals.
	 *
	 * @var SignalStore
	 */
	private $store;

	/**
	 * Builds the source.
	 *
	 * @param SignalStore $store The store.
	 */
	public function __construct( SignalStore $store ) {
		$this->store = $store;
	}

	/**
	 * The slug of the source.
	 *
	 * @return string
	 */
	public function id() {
		return self::SLUG;
	}

	/**
	 * An explicit mark and a link to Wikipedia are things Eric chose to write: sure.
	 *
	 * @return bool
	 */
	public function is_sure() {
		return true;
	}

	/**
	 * What the page says, in the order of the page; one candidate for an entry met twice, the strongest strength kept.
	 *
	 * @param int $post_id Post.
	 * @return Candidate[]
	 */
	public function candidates( $post_id ) {
		$candidates = array();

		foreach ( $this->store->load( (int) $post_id ) as $signal ) {
			$candidate = Signal::ENTRY === $signal->type ? $this->from_mark( $signal ) : ( Signal::WIKIPEDIA_LINK === $signal->type ? $this->from_wikipedia( $signal ) : null );

			if ( null !== $candidate ) {
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}

	/**
	 * The candidate of a mark: the identifier is its key, a path makes the broader candidates.
	 *
	 * @param Signal $signal The signal.
	 * @return Candidate|null
	 */
	private function from_mark( Signal $signal ) {
		$path = is_array( $signal->data['path'] ?? null ) ? array_values( array_map( 'strval', $signal->data['path'] ) ) : array();

		if ( array() === $path ) {
			return null;
		}

		$kind     = isset( $signal->data['kind'] ) && Kinds::is_kind( (string) $signal->data['kind'] ) ? (string) $signal->data['kind'] : Kinds::OTHER;
		$strength = ! empty( $signal->data['main'] ) ? Candidate::MAIN : Candidate::PASSING;
		$parent   = null;
		$last     = count( $path ) - 1;

		foreach ( $path as $position => $label ) {
			$is_last = $position === $last;
			$keys    = array();
			$shown   = $label;

			if ( $is_last && ! empty( $signal->data['identifier'] ) ) {
				// `WIKIDATA:Q1` and `wikidata:Q1` are one key; the label to show is the one the page gives, else the identifier.
				$keys[] = strtolower( (string) strtok( $label, ':' ) ) . ':' . (string) substr( $label, (int) strpos( $label, ':' ) + 1 );
				$shown  = isset( $signal->data['label'] ) ? (string) $signal->data['label'] : $label;
			}

			$parent = new Candidate( $shown, $kind, $keys, $parent, $is_last ? $strength : Candidate::PASSING );
		}

		return $parent;
	}

	/**
	 * The candidate of a link to Wikipedia: the key names the language and the title, the label is the title.
	 *
	 * @param Signal $signal The signal.
	 * @return Candidate
	 */
	private function from_wikipedia( Signal $signal ) {
		$language = (string) ( $signal->data['language'] ?? '' );

		return new Candidate( $signal->value, Kinds::OTHER, array( 'wikipedia:' . $language . ':' . $signal->value ), null, Candidate::PASSING );
	}
}
