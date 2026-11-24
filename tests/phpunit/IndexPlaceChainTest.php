<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the chain of a place (slice 606; the stops were removed by slice 623).
 *
 * @package Otherguise
 */

use Otherguise\Index\Photo\PlaceChain;
use PHPUnit\Framework\TestCase;

/**
 * Pure functions: no WordPress, no database.
 *
 * @covers \Otherguise\Index\Photo\PlaceChain
 */
class IndexPlaceChainTest extends TestCase {

	/**
	 * Every level is a topic, from the country down; the lowest place is the entry; an empty level or a name repeated is skipped.
	 *
	 * @return void
	 */
	public function test_the_chain_of_a_place(): void {
		$place = array(
			'levels' => array(
				'country'    => 'France',
				'state'      => 'Auvergne-Rhône-Alpes',
				'county'     => 'Savoie',
				'settlement' => 'Beaufort',
				'site'       => 'Les Arberons',
				'locality'   => 'Ailleurs',
				'kind'       => 'place hamlet',
			),
		);
		$leaf  = PlaceChain::candidate( $place, 'image/address', array( 'image:abc' ) );
		$names = array();

		for ( $c = $leaf; null !== $c; $c = $c->parent ) {
			array_unshift( $names, $c->label );
		}

		$this->assertSame( array( 'France', 'Auvergne-Rhône-Alpes', 'Savoie', 'Beaufort', 'Les Arberons' ), $names );
		$this->assertSame( 'image/address', $leaf->source );
		$this->assertSame( array( 'image:abc' ), $leaf->via );
		$this->assertSame( 'site', $leaf->kind );
		$this->assertSame( 'commune', $leaf->parent->kind );
		$this->assertSame( 'admin2', $leaf->parent->parent->kind );
		$this->assertSame( 'admin1', $leaf->parent->parent->parent->kind );
		$this->assertSame( 'country', $leaf->parent->parent->parent->parent->kind );

		// No commune and no county (a point on the coast): the place hangs from the highest level that exists; a repeated name is one level.
		$coast = PlaceChain::candidate(
			array(
				'levels' => array(
					'country'  => 'France',
					'state'    => 'France',
					'locality' => 'Pointe',
				),
			),
			'trace/end',
			array()
		);

		$this->assertSame( 'Pointe', $coast->label );
		$this->assertSame( 'France', $coast->parent->label );
		$this->assertNull( $coast->parent->parent );

		$this->assertNull( PlaceChain::candidate( array( 'levels' => array() ), 'image/address', array() ), 'A place with no name makes no topic.' );
	}

	/**
	 * Two levels with the same name are two topics (slice 614): the province and the city of Zaragoza, the department and the commune of Paris; only a
	 * first division that repeats the country is not one; a country with no intermediate level has the commune under the country.
	 *
	 * @return void
	 */
	public function test_levels_with_the_same_name_are_kept(): void {
		$spain = PlaceChain::candidate(
			array(
				'levels' => array(
					'country'    => 'España',
					'state'      => 'Aragón',
					'county'     => 'Zaragoza',
					'settlement' => 'Zaragoza',
				),
			),
			'image/address',
			array()
		);
		$names = array();
		$kinds = array();

		for ( $c = $spain; null !== $c; $c = $c->parent ) {
			array_unshift( $names, $c->label );
			array_unshift( $kinds, $c->kind );
		}

		$this->assertSame( array( 'España', 'Aragón', 'Zaragoza', 'Zaragoza' ), $names );
		$this->assertSame( array( 'country', 'admin1', 'admin2', 'commune' ), $kinds, 'The province and the city have each their kind.' );

		$paris = PlaceChain::candidate(
			array(
				'levels' => array(
					'country'    => 'France',
					'state'      => 'Île-de-France',
					'county'     => 'Paris',
					'settlement' => 'Paris',
				),
			),
			'image/address',
			array()
		);

		$this->assertSame( 'Paris', $paris->label );
		$this->assertSame( 'Paris', $paris->parent->label );
		$this->assertSame( 'admin2', $paris->parent->kind );

		$monaco = PlaceChain::candidate(
			array(
				'levels' => array(
					'country'    => 'Monaco',
					'state'      => 'Monaco',
					'settlement' => 'Monaco-Ville',
				),
			),
			'image/address',
			array()
		);

		$this->assertSame( 'Monaco-Ville', $monaco->label );
		$this->assertSame( 'Monaco', $monaco->parent->label );
		$this->assertSame( 'country', $monaco->parent->kind );
		$this->assertNull( $monaco->parent->parent, 'No intermediate level: the commune is under the country.' );
	}

	/**
	 * The names of the levels in a chain, from the country down, with their kinds.
	 *
	 * @param array $levels The levels of the answer.
	 * @return array<int, string>
	 */
	private function chain_of( array $levels ): array {
		$chain = array();

		for ( $c = PlaceChain::candidate( array( 'levels' => $levels ), 'image/address', array() ); null !== $c; $c = $c->parent ) {
			array_unshift( $chain, $c->kind . ':' . $c->label );
		}

		return $chain;
	}

