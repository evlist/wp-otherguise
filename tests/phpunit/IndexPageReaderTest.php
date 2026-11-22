<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the reader of a rendered page (slice 601).
 *
 * @package Otherguise
 */

use Otherguise\Index\Cli\IndexCommand;
use Otherguise\Index\Indexer;
use Otherguise\Index\Mentions;
use Otherguise\Index\Resolver;
use Otherguise\Index\Harvest\PageReader;
use Otherguise\Index\Harvest\Signal;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-html-api.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';

/**
 * No database and no WordPress: the tag processor of the core (`OTHERGUISE_WP_CORE`) and excerpts of public pages of the blog.
 *
 * @covers \Otherguise\Index\Harvest\PageReader
 * @covers \Otherguise\Index\Harvest\Signal
 * @covers \Otherguise\Index\Cli\IndexCommand::read
 * @covers \Otherguise\Index\Cli\IndexCommand::load
 */
class IndexPageReaderTest extends TestCase {

	/**
	 * The reader.
	 *
	 * @var PageReader
	 */
	private $reader;

	/**
	 * Loads the tag processor, or skips.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();

		if ( ! Otherguise_Test_Html_Api::load() ) {
			$this->markTestSkipped( 'The tag processor is not available (set OTHERGUISE_WP_CORE to wp-includes, with class-wp-token-map.php).' );
		}

		$this->reader = new PageReader();
	}

	/**
	 * Reads a fixture.
	 *
	 * @param string $name File name.
	 * @return Signal[]
	 */
	private function read( $name ): array {
		return $this->reader->signals( (string) file_get_contents( __DIR__ . '/fixtures/index/' . $name ) );
	}

	/**
	 * The values of the signals of a type, with their scope.
	 *
	 * @param Signal[] $signals Signals.
	 * @param string   $type    Type.
	 * @return string[] `scope:value`.
	 */
	private function of( array $signals, $type ): array {
		$found = array();

		foreach ( $signals as $signal ) {
			if ( $type === $signal->type ) {
				$found[] = $signal->scope . ':' . $signal->value;
			}
		}

		return $found;
	}

	/**
	 * The Wikipedia links of the contents only: the label comes from the address, the fragment and the old form are read, the special
	 * pages and the files are not, and a menu, a list of posts and a footer are never read.
	 *
	 * @return void
	 */
	public function test_wikipedia_links_of_the_contents(): void {
		$signals = $this->read( 'etroubles-beaufort.html' );

		$this->assertSame(
			array( 'content:Beaufort (fromage)', 'content:Col de la Forclaz (Valais)', 'content:Fontintes (Liddes)' ),
			$this->of( $signals, Signal::WIKIPEDIA_LINK )
		);

		$languages = array();

		foreach ( $signals as $signal ) {
			if ( Signal::WIKIPEDIA_LINK === $signal->type ) {
				$languages[] = $signal->data['language'];
			}
		}

		$this->assertSame( array( 'fr', 'en', 'fr' ), $languages );
	}

	/**
	 * Wikidata items and OpenStreetMap objects are read; the attribution of a map, a map position and a tourist site are not.
	 *
	 * @return void
	 */
	public function test_wikidata_and_openstreetmap(): void {
		$signals = $this->read( 'etroubles-beaufort.html' );

		$this->assertSame( array( 'content:Q42' ), $this->of( $signals, Signal::WIKIDATA_LINK ) );
		$this->assertSame( array( 'content:node/12345', 'content:relation/9' ), $this->of( $signals, Signal::OSM_OBJECT ) );

		foreach ( $signals as $signal ) {
			$this->assertStringNotContainsString( 'ruche', strtolower( $signal->value ) );
			$this->assertStringNotContainsString( 'copyright', $signal->value );
		}
	}

	/**
	 * The GPX link of the plugin that shows the track is in `<main>` but outside the contents.
	 *
	 * @return void
	 */
	public function test_the_gpx_file_outside_the_contents(): void {
		$this->assertSame(
			array( 'page:https://e.vli.st/wp-content/uploads/cumulus/eric/2026/10/01/20261001-vanlife.gpx' ),
			$this->of( $this->read( 'etroubles-beaufort.html' ), Signal::GPX_FILE )
		);
	}

