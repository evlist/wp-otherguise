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
use Otherguise\Index\Sources;

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
	 * Whether the links to Wikidata make candidates (they have no label until the enrichment gives them one).
	 *
	 * @var bool
	 */
	private $with_wikidata;

	/**
	 * Builds the source.
	 *
	 * @param SignalStore $store         The store.
	 * @param bool        $with_wikidata Whether the links to Wikidata make candidates: only when the enrichment is on.
	 */
	public function __construct( SignalStore $store, $with_wikidata = false ) {
		$this->store         = $store;
		$this->with_wikidata = (bool) $with_wikidata;
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
	 * The sources of its mentions: the links and the marks.
	 *
	 * @return string[]
	 */
	public function mention_sources() {
		return array( Sources::LINK, Sources::MARK );
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
	 * What the page says, in the order of the page; one candidate for a topic met twice, the strongest strength kept.
	 *
	 * @param int $post_id Post.
	 * @return Candidate[]
	 */
	public function candidates( $post_id ) {
		$candidates = array();

		foreach ( $this->store->load( (int) $post_id ) as $signal ) {
			$candidate = $this->candidate_of( $signal );

			if ( null !== $candidate ) {
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}

	/**
	 * The candidate of a signal, null for the signals that make none.
	 *
	 * @param Signal $signal The signal.
	 * @return Candidate|null
	 */
	private function candidate_of( Signal $signal ) {
		if ( Signal::TOPIC === $signal->type ) {
			return $this->from_mark( $signal );
		}

		if ( Signal::WIKIPEDIA_LINK === $signal->type ) {
			return $this->from_wikipedia( $signal );
		}

		// A link to an item: the identifier is all there is, the label comes with the enrichment.
		return $this->with_wikidata && Signal::WIKIDATA_LINK === $signal->type ? ( new Candidate( $signal->value, Kinds::OTHER, array( 'wikidata:' . $signal->value ), null, Candidate::PASSING ) )->from( Sources::LINK, array( 'link:wikidata:' . $signal->value ) ) : null;
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
				// With no label on the page, a Wikidata identifier is shown as `Q42` (what the enrichment looks for), not as `wikidata:Q42`.
				$shown = isset( $signal->data['label'] ) ? (string) $signal->data['label'] : ( 0 === stripos( $label, 'wikidata:' ) ? (string) substr( $label, 9 ) : $label );
			}

			$parent = new Candidate( $shown, $kind, $keys, $parent, $is_last ? $strength : Candidate::PASSING );
		}

		$modes = is_array( $signal->data['modes'] ?? null ) ? array_map( 'strval', $signal->data['modes'] ) : array();

		return null === $parent ? null : $parent->from( Sources::MARK, array( 'mark:' . ( '' !== $signal->anchor ? '#' . $signal->anchor : implode( ' > ', $path ) ) ), $modes );
	}

	/**
	 * The candidate of a link to Wikipedia: the key names the language and the title, the label is the title.
	 *
	 * @param Signal $signal The signal.
	 * @return Candidate
	 */
	private function from_wikipedia( Signal $signal ) {
		$language = (string) ( $signal->data['language'] ?? '' );

		return ( new Candidate( $signal->value, Kinds::OTHER, array( 'wikipedia:' . $language . ':' . $signal->value ), null, Candidate::PASSING ) )->from( Sources::LINK, array( 'link:' . $language . ':' . $signal->value ) );
	}
}