	/**
	 * The levels come from the numbered administrative levels (slice 617): in Aragon `county` is the comarca and the province is `level6`; the comarca is
	 * a third division between the province and the commune.
	 *
	 * @return void
	 */
	public function test_the_numbered_levels_give_the_province(): void {
		$this->assertSame(
			array( 'country:Espagne', 'admin1:Aragon', 'admin2:Huesca', 'admin3:Somontano de Barbastro', 'commune:Lascellas-Ponzano', 'site:San Román' ),
			$this->chain_of(
				array(
					'country'    => 'Espagne',
					'state'      => 'Aragon',
					'county'     => 'Somontano de Barbastro',
					'settlement' => 'Lascellas-Ponzano',
					'site'       => 'San Román',
					'admin'      => array(
						'level8' => 'Lascellas-Ponzano',
						'level7' => 'Somontano de Barbastro',
						'level6' => 'Huesca',
						'level4' => 'Aragon',
					),
				)
			)
		);

		// France: the département is level 6 and the arrondissement level 7 (a third division); the commune is level 8.
		$this->assertSame(
			array( 'country:France', 'admin1:Occitanie', 'admin2:Hérault', 'admin3:Arrondissement de Montpellier', 'commune:Pérols' ),
			$this->chain_of(
				array(
					'country'    => 'France',
					'state'      => 'Occitanie',
					'county'     => 'Hérault',
					'settlement' => 'Pérols',
					'admin'      => array(
						'level8' => 'Pérols',
						'level7' => 'Arrondissement de Montpellier',
						'level6' => 'Hérault',
						'level4' => 'Occitanie',
					),
				)
			)
		);

		// Poland: the gmina is level 7 and is the commune: it is not a third division.
		$this->assertSame(
			array( 'country:Polska', 'admin1:Małopolskie', 'admin2:Tatrzański', 'commune:Zakopane' ),
			$this->chain_of(
				array(
					'country'    => 'Polska',
					'state'      => 'Małopolskie',
					'county'     => 'Tatrzański',
					'settlement' => 'Zakopane',
					'admin'      => array(
						'level7' => 'Zakopane',
						'level6' => 'Tatrzański',
						'level4' => 'Małopolskie',
					),
				)
			)
		);
	}

	/**
	 * A numbered level that is missing is a level that is missing: without `level6` the comarca of `county` is **not** taken for the province; and an
	 * answer without any numbered level (an old one of the cache) keeps `state` and `county`.
	 *
	 * @return void
	 */
	public function test_a_missing_level_is_missing_and_an_old_answer_falls_back(): void {
		$this->assertSame(
			array( 'country:Espagne', 'admin1:Navarra', 'commune:Pamplona' ),
			$this->chain_of(
				array(
					'country'    => 'Espagne',
					'state'      => 'Navarra',
					'county'     => 'Cuenca de Pamplona',
					'settlement' => 'Pamplona',
					'admin'      => array(
						'level8' => 'Pamplona',
						'level4' => 'Navarra',
					),
				)
			),
			'No province in the answer: none is made up from the comarca.'
		);

		$this->assertSame(
			array( 'country:France', 'admin1:Occitanie', 'admin2:Hérault', 'commune:Pérols' ),
			$this->chain_of(
				array(
					'country'    => 'France',
					'state'      => 'Occitanie',
					'county'     => 'Hérault',
					'settlement' => 'Pérols',
				)
			),
			'An answer with no numbered level: the names of the answer.'
		);
	}

	/**
	 * The Wikidata item belongs to the object found: only a place gets it, never a road or a house, and only a well formed identifier.
	 *
	 * @return void
	 */
	public function test_the_wikidata_key_only_for_a_place_object(): void {
		$answer = array( 'features' => array( array( 'properties' => array( 'geocoding' => array( 'extra' => array( 'wikidata' => 'Q123' ) ) ) ) ) );
		$base   = array(
			'levels' => array(
				'settlement' => 'Beaufort',
				'site'       => 'Les Arberons',
				'kind'       => 'place hamlet',
			),
			'answer' => $answer,
		);

		$this->assertSame( array( 'wikidata:Q123' ), PlaceChain::candidate( $base, 'image/address', array() )->keys );
		$this->assertSame( array(), PlaceChain::candidate( $base, 'image/address', array() )->parent->keys, 'Never the commune.' );

		$road                    = $base;
		$road['levels']['kind'] = 'highway residential';

		$this->assertSame( array(), PlaceChain::candidate( $road, 'image/address', array() )->keys );

		$bad                                                                               = $base;
		$bad['answer']['features'][0]['properties']['geocoding']['extra']['wikidata'] = 'Q12; DROP';

		$this->assertSame( array(), PlaceChain::candidate( $bad, 'image/address', array() )->keys );
	}
}
