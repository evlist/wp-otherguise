<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the photographs and the GPX tracks as sources of places (slice 606), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Index\Cli\PhotosCommand;
use Otherguise\Index\Harvest\Signal;
use Otherguise\Index\Indexer;
use Otherguise\Index\Mentions;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Photo\Geo;
use Otherguise\Index\Resolver;
use Otherguise\Index\Source\Photos;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';

/**
 * Media Helper is a double: a table of answers by URL. The statements are real.
 *
 * @covers \Otherguise\Index\Source\Photos
 * @covers \Otherguise\Index\Source\Partial
 * @covers \Otherguise\Index\Photo\Geo
 * @covers \Otherguise\Index\Cli\PhotosCommand
 * @covers \Otherguise\Index\Indexer
 */
class IndexPhotosDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The topics.
	 *
	 * @var Otherguise_Test_Memory_Topics
	 */
	private $topics;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * What Media Helper knows, by URL.
	 *
	 * @var array<string, array|null>
	 */
	private $files = array();

	/**
	 * The pending URLs, by post.
	 *
	 * @var array<int, string[]>
	 */
	private $pending = array();

	/**
	 * The source.
	 *
	 * @var Photos
	 */
	private $photos;

	/**
	 * The indexer.
	 *
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * Wires the statements, the double of Media Helper and the indexer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Otherguise_Test_Memory_Kinds::install();
		otherguise_test_wp_objects( array( new WP_Post( 1 ), new WP_Post( 2 ) ) );

		$module = new IndexModule();
		$site   = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $module ) {
					$module->register_predicates( $registry );
				},
			)
		);

		$statements     = $site->statements;
		$this->topics   = new Otherguise_Test_Memory_Topics();
		$this->mentions = new Mentions(
			static function () use ( $statements ) {
				return $statements;
			}
		);
		$this->photos   = new Photos(
			new Geo( fn( array $urls ) => array_intersect_key( $this->files, array_flip( $urls ) ) ),
			static fn() => array(
				'photos_min'   => 3,
				'minutes_min'  => 20,
				'chain_metres' => 50,
			),
			fn( $post ) => $this->pending[ $post ] ?? array(),
			function ( $post, array $urls ) {
				if ( array() === $urls ) {
					unset( $this->pending[ $post ] );
				} else {
					$this->pending[ $post ] = $urls;
				}
			},
			static fn( $url ) => 0 !== strpos( $url, 'https://upload.wikimedia.org/' )
		);
		$this->indexer  = new Indexer( new Resolver( $this->topics ), $this->mentions, array( $this->photos ), static fn() => true );
	}

	/**
	 * What Media Helper answers for a photograph.
	 *
	 * @param float      $lat   Latitude.
	 * @param int        $min   Minutes after 10:00.
	 * @param array|null $place Its place, null while the cell was not looked for.
	 * @param string     $precision Precision of the time.
	 * @return array<string, mixed>
	 */
	private function shot( $lat, $min, $place, $precision = 'second' ): array {
		return array(
			'type'            => 'file',
			'state'           => 'positioned',
			'lat'             => $lat,
			'lon'             => 6.0,
			'taken_at'        => '2026-10-01 ' . sprintf( '%02d:%02d:00', 10 + intdiv( $min, 60 ), $min % 60 ),
			'taken_source'    => 'embedded',
			'taken_precision' => $precision,
			'place'           => $place,
		);
	}

	/**
	 * A place found by Media Helper.
	 *
	 * @param string $site       Site.
	 * @param string $settlement Commune.
	 * @return array<string, mixed>
	 */
	private function place( $site, $settlement ): array {
		return array(
			'state'  => 'found',
			'levels' => array(
				'country'    => 'France',
				'state'      => 'Auvergne-Rhône-Alpes',
				'county'     => 'Savoie',
				'settlement' => $settlement,
				'site'       => $site,
				'kind'       => 'place hamlet',
			),
		);
	}

	/**
	 * A page with photographs and a track: the stops that are big enough make mentions of the source image/address on the lowest place, the track
	 * makes one mention for its start and one for its end; what is not usable is left out and counted, and no URL is kept.
	 *
	 * @return void
	 */
	public function test_the_photographs_and_the_tracks_make_mentions(): void {
		$arberons = $this->place( 'Les Arberons', 'Beaufort' );

		$this->files = array(
			'/a.jpg' => $this->shot( 45.0000, 0, $arberons ),
			'/b.jpg' => $this->shot( 45.0003, 1, $arberons ),
			'/c.jpg' => $this->shot( 45.0006, 2, $arberons ),
			'/far.jpg' => $this->shot( 46.0, 90, $this->place( 'Lac', 'Doron' ) ),
			'/none.jpg' => array(
				'type'  => 'file',
				'state' => 'none',
			),
			'/day.jpg' => $this->shot( 45.0, 0, $arberons, 'day' ),
			'/day.gpx' => array(
				'type'  => 'track',
				'state' => 'positioned',
				'start' => array( 'place' => $this->place( 'Gîte', 'Beaufort' ) ),
				'end'   => array( 'place' => $this->place( 'Refuge', 'Doron' ) ),
			),
		);

		$this->photos->handle(
			1,
			array(
				new Signal( Signal::IMAGE, '/a.jpg', 'content', 'src' ),
				new Signal( Signal::IMAGE, '/b.jpg', 'content', 'src' ),
				new Signal( Signal::IMAGE, '/c.jpg', 'content', 'src' ),
				new Signal( Signal::IMAGE, '/far.jpg', 'content', 'src' ),
				new Signal( Signal::IMAGE, '/none.jpg', 'content', 'src' ),
				new Signal( Signal::IMAGE, '/day.jpg', 'content', 'src' ),
				new Signal( Signal::IMAGE, '/unknown.jpg', 'content', 'src' ),
				new Signal( Signal::IMAGE, 'https://upload.wikimedia.org/x.jpg', 'content', 'src' ),
				new Signal( Signal::GPX_FILE, '/day.gpx', 'content', 'href' ),
			)
		);

		$report = $this->indexer->index_post( 1 );
		$found  = $this->mentions->of_post( 1 );
		$sources = array();

		foreach ( $found as $id => $mention ) {
			$topic = $this->topics->get( $id );

			$sources[ $topic['label'] ] = $mention['sources'];
		}

		$this->assertSame(
			array(
				'Les Arberons' => array( 'image/address' ),
				'Gîte'         => array( 'trace/start' ),
				'Refuge'       => array( 'trace/end' ),
			),
			$sources,
			'The stop of three photographs, the start and the end; the lone photograph is too small; none, day-only time with one photograph: too small.'
		);

		$arberons_id = array_search( 'Les Arberons', array_map( fn( $id ) => $this->topics->get( $id )['label'], array_keys( $found ) ), true );
		$id          = array_keys( $found )[ $arberons_id ];
		$via         = $found[ $id ]['details']['image/address']['via'];

		$this->assertCount( 3, $via, 'One via per photograph, a short hash and not the URL.' );
		$this->assertMatchesRegularExpression( '/^image:[0-9a-f]{12}$/', $via[0] );
		$this->assertStringNotContainsString( 'a.jpg', implode( ' ', $via ) );
		$this->assertSame( 'Beaufort', $this->topics->get( $this->topics->get( $id )['parent'] )['label'] );

		$left = $this->photos->last( 1 )['left_out'];

		$this->assertSame( 1, $left['not_of_the_site'] );
		$this->assertSame( 1, $left['no_data'] );
		$this->assertSame( 1, $left['no_position'] );
		$this->assertSame( 1, $left['no_precise_time'] );
		$this->assertSame( 0, $left['no_place_yet'] );
		$this->assertArrayNotHasKey( 1, $this->pending, 'Nothing waits: no URL is kept.' );
		$this->assertGreaterThan( 0, $report['added'] );
	}

	/**
	 * A photograph whose place was not looked for yet is not an error: its URL waits, and the second pass (no page, no network) asks again and
	 * brings the mentions in line; a post that nothing is known about is left as it is.
	 *
	 * @return void
	 */
	public function test_the_second_pass_for_the_places_that_were_not_known(): void {
		$this->files = array(
			'/a.jpg' => $this->shot( 45.0000, 0, null ),
			'/b.jpg' => $this->shot( 45.0003, 1, null ),
			'/c.jpg' => $this->shot( 45.0006, 2, null ),
		);

		$this->photos->handle(
			1,
			array(
				new Signal( Signal::IMAGE, '/a.jpg', 'content', 'src' ),
				new Signal( Signal::IMAGE, '/b.jpg', 'content', 'src' ),
				new Signal( Signal::IMAGE, '/c.jpg', 'content', 'src' ),
			)
		);
		$this->indexer->index_post( 1 );

		$this->assertSame( array(), $this->mentions->of_post( 1 ), 'No place yet: no topic.' );
		$this->assertSame( array( '/a.jpg', '/b.jpg', '/c.jpg' ), $this->pending[1] );
		$this->assertTrue( $this->photos->knows( 1 ) );
		$this->assertFalse( $this->photos->knows( 2 ) );

		// Media Helper looks the cells up: the second pass reads no page.
		$here = $this->place( 'Les Arberons', 'Beaufort' );

		foreach ( array( '/a.jpg', '/b.jpg', '/c.jpg' ) as $url ) {
			$this->files[ $url ]['place'] = $here;
		}

		$this->indexer->index_post( 1, array( Photos::SLUG ) );

		$this->assertCount( 1, $this->mentions->of_post( 1 ) );
		$this->assertArrayNotHasKey( 1, $this->pending, 'The URLs are forgotten once their place is known.' );

		// Nothing is known about this post (no page being read, nothing pending): its mentions stay.
		$this->indexer->index_post( 1, array( Photos::SLUG ) );

		$this->assertCount( 1, $this->mentions->of_post( 1 ), 'A source that does not know the post does not withdraw its mentions.' );

		// The page is read again and the photographs are gone: the mentions go.
		$this->photos->handle( 1, array() );
		$this->indexer->index_post( 1 );

		$this->assertSame( array(), $this->mentions->of_post( 1 ) );
	}

	/**
	 * A dry run keeps nothing; the command prints the stops and what is left out; without a post it makes the second pass.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$here        = $this->place( 'Les Arberons', 'Beaufort' );
		$this->files = array(
			'/a.jpg' => $this->shot( 45.0000, 0, $here ),
			'/b.jpg' => $this->shot( 45.0003, 30, $here ),
			'/c.jpg' => $this->shot( 45.5, 60, null ),
		);
		$lines       = array();
		$done        = array();
		$command     = new PhotosCommand(
			static fn( $on ) => true,
			function ( $post, $dry ) {
				$this->photos->handle(
					$post,
					array(
						new Signal( Signal::IMAGE, '/a.jpg', 'content', 'src' ),
						new Signal( Signal::IMAGE, '/b.jpg', 'content', 'src' ),
						new Signal( Signal::IMAGE, '/c.jpg', 'content', 'src' ),
					)
				);

				return array(
					'ok'       => true,
					'message'  => 'Dry run: nothing was written.',
					'analysis' => $this->photos->analyse( $post, $dry ),
				);
			},
			static fn() => 3,
			static fn() => array(
				'posts'    => 2,
				'urls'     => 5,
				'left_out' => array( 'no_data' => 4 ),
			),
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $line ) use ( &$done ) {
				$done[] = $line;
			},
			static function () {
				throw new RuntimeException( 'Unexpected failure.' );
			}
		);

		$command->run(
			array(),
			array(
				'post' => 1,
				'dry-run' => true,
			)
		);

		$this->assertSame( array( 'stop 1: 2 photograph(s), 30 min, France > Auvergne-Rhône-Alpes > Savoie > Beaufort > Les Arberons', 'stop 2: 1 photograph(s), 0 min, no place yet', 'left out, no place yet: 1' ), $lines );
		$this->assertSame( array( '/c.jpg' ), $this->photos->last( 1 )['pending'] );
		$this->assertArrayNotHasKey( 1, $this->pending, 'A dry run keeps nothing.' );

		$done  = array();
		$lines = array();

		$command->run( array(), array() );
		$command->status( array(), array() );

		$this->assertSame( array( '3 post(s) looked at again.', 'Done.' ), $done );
		$this->assertSame( array( '2 post(s) wait for a place, 5 file(s).', 'left out, no data: 4' ), $lines );
	}

	/**
	 * With `--verbose` the dry run shows, under each stop, the levels of its place as Media Helper gave them and each photograph with its time, its
	 * position and its address (the tool to find which photograph gives a wrong place); without it the lines are the same as before.
	 *
	 * @return void
	 */
	public function test_verbose_shows_the_photographs_and_the_levels(): void {
		$place       = $this->place( 'San Román', 'Lascellas-Ponzano' );
		$place['levels']['admin'] = array(
			'level4' => 'Aragón',
			'level6' => 'Huesca',
		);

		$this->files = array(
			'/a.jpg' => $this->shot( 42.0000, 0, $place ),
			'/b.jpg' => $this->shot( 42.0003, 30, $place ),
		);

		$lines   = array();
		$command = new PhotosCommand(
			static fn( $on ) => true,
			function ( $post, $dry ) {
				$this->photos->handle(
					$post,
					array(
						new Signal( Signal::IMAGE, '/a.jpg', 'content', 'src' ),
						new Signal( Signal::IMAGE, '/b.jpg', 'content', 'src' ),
					)
				);

				return array(
					'ok'       => true,
					'message'  => 'Dry run: nothing was written.',
					'analysis' => $this->photos->analyse( $post, $dry ),
				);
			},
			static fn() => 0,
			static fn() => array(),
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static fn() => null,
			static function () {
				throw new RuntimeException( 'Unexpected failure.' );
			}
		);

		$command->run(
			array(),
			array(
				'post' => 1,
				'dry-run' => true,
			)
		);

		$this->assertCount( 1, $lines, 'Without --verbose: the stop only.' );

		$lines = array();

		$command->run(
			array(),
			array(
				'post' => 1,
				'dry-run' => true,
				'verbose' => true,
			)
		);

		$this->assertStringStartsWith( 'stop 1: 2 photograph(s), 30 min, ', $lines[0] );
		$this->assertStringStartsWith( '    levels: {', $lines[1] );
		$this->assertStringContainsString( '"level6":"Huesca"', $lines[1], 'The numbered levels are shown.' );
		$this->assertSame( '    10:00  42,6  /a.jpg', $lines[2] );
		$this->assertSame( '    10:30  42.0003,6  /b.jpg', $lines[3] );
	}

	/**
	 * A thumbnail and its original are one photograph (same position, same time, same name once the size is left out): a stop counts it once, so two
	 * photographs and a thumbnail do not make the three of a stop; the mention has the `via` of both addresses.
	 *
	 * @return void
	 */
	public function test_a_thumbnail_and_its_original_are_one_photograph(): void {
		$here        = $this->place( 'Les Arberons', 'Beaufort' );
		$this->files = array(
			'/up/a.jpg'                => $this->shot( 45.0000, 0, $here ),
			'/up/thumbnails/b-1024x577.jpg' => $this->shot( 45.0003, 1, $here ),
			'/up/b.jpg'                => $this->shot( 45.0003, 1, $here ),
		);

		$signals = array(
			new Signal( Signal::IMAGE, '/up/a.jpg', 'content', 'src' ),
			new Signal( Signal::IMAGE, '/up/thumbnails/b-1024x577.jpg', 'content', 'src' ),
			new Signal( Signal::IMAGE, '/up/b.jpg', 'content', 'href' ),
		);

		$this->photos->handle( 1, $signals );
		$this->indexer->index_post( 1 );

		$this->assertSame( array(), $this->mentions->of_post( 1 ), 'Two photographs, not three: too small.' );
		$this->assertSame( 2, $this->photos->last( 1 )['left_out']['small_stop'], 'Two photographs left out, not three.' );

		$this->files['/up/c.jpg'] = $this->shot( 45.0006, 2, $here );
		$this->photos->handle( 1, array_merge( $signals, array( new Signal( Signal::IMAGE, '/up/c.jpg', 'content', 'src' ) ) ) );
		$this->indexer->index_post( 1 );

		$found = $this->mentions->of_post( 1 );

		$this->assertCount( 1, $found, 'Three photographs now.' );
		$this->assertCount( 4, reset( $found )['details']['image/address']['via'], 'The via of each of the four addresses.' );
	}
}