	/**
	 * An address with a query string is still a GPX file, and an attribute that is not an address (`data-src` of an image) is not.
	 *
	 * @return void
	 */
	public function test_a_gpx_file_with_a_query_inside_the_contents(): void {
		$signals = $this->read( 'nextgen-gallery.html' );

		$this->assertSame( array( 'content:https://e.vli.st/wp-content/uploads/2024/03/16-mars-2024.gpx?download=1' ), $this->of( $signals, Signal::GPX_FILE ) );
	}

	/**
	 * One address per photograph: the thumbnail inside a link to an image is not counted; a data URI, and the images of a list of other
	 * posts, are not.
	 *
	 * @return void
	 */
	public function test_images(): void {
		$this->assertSame(
			array(
				'page:https://e.vli.st/wp-content/uploads/thumbnails/cumulus/eric/2026/10/01/20261001_134828-1024x577.jpg',
				'content:https://e.vli.st/wp-content/uploads/cumulus/eric/2026/10/01/20261001_093654.jpg',
				'content:https://e.vli.st/wp-content/uploads/cumulus/eric/2026/10/01/20261001_100000.jpg',
			),
			$this->of( $this->read( 'etroubles-beaufort.html' ), Signal::IMAGE )
		);
	}

	/**
	 * A NextGEN gallery: the link names the original, its thumbnail is not a second photograph.
	 *
	 * @return void
	 */
	public function test_a_nextgen_gallery(): void {
		$this->assertSame(
			array(
				'content:https://e.vli.st/wp-content/gallery/16-mars-2024/20240316_091413.jpg',
				'content:https://e.vli.st/wp-content/gallery/16-mars-2024/20240316_091500.jpg',
			),
			$this->of( $this->read( 'nextgen-gallery.html' ), Signal::IMAGE )
		);
	}

	/**
	 * The whoBIRD block after the contents: its Wikipedia links are outside the contents, so they say nothing; its mark does.
	 *
	 * @return void
	 */
	public function test_the_whobird_block(): void {
		$signals = $this->read( 'whobird-block.html' );

		$this->assertSame( array( 'content:Chemin de la Mâture', 'content:Lathraea clandestina' ), $this->of( $signals, Signal::WIKIPEDIA_LINK ) );
		$this->assertSame( array( 'page:wikidata:Q1234' ), $this->of( $signals, Signal::TOPIC ) );

		$topic = array_values(
			array_filter(
				$signals,
				static fn( $signal ) => Signal::TOPIC === $signal->type
			)
		)[0];

		$this->assertSame( 'species', $topic->data['kind'] );
		$this->assertSame( 'Mésange bleue', $topic->data['label'] );
		$this->assertTrue( $topic->data['identifier'] );
		$this->assertSame( 'data-index', $topic->evidence );
	}

	/**
	 * The marks in every form: identifier, path, several topics, the main flag, the anchor, a mark in a footer, an empty mark and what is
	 * under `data-index-ignore`. A document with no `<main>` and no contents is read whole.
	 *
	 * @return void
	 */
	public function test_the_marks(): void {
		$signals = $this->read( 'marks.html' );

		$this->assertSame(
			array( 'page:wikidata:Q12', 'page:Beaufort > Les Arberons', 'page:Doron', 'page:Beaufort', 'page:Marked in a footer' ),
			$this->of( $signals, Signal::TOPIC )
		);
		$this->assertSame( array( 'content:No Main Link' ), $this->of( $signals, Signal::WIKIPEDIA_LINK ) );
		$this->assertSame( array( 'content:/wp-content/uploads/a.jpg' ), $this->of( $signals, Signal::IMAGE ) );

		$first = $signals[0];

		$this->assertSame( 'a1', $first->anchor );
		$this->assertSame( 'Merle', $first->data['sort'] );
		$this->assertTrue( $first->data['identifier'] );
		$this->assertSame( array( 'Beaufort', 'Les Arberons' ), $signals[1]->data['path'] );
		$this->assertFalse( $signals[1]->data['identifier'] );
		$this->assertTrue( $signals[2]->data['main'] );
		$this->assertTrue( $signals[3]->data['main'] );
		$this->assertArrayNotHasKey( 'main', $first->data );
	}

