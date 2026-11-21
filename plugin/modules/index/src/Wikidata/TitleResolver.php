<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * From the title of a Wikipedia page to its Wikidata item.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Wikidata;

defined( 'ABSPATH' ) || exit;

/**
 * `action=query & prop=pageprops` on the Wikipedia of a language, for up to 50 titles at once (slice 604). The answer says, for each title asked,
 * whether it is an item (`resolved`), a disambiguation page (`ambiguous`), a page with no item (`none`) or no page (`missing`). The maps
 * `normalized` and `redirects` of the answer bring each page back to the title that was asked.
 */
final class TitleResolver {
	public const BATCH = 50;

	/**
	 * The client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Builds the resolver.
	 *
	 * @param Client $client The client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Tells whether a language code can be put in an address.
	 *
	 * @param string $language Code.
	 * @return bool
	 */
	public static function valid_language( $language ) {
		return 1 === preg_match( '/^[a-z]{2,3}(-[a-z]{2,8})?$/', (string) $language );
	}

	/**
	 * Resolves titles of one language.
	 *
	 * @param string   $language Language of the Wikipedia (`fr`).
	 * @param string[] $titles   Titles, at most 50.
	 * @return array{response:array,results:array<string,array{state:string,qid:string}>}
	 */
	public function resolve( $language, array $titles ) {
		$titles = array_slice( array_values( array_unique( array_map( 'strval', $titles ) ) ), 0, self::BATCH );

		if ( ! self::valid_language( $language ) || array() === $titles ) {
			return array(
				'response' => array(
					'ok'          => false,
					'class'       => 'fatal',
					'status'      => 0,
					'json'        => null,
					'retry_after' => 0,
					'error'       => 'No title, or a language that is not a code.',
				),
				'results'  => array(),
			);
		}

		$response = $this->client->get(
			'https://' . $language . '.wikipedia.org/w/api.php',
			array(
				'action'        => 'query',
				'format'        => 'json',
				'formatversion' => 2,
				'prop'          => 'pageprops',
				'ppprop'        => 'wikibase_item|disambiguation',
				'redirects'     => 1,
				'maxlag'        => 5,
				'titles'        => implode( '|', $titles ),
			)
		);

		return array(
			'response' => $response,
			'results'  => $response['ok'] ? $this->read( $titles, (array) $response['json'] ) : array(),
		);
	}

	/**
	 * Reads the answer.
	 *
	 * @param string[]             $titles The titles asked.
	 * @param array<string, mixed> $json   The answer.
	 * @return array<string, array{state:string,qid:string}>
	 */
	private function read( array $titles, array $json ) {
		$query = (array) ( $json['query'] ?? array() );
		$moved = array();

		foreach ( array( 'normalized', 'redirects' ) as $map ) {
			foreach ( (array) ( $query[ $map ] ?? array() ) as $move ) {
				if ( isset( $move['from'], $move['to'] ) ) {
					$moved[ $map ][ (string) $move['from'] ] = (string) $move['to'];
				}
			}
		}

		$pages = array();

		foreach ( (array) ( $query['pages'] ?? array() ) as $page ) {
			if ( isset( $page['title'] ) ) {
				$pages[ (string) $page['title'] ] = (array) $page;
			}
		}

		$results = array();

		foreach ( $titles as $title ) {
			$final = $title;
			$final = $moved['normalized'][ $final ] ?? $final;
			$final = $moved['redirects'][ $final ] ?? $final;
			$page  = $pages[ $final ] ?? null;
			$props = (array) ( $page['pageprops'] ?? array() );
			$qid   = (string) ( $props['wikibase_item'] ?? '' );

			if ( null === $page || ! empty( $page['missing'] ) || ! empty( $page['invalid'] ) ) {
				$results[ $title ] = array(
					'state' => 'missing',
					'qid'   => '',
				);
			} elseif ( array_key_exists( 'disambiguation', $props ) ) {
				$results[ $title ] = array(
					'state' => 'ambiguous',
					'qid'   => '',
				);
			} elseif ( ItemReader::valid_id( $qid ) ) {
				$results[ $title ] = array(
					'state' => 'resolved',
					'qid'   => $qid,
				);
			} else {
				$results[ $title ] = array(
					'state' => 'none',
					'qid'   => '',
				);
			}
		}

		return $results;
	}
}
