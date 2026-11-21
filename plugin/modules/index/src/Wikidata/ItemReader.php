<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Reads the label and the classes of Wikidata items.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Wikidata;

defined( 'ABSPATH' ) || exit;

/**
 * Two light queries to the query service (slice 604), up to 50 items or classes each:
 *
 * - the **label** and the **classes** (`instance of`, P31) of items;
 * - the **ancestors** (`subclass of`, P279, at any depth) of classes.
 *
 * Only identifiers that match `^Q[1-9][0-9]*$` are ever put in a query: a title or a label never is (no injection).
 */
final class ItemReader {
	public const BATCH    = 50;
	public const ENDPOINT = 'https://query.wikidata.org/sparql';

	/**
	 * The client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Returns the address of the query service: `fn(): string`.
	 *
	 * @var callable
	 */
	private $endpoint;

	/**
	 * Builds the reader.
	 *
	 * @param Client        $client   The client.
	 * @param callable|null $endpoint The address of the query service; default the option `index_wikidata_sparql`, else the public one.
	 */
	public function __construct( Client $client, $endpoint = null ) {
		$this->client   = $client;
		$this->endpoint = $endpoint ?? static fn() => (string) apply_filters( 'index_wikidata_sparql', self::ENDPOINT );
	}

	/**
	 * Tells whether a string is the identifier of an item.
	 *
	 * @param string $id Identifier.
	 * @return bool
	 */
	public static function valid_id( $id ) {
		return 1 === preg_match( '/^Q[1-9][0-9]*$/', (string) $id );
	}

	/**
	 * The label (in the language, then in English) and the classes of items.
	 *
	 * @param string[] $ids      Identifiers, at most 50.
	 * @param string   $language Language of the labels (`fr`).
	 * @return array{response:array,items:array<string,array{label:string,classes:array<string,string>}>}
	 */
	public function read( array $ids, $language ) {
		$ids = $this->ids( $ids );

		if ( array() === $ids || ! TitleResolver::valid_language( $language ) ) {
			return $this->nothing();
		}

		$values   = 'wd:' . implode( ' wd:', $ids );
		$response = $this->ask( 'SELECT ?item ?itemLabel ?class ?classLabel WHERE { VALUES ?item { ' . $values . ' } OPTIONAL { ?item wdt:P31 ?class } SERVICE wikibase:label { bd:serviceParam wikibase:language "' . $language . ',en". } }' );
		$items    = array();

		foreach ( $ids as $id ) {
			$items[ $id ] = array(
				'label'   => '',
				'classes' => array(),
			);
		}

		foreach ( $response['ok'] ? $this->bindings( (array) $response['json'] ) : array() as $row ) {
			$item = $this->id_of( $row['item'] ?? '' );

			if ( ! isset( $items[ $item ] ) ) {
				continue;
			}

			$items[ $item ]['label'] = $this->label( $row['itemLabel'] ?? '', $item );
			$class                   = $this->id_of( $row['class'] ?? '' );

			if ( '' !== $class ) {
				$items[ $item ]['classes'][ $class ] = $this->label( $row['classLabel'] ?? '', $class );
			}
		}

		return array(
			'response' => $response,
			'items'    => $response['ok'] ? $items : array(),
		);
	}

	/**
	 * The ancestors of classes, each class included.
	 *
	 * @param string[] $classes Identifiers, at most 50.
	 * @return array{response:array,ancestors:array<string,string[]>}
	 */
	public function ancestors( array $classes ) {
		$classes = $this->ids( $classes );

		if ( array() === $classes ) {
			return array(
				'response'  => $this->nothing()['response'],
				'ancestors' => array(),
			);
		}

		$response = $this->ask( 'SELECT ?class ?ancestor WHERE { VALUES ?class { wd:' . implode( ' wd:', $classes ) . ' } ?class wdt:P279* ?ancestor }' );
		$found    = array();

		foreach ( $classes as $class ) {
			$found[ $class ] = array( $class );
		}

		foreach ( $response['ok'] ? $this->bindings( (array) $response['json'] ) : array() as $row ) {
			$class    = $this->id_of( $row['class'] ?? '' );
			$ancestor = $this->id_of( $row['ancestor'] ?? '' );

			if ( isset( $found[ $class ] ) && '' !== $ancestor && ! in_array( $ancestor, $found[ $class ], true ) ) {
				$found[ $class ][] = $ancestor;
			}
		}

		return array(
			'response'  => $response,
			'ancestors' => $response['ok'] ? $found : array(),
		);
	}

	/**
	 * Asks the query service.
	 *
	 * @param string $sparql The query.
	 * @return array The answer of the client.
	 */
	private function ask( $sparql ) {
		return $this->client->get(
			( $this->endpoint )(),
			array(
				'query'  => $sparql,
				'format' => 'json',
			)
		);
	}

	/**
	 * The valid identifiers of a list, without duplicates, at most 50.
	 *
	 * @param string[] $ids Identifiers.
	 * @return string[]
	 */
	private function ids( array $ids ) {
		return array_slice( array_values( array_unique( array_filter( array_map( 'strval', $ids ), array( self::class, 'valid_id' ) ) ) ), 0, self::BATCH );
	}

	/**
	 * The rows of an answer of the query service.
	 *
	 * @param array<string, mixed> $json The answer.
	 * @return array<int, array<string, string>> Each row as `variable => value`.
	 */
	private function bindings( array $json ) {
		$rows = array();

		foreach ( (array) ( $json['results']['bindings'] ?? array() ) as $binding ) {
			$row = array();

			foreach ( (array) $binding as $name => $cell ) {
				$row[ (string) $name ] = is_array( $cell ) && isset( $cell['value'] ) ? (string) $cell['value'] : '';
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * The identifier at the end of the address of an entity, or an empty string.
	 *
	 * @param string $uri Address.
	 * @return string
	 */
	private function id_of( $uri ) {
		$id = (string) substr( (string) strrchr( (string) $uri, '/' ), 1 );

		return self::valid_id( $id ) ? $id : '';
	}

	/**
	 * A label; the label service answers the identifier itself when an item has no label, which is not a label.
	 *
	 * @param string $label Label.
	 * @param string $id    Identifier.
	 * @return string
	 */
	private function label( $label, $id ) {
		return $label === $id ? '' : trim( (string) $label );
	}

	/**
	 * What is returned when there is nothing to ask.
	 *
	 * @return array{response:array,items:array}
	 */
	private function nothing() {
		return array(
			'response' => array(
				'ok'          => false,
				'class'       => 'fatal',
				'status'      => 0,
				'json'        => null,
				'retry_after' => 0,
				'error'       => 'Nothing to ask, or a language that is not a code.',
			),
			'items'    => array(),
		);
	}
}