	/**
	 * The modes in which a mark is shown (slice 605): slugs separated by spaces or commas, cleaned; none given or none valid means every mode.
	 *
	 * @return void
	 */
	public function test_the_modes_of_a_mark(): void {
		$signals = $this->reader->signals( '<main><span id="a" data-index="Doron" data-index-modes="Print, web  Bad!Mode x"></span><span data-index="Merle" data-index-modes="!!"></span><span data-index="Beaufort"></span></main>' );

		$this->assertSame( array( 'print', 'web', 'x' ), $signals[0]->data['modes'] );
		$this->assertArrayNotHasKey( 'modes', $signals[1]->data );
		$this->assertArrayNotHasKey( 'modes', $signals[2]->data );
	}

	/**
	 * Entities in an attribute are decoded, and a title is percent-decoded.
	 *
	 * @return void
	 */
	public function test_entities_and_percent_encoding(): void {
		$signals = $this->reader->signals( '<main><div class="topic-content"><a href="https://fr.wikipedia.org/wiki/Fontintes_%28Liddes%29?a=1&amp;b=2#x">a</a><a href="//de.wikipedia.org/wiki/Z%C3%BCrich">b</a></div></main>' );

		$this->assertSame( array( 'content:Fontintes (Liddes)', 'content:Zürich' ), $this->of( $signals, Signal::WIKIPEDIA_LINK ) );
	}

	/**
	 * A page with no contents region is read as one whole; one with a contents region reads links only there.
	 *
	 * @return void
	 */
	public function test_the_scopes(): void {
		$link = '<a href="https://fr.wikipedia.org/wiki/Test">t</a>';

		$this->assertSame( array( 'content:Test' ), $this->of( $this->reader->signals( '<main>' . $link . '</main>' ), Signal::WIKIPEDIA_LINK ) );
		$this->assertSame( array(), $this->of( $this->reader->signals( '<main>' . $link . '<div class="topic-content"></div></main>' ), Signal::WIKIPEDIA_LINK ) );
		$this->assertSame( array(), $this->of( $this->reader->signals( '<div id="comments">' . $link . '</div>' ), Signal::WIKIPEDIA_LINK ) );
		$this->assertSame( array(), $this->of( $this->reader->signals( '<nav>' . $link . '</nav><main></main>' ), Signal::WIKIPEDIA_LINK ) );
	}

	/**
	 * Nested ignored regions and elements that are never closed do not leak: the scope ends with the element that opened it.
	 *
	 * @return void
	 */
	public function test_nesting_and_unclosed_elements(): void {
		$html = '<main><div class="topic-content"><div data-index-ignore><div><p>one<p>two</div><a href="https://fr.wikipedia.org/wiki/Hidden">h</a></div><a href="https://fr.wikipedia.org/wiki/Shown">s</a></div></main>';

		$this->assertSame( array( 'content:Shown' ), $this->of( $this->reader->signals( $html ), Signal::WIKIPEDIA_LINK ) );
	}

	/**
	 * An empty page, an unexpected closing tag and a page with nothing to say give no signal, and no error.
	 *
	 * @return void
	 */
	public function test_degenerate_input(): void {
		$this->assertSame( array(), $this->reader->signals( '' ) );
		$this->assertSame( array(), $this->reader->signals( '</div></main>text' ) );
		$this->assertSame( array(), $this->reader->signals( '<p>Nothing</p><script>var a = "<a href=\'https://fr.wikipedia.org/wiki/In_script\'>";</script>' ) );
	}

	/**
	 * A large page is read quickly (the real pages of the blog, 160 to 260 KB, took about 10 ms).
	 *
	 * @return void
	 */
	public function test_a_large_page_is_read_in_well_under_a_second(): void {
		$page  = (string) file_get_contents( __DIR__ . '/fixtures/index/etroubles-beaufort.html' );
		$large = '<main>' . str_repeat( '<figure><img src="https://e.vli.st/a.jpg"></figure><p>Text <a href="https://example.test/">x</a></p>', 4000 ) . '</main>' . $page;

		$start   = microtime( true );
		$signals = $this->reader->signals( $large );
		$elapsed = microtime( true ) - $start;

		$this->assertGreaterThan( 250000, strlen( $large ) );
		$this->assertGreaterThan( 4000, count( $signals ) );
		$this->assertLessThan( 1.0, $elapsed );
	}

