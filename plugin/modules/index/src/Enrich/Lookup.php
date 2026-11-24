<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What Wikidata says about titles of Wikipedia.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Enrich;

use Otherguise\Index\Wikidata\ClassTable;
use Otherguise\Index\Wikidata\ItemReader;
use Otherguise\Index\Wikidata\TitleResolver;

defined( 'ABSPATH' ) || exit;

/**
 * The questions to the services, shared by the enrichment of the topics (slice 604) and the enrichment before creation (slice 627): the titles are
 * resolved to items (one request per language), the label and the classes of the items are read (one request), the ancestors of the classes not yet known
 * are read (one request per 50). **Nothing is written here**: the caller writes when every request worked.
 */
final class Lookup {
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
	 * The language of the labels.
	 *
	 * @var string
	 */
	private $language;

	/**
	 * Builds the service.
	 *
	 * @param TitleResolver $titles   The resolver of titles.
	 * @param ItemReader    $items    The reader of items.
	 * @param ClassTable    $classes  The table of classes.
	 * @param string        $language Language of the labels (`fr`).
	 */
	public function __construct( TitleResolver $titles, ItemReader $items, ClassTable $classes, $language ) {
		$this->titles   = $titles;
		$this->items    = $items;
		$this->classes  = $classes;
		$this->language = (string) $language;
	}

	/**
	 * The table of classes (to deduce a kind from the classes of an item).
	 *
	 * @return ClassTable
	 */
	public function classes() {
		return $this->classes;
	}

	/**
	 * Asks the services.
	 *
	 * @param array<int|string, array{type:string,language:string,title:string,qid:string}> $sources By id: a title of Wikipedia, or an identifier of Wikidata.
	 * @return array{ok:true,outcomes:array<int|string,array<string,string>>,items:array<string,array>}|array{ok:false,response:array}
	 */
	public function run( array $sources ) {
		$resolved = $this->resolve_titles( $sources );

		if ( isset( $resolved['failure'] ) ) {
			return array(
				'ok'       => false,
				'response' => $resolved['failure'],
			);
		}

		$qids  = array_values( array_unique( array_filter( array_column( $resolved['items'], 'qid' ) ) ) );
		$items = array();

		if ( array() !== $qids ) {
			$read = $this->items->read( $qids, $this->language );

			if ( ! $read['response']['ok'] ) {
				return array(
					'ok'       => false,
					'response' => $read['response'],
				);
			}

			$items = $read['items'];
		}

		$unknown = $this->classes->unknown( array_keys( array_merge( array(), ...array_column( $items, 'classes' ) ) ) );

		foreach ( array_chunk( $unknown, ItemReader::BATCH ) as $chunk ) {
			$ancestors = $this->items->ancestors( $chunk );

			if ( ! $ancestors['response']['ok'] ) {
				return array(
					'ok'       => false,
					'response' => $ancestors['response'],
				);
			}

			$this->classes->remember( $ancestors['ancestors'] );
		}

		return array(
			'ok'       => true,
			'outcomes' => $resolved['items'],
			'items'    => $items,
		);
	}

	/**
	 * Resolves the titles, one request per language.
	 *
	 * @param array<int|string, array<string, string>> $sources By id.
	 * @return array{items:array<int|string,array<string,string>>}|array{failure:array}
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
}
