<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the stops of a day of photographs and of the chain of a place (slice 606).
 *
 * @package Otherguise
 */

use Otherguise\Index\Photo\PlaceChain;
use Otherguise\Index\Photo\Stops;
use PHPUnit\Framework\TestCase;

/**
 * Pure functions: no WordPress, no database.
 *
 * @covers \Otherguise\Index\Photo\Stops
 * @covers \Otherguise\Index\Photo\PlaceChain
 */
class IndexPhotoStopsTest extends TestCase {

	/**
	 * A photograph.
	 *
	 * @param string   $url   URL.
	 * @param float    $lat   Latitude.
	 * @param float    $lon   Longitude.
	 * @param int|null $time  Minutes after 10:00, null when the time is not precise.
	 * @param array    $place Place.
	 * @return array<string, mixed>
	 */
	private function photo( $url, $lat, $lon, $time, $place = null ): array {
		return array(
			'url'   => $url,
			'lat'   => $lat,
			'lon'   => $lon,
			'time'  => null === $time ? null : 36000 + $time * 60,
			'place' => $place,
		);
	}

	/**
	 * The chain rule: consecutive photographs within the distance of the previous one are one stop, whatever the total length of the chain; a photograph
	 * far from the previous one starts another; the photographs are put in order of time first.
	 *
	 * @return void
	 */
	public function test_the_chain_rule(): void {
		$photos = array(
			$this->photo( 'c', 45.0006, 6.0000, 2 ),
			$this->photo( 'a', 45.0000, 6.0000, 0 ),
			$this->photo( 'b', 45.0003, 6.0000, 1 ),
			$this->photo( 'd', 45.0100, 6.0000, 30 ),
		);
		$stops  = Stops::group( $photos, array( 'photos_min' => 3 ) );

		$this->assertCount( 2, $stops );
		$this->assertSame( array( 'a', 'b', 'c' ), array_column( $stops[0]['photos'], 'url' ), 'About 33 m between each, 67 m from first to last: still one stop.' );
		$this->assertTrue( $stops[0]['qualifies'] );
		$this->assertSame( 2, $stops[0]['minutes'] );
		$this->assertFalse( $stops[1]['qualifies'], 'One photograph, no duration: too small.' );
		$this->assertSame( 0, $stops[1]['minutes'] );
	}

	/**
	 * A stop qualifies by the number of its photographs or by its duration.
	 *
	 * @return void
	 */
	public function test_the_minimums(): void {
		$two = array( $this->photo( 'a', 45.0, 6.0, 0 ), $this->photo( 'b', 45.0, 6.0, 40 ) );

		$this->assertTrue( Stops::group( $two, array( 'minutes_min' => 20 ) )[0]['qualifies'], 'Two photographs forty minutes apart: a stay.' );
		$this->assertFalse( Stops::group( $two, array( 'minutes_min' => 60 ) )[0]['qualifies'] );

		$three = array( $this->photo( 'a', 45.0, 6.0, 0 ), $this->photo( 'b', 45.0, 6.0, 0 ), $this->photo( 'c', 45.0, 6.0, 0 ) );

		$this->assertTrue( Stops::group( $three, array( 'photos_min' => 3 ) )[0]['qualifies'] );
		$this->assertFalse( Stops::group( $three, array( 'photos_min' => 4 ) )[0]['qualifies'] );
	}

	/**
	 * A photograph with no precise time is grouped by place only (the same site and the same commune), has no duration, and is not chained to the
	 * photographs that have a time.
	 *
	 * @return void
	 */
	public function test_no_precise_time_groups_by_place(): void {
		$here    = array(
			'site'       => 'Les Arberons',
			'settlement' => 'Beaufort',
		);
		$another = array(
			'site'       => '',
			'settlement' => 'Doron',
		);
		$stops   = Stops::group(
			array(
				$this->photo( 'a', 45.0, 6.0, null, $here ),
				$this->photo( 'b', 45.5, 6.5, null, $here ),
				$this->photo( 'c', 45.0, 6.0, null, $another ),
				$this->photo( 'd', 45.0, 6.0, null, null ),
				$this->photo( 'e', 45.0, 6.0, 0 ),
			),
			array( 'photos_min' => 2 )
		);

		$this->assertCount( 4, $stops );
		$this->assertSame( array( 'e' ), array_column( $stops[0]['photos'], 'url' ) );
		$this->assertSame( array( 'a', 'b' ), array_column( $stops[1]['photos'], 'url' ), 'Same site and commune: one stop, far apart or not.' );
		$this->assertSame( 0, $stops[1]['minutes'] );
		$this->assertNull( $stops[3]['place'], 'No place yet.' );
	}

	/**
	 * The distance in metres.
	 *
	 * @return void
	 */
	public function test_the_distance(): void {
		$this->assertEqualsWithDelta(
			111195,
			Stops::metres(
				array(
					'lat' => 0,
					'lon' => 0,
				),
				array(
					'lat' => 1,
					'lon' => 0,
				)
			),
			100
		);
		$this->assertEqualsWithDelta(
			0,
			Stops::metres(
				array(
					'lat' => 45,
					'lon' => 6,
				),
				array(
					'lat' => 45,
					'lon' => 6,
				)
			),
			0.001
		);
	}

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
