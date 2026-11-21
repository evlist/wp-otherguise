<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of reading a post through its page, from the page to the mentions (slice 602), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Index\Harvest\PageFetcher;
use Otherguise\Index\Harvest\PageReader;
use Otherguise\Index\Harvest\SignalStore;
use Otherguise\Index\Indexer;
use Otherguise\Index\Job\PagesService;
use Otherguise\Index\Mentions;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Resolver;
use Otherguise\Index\Source\Page;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-entries.php';
require_once __DIR__ . '/support/class-otherguise-test-html-api.php';

/**
 * The statements are real; the page is a string, the entries are in memory, posts and meta are stubs.
 *
 * @covers \Otherguise\Index\Job\PagesService
 */
class IndexPagesDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The entries.
	 *
	 * @var Otherguise_Test_Memory_Entries
	 */
	private $entries;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * The service.
	 *
	 * @var PagesService
	 */
	private $service;

	/**
	 * What the site answers for a page, by address.
	 *
	 * @var array<string, mixed>
	 */
	private $pages = array();

	/**
	 * The post meta.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $meta = array();

	/**
	 * Wires the real statements, the reader, a fetcher that answers from `$pages`, and the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! Otherguise_Test_Html_Api::load() ) {
			$this->markTestSkipped( 'The tag processor is not available (set OTHERGUISE_WP_CORE).' );
		}

		$this->wire( true );
	}

	/**
	 * Builds the pieces, with the source of pages on or off.
	 *
	 * @param bool $on Whether the source is on.
	 * @return void
	 */
	private function wire( $on ): void {
		otherguise_test_wp_objects( array( new WP_Post( 12 ), new WP_Post( 13 ) ) );

		$module     = new IndexModule();
		$site       = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $module ) {
					$module->register_predicates( $registry );
				},
			)
		);
		$statements = $site->statements;

		$this->entries  = new Otherguise_Test_Memory_Entries();
		$this->mentions = new Mentions(
			static function () use ( $statements ) {
				return $statements;
			}
		);

		$store = new SignalStore(
			function ( $id, $key ) {
				return $this->meta[ $id ][ $key ] ?? '';
			},
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = is_string( $value ) ? stripslashes( $value ) : $value;
			},
			static fn() => 1700000000
		);

		$indexer = new Indexer(
			new Resolver( $this->entries ),
			$this->mentions,
			$on ? array( new Page( $store ) ) : array(),
			static fn( $id ) => 'publish' === get_post_status( $id )
		);

		$this->service = new PagesService(
			fn() => $indexer,
			static fn() => array( 'post' ),
			static fn( $offset, $limit ) => array_slice( array( 12, 13 ), $offset, $limit ),
			new PageFetcher(
				static fn( $id ) => 'https://e.vli.st/post-' . $id . '/',
				static fn() => 'https://e.vli.st/',
				static fn() => '',
				fn( $url ) => $this->pages[ $url ] ?? new WP_Error( 'http_request_failed', 'no such page' )
			),
			new PageReader(),
			$store
		);
	}

	/**
	 * A page as the site answers it.
	 *
	 * @param string $html The HTML.
	 * @return array<string, mixed>
	 */
	private function page( $html ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'text/html' ),
			'body'     => $html,
		);
	}

	/**
	 * The labels of the entries a post mentions.
	 *
	 * @param int $post Post.
	 * @return string[]
	 */
	private function mentioned( $post ): array {
		$labels = array();

		foreach ( array_keys( $this->mentions->of_post( $post ) ) as $id ) {
			$labels[] = $this->entries->get( $id )['label'];
		}

		sort( $labels );

		return $labels;
	}

	/**
	 * From the page to the mentions: a link in the contents and a mark become mentions, the links outside the contents do not; the harvest
	 * is kept; reading again changes nothing; a link that disappears from the page withdraws its mention.
	 *
	 * @return void
	 */
	public function test_from_the_page_to_the_mentions(): void {
		$with_link = '<header><a href="https://fr.wikipedia.org/wiki/Menu">m</a></header><main><div class="entry-content"><a href="https://fr.wikipedia.org/wiki/Beaufort_(fromage)">w</a> <span data-index="wikidata:Q9" data-index-kind="species" data-index-label="Merle noir">x</span></div></main>';

		$this->pages['https://e.vli.st/post-12/'] = $this->page( $with_link );

		$this->assertSame(
			array(
				'ok'      => true,
				'message' => '2 signal(s)',
			),
			$this->service->process( 12 )
		);
		$this->assertSame( array( 'Beaufort (fromage)', 'Merle noir' ), $this->mentioned( 12 ) );
		$this->assertStringStartsWith( '1700000000 ', $this->meta[12]['_index_harvest'] );
		$this->assertCount( 2, $this->entries->entries );

		$count = count( $this->wpdb->get_results( 'SELECT id FROM `' . $this->table() . '`' ) );
		$this->service->process( 12 );
		$this->assertSame( $count, count( $this->wpdb->get_results( 'SELECT id FROM `' . $this->table() . '`' ) ) );
		$this->assertCount( 2, $this->entries->entries );

		$this->pages['https://e.vli.st/post-12/'] = $this->page( '<main><div class="entry-content"><span data-index="wikidata:Q9" data-index-label="Merle noir">x</span></div></main>' );
		$this->service->process( 12 );

		$this->assertSame( array( 'Merle noir' ), $this->mentioned( 12 ) );

		$entry = array_key_first( $this->mentions->of_post( 12 ) );

		$this->assertSame( array( 'page' ), $this->mentions->of_post( 12 )[ $entry ]['sources'] );
	}

	/**
	 * Two posts that link to one page share one entry, and a key learnt by another source finds it.
	 *
	 * @return void
	 */
	public function test_two_posts_share_an_entry(): void {
		$html = '<main><div class="entry-content"><a href="https://fr.wikipedia.org/wiki/Cap_Ortegal">c</a></div></main>';

		$this->pages['https://e.vli.st/post-12/'] = $this->page( $html );
		$this->pages['https://e.vli.st/post-13/'] = $this->page( $html );

		$this->service->process( 12 );
		$this->service->process( 13 );

		$this->assertCount( 1, $this->entries->entries );
		$this->assertSame( array( 12, 13 ), array_keys( $this->mentions->of_entry( array_key_first( $this->entries->entries ) ) ) );
	}

	/**
	 * A page that cannot be reached, a post that is not published and a source that is off are reported with a reason, and write nothing.
	 *
	 * @return void
	 */
	public function test_failures_are_reported(): void {
		$missing = $this->service->process( 12 );

		$this->assertFalse( $missing['ok'] );
		$this->assertStringContainsString( 'no such page', $missing['message'] );

		$GLOBALS['otherguise_test_statuses'][13] = 'draft';
		$draft                                   = $this->service->process( 13 );

		$this->assertFalse( $draft['ok'] );
		$this->assertStringContainsString( 'not published', $draft['message'] );
		$this->assertSame( array(), $this->entries->entries );
		$this->assertSame( array(), $this->meta );
	}

	/**
	 * With the source off nothing is requested, kept or indexed, and the reason says how to turn it on.
	 *
	 * @return void
	 */
	public function test_nothing_happens_with_the_source_off(): void {
		$this->wire( false );

		$this->pages['https://e.vli.st/post-12/'] = $this->page( '<main></main>' );

		$result = $this->service->process( 12 );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'pages enable', $result['message'] );
		$this->assertSame( array(), $this->meta );

		$this->service->on_save( 12 );
		$this->assertSame( array(), $GLOBALS['otherguise_test_calls'] );
	}

	/**
	 * A saved post is read a little later, once, and only when the source is on and the post is published.
	 *
	 * @return void
	 */
	public function test_a_saved_post_is_scheduled(): void {
		$this->service->on_save( 12 );

		$calls = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_schedule_single_event' === $call[0] ) );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'otherguise_index_page', $calls[0][2] );
		$this->assertSame( array( 12 ), $calls[0][3] );
		$this->assertGreaterThan( time() + 20, $calls[0][1] );

		$GLOBALS['otherguise_test_calls']        = array();
		$GLOBALS['otherguise_test_statuses'][13] = 'draft';
		$this->service->on_save( 13 );
		$this->service->on_save( 0 );

		$this->assertSame( array(), $GLOBALS['otherguise_test_calls'] );
	}

	/**
	 * The source is turned on and off in the option of the sources; the pages are on only when listed.
	 *
	 * @return void
	 */
	public function test_the_source_switch(): void {
		$this->assertTrue( $this->service->is_on() );

		$this->service->set_source( true );
		$this->service->set_source( true );

		$this->assertSame( array( 'categories', 'tags', 'page' ), get_option( 'index_sources' ) );

		$this->service->set_source( false );

		$this->assertSame( array( 'categories', 'tags' ), get_option( 'index_sources' ) );
	}
}
