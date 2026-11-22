<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The elements of a page that stand for a mention.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Harvest;

use Otherguise\Index\Mentions;
use Otherguise\Index\Source\Page;

defined( 'ABSPATH' ) || exit;

/**
 * Tells which elements of the HTML of a post stand for a mention that is stored (slice 612): the page is read as the source `page` reads it, and an
 * element whose signal made a candidate with a `via` that the post's mentions hold gets the attribute `data-index-mention`, whose value is that
 * `via` as it is stored. A link that made no mention gets nothing. The merge of the books puts a marker in front of these elements (the module
 * of the books does not name this class: it asks the filter `index_mention_elements`).
 */
final class MentionElements {
	/**
	 * The reader of pages.
	 *
	 * @var PageReader
	 */
	private $reader;

	/**
	 * The source `page`, which turns a signal into a candidate.
	 *
	 * @var Page
	 */
	private $page;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * Builds the reader.
	 *
	 * @param PageReader $reader   The reader of pages.
	 * @param Page       $page     The source `page`.
	 * @param Mentions   $mentions The mentions.
	 */
	public function __construct( PageReader $reader, Page $page, Mentions $mentions ) {
		$this->reader   = $reader;
		$this->page     = $page;
		$this->mentions = $mentions;
	}

	/**
	 * Stamps the elements of a post.
	 *
	 * @param string $html    The HTML of the post as it is printed.
	 * @param int    $post_id The post.
	 * @return string The same HTML, with `data-index-mention` on the elements that stand for a mention.
	 */
	public function mark( $html, $post_id ) {
		$html = (string) $html;

		$stored = array();

		foreach ( $this->mentions->of_post( (int) $post_id ) as $mention ) {
			foreach ( $mention['details'] as $source => $detail ) {
				foreach ( $detail['via'] as $via ) {
					$stored[ (string) $source ][ $via ] = true;
				}
			}
		}

		if ( array() === $stored ) {
			return $html;
		}

		$read   = $this->reader->stamped( $html );
		$values = array();

		foreach ( $read['signals'] as $position => $signal ) {
			$candidate = $this->page->candidate_of( $signal );

			foreach ( null === $candidate ? array() : $candidate->via as $via ) {
				$kept = substr( Mentions::pack( (string) $candidate->source, $via ), strlen( (string) $candidate->source ) + 1 );

				if ( isset( $stored[ (string) $candidate->source ][ $kept ] ) ) {
					$values[ $position ] = $kept;
					break;
				}
			}
		}

		$tags = new \WP_HTML_Tag_Processor( $read['html'] );

		while ( $tags->next_tag() ) {
			$sigs = $tags->get_attribute( 'data-index-sig' );

			if ( ! is_string( $sigs ) ) {
				continue;
			}

			$tags->remove_attribute( 'data-index-sig' );

			foreach ( explode( ',', $sigs ) as $position ) {
				if ( isset( $values[ (int) $position ] ) ) {
					$tags->set_attribute( 'data-index-mention', $values[ (int) $position ] );
					break;
				}
			}
		}

		return $tags->get_updated_html();
	}
}