	/**
	 * A signal as an array (for the command).
	 *
	 * @return void
	 */
	public function test_a_signal_as_an_array(): void {
		$signal = new Signal( Signal::IMAGE, 'a.jpg', 'page', 'src', array( 'x' => 1 ), 'id1' );

		$this->assertSame(
			array(
				'type'     => 'image',
				'value'    => 'a.jpg',
				'scope'    => 'page',
				'evidence' => 'src',
				'anchor'   => 'id1',
				'data'     => array( 'x' => 1 ),
			),
			$signal->to_array()
		);
	}

	/**
	 * Builds the command around the reader, with doubles for what the reading does not use.
	 *
	 * @param array    $log  Receives the lines.
	 * @param array    $fail Receives the errors.
	 * @param callable $load Loads a page; null for the real one.
	 * @return IndexCommand
	 */
	private function command( array &$log, array &$fail, $load = null ): IndexCommand {
		$topics  = new Otherguise_Test_Memory_Topics();
		$mentions = new Mentions( static fn() => null );

		return new IndexCommand(
			$topics,
			new Indexer( new Resolver( $topics ), $mentions, array(), static fn() => true ),
			$mentions,
			static fn() => array(),
			static fn() => true,
			static function ( $line ) use ( &$log ) {
				$log[] = $line;
			},
			static function ( $line ) use ( &$log ) {
				$log[] = 'OK ' . $line;
			},
			static function ( $line ) use ( &$fail ) {
				$fail[] = $line;
			},
			$this->reader,
			$load
		);
	}

	/**
	 * The command prints one line per signal and a summary, reading a local file.
	 *
	 * @return void
	 */
	public function test_the_command_prints_a_table(): void {
		$log  = array();
		$fail = array();

		$this->command( $log, $fail )->read( array( __DIR__ . '/fixtures/index/whobird-block.html' ), array() );

		$this->assertSame( array(), $fail );
		$this->assertSame( "wikipedia-link\tcontent\tChemin de la Mâture\t{\"language\":\"fr\"}", $log[0] );
		$this->assertStringContainsString( "topic\tpage\twikidata:Q1234", implode( "\n", $log ) );
		$this->assertSame( 'OK 4 signal(s) (wikipedia-link: 2, image: 1, topic: 1).', end( $log ) );
	}

	/**
	 * The command prints JSON on demand, and refuses a bad format, a missing file and a page that did not answer.
	 *
	 * @return void
	 */
	public function test_the_command_json_and_errors(): void {
		$log  = array();
		$fail = array();
		$page = static fn( $target ) => '' === $target ? array(
			'html'  => '',
			'error' => 'nothing',
		) : array(
			'html'  => '<main><div class="topic-content"><a href="https://fr.wikipedia.org/wiki/Test">t</a></div></main>',
			'error' => '',
		);

		$this->command( $log, $fail, $page )->read( array( 'x' ), array( 'format' => 'json' ) );

		$decoded = json_decode( $log[0], true );

		$this->assertSame( 'wikipedia-link', $decoded[0]['type'] ?? null );
		$this->assertSame( 'Test', $decoded[0]['value'] ?? null );

		$this->command( $log, $fail )->read( array( __DIR__ . '/fixtures/index/missing.html' ), array() );
		$this->command( $log, $fail )->read( array( 'x' ), array( 'format' => 'xml' ) );
		$this->command( $log, $fail )->read( array(), array() );
		$this->command(
			$log,
			$fail,
			static fn() => array(
				'html'  => '',
				'error' => 'The page answered with the status 404.',
			)
		)->read( array( 'https://example.test/' ), array() );

		$this->assertStringContainsString( 'cannot be read', $fail[0] );
		$this->assertStringContainsString( 'format', $fail[1] );
		$this->assertStringContainsString( 'format', $fail[2] );
		$this->assertStringContainsString( '404', $fail[3] );
	}
}
