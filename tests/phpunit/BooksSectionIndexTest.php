<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the index of a section (slice 611): its HTML and its slots.
 *
 * @package Otherguise
 */

use Otherguise\Books\Merge\IndexSlots;
use Otherguise\Books\Merge\IndexTable;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: the data of the index, the headings and the pages are arrays.
 *
 * @covers \Otherguise\Books\Merge\IndexTable
 * @covers \Otherguise\Books\Merge\IndexSlots
 */
class BooksSectionIndexTest extends TestCase {

	/**
	 * Starts clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * A line of the data of an index.
	 *
	 * @param string $label    Label.
	 * @param array  $locators `array( post, strength )` pairs.
	 * @param array  $classes  Classes.
	 * @return array<string, mixed>
	 */
	private function line( $label, array $locators, array $classes = array( 'index-kind-place' ) ): array {
		return array(
			'label'    => $label,
			'kind'     => 'place',
			'classes'  => $classes,
			'locators' => array_map(
				static fn( $pair ) => array(
					'post'     => $pair[0],
					'strength' => $pair[1] ?? 'passing',
					'sources'  => array( 'post/tag' ),
				),
				$locators
			),
		);
	}

	/**
	 * The data of two groups.
	 *
	 * @param array $attribution What the lines owe.
	 * @return array<string, mixed>
	 */
	private function data( array $attribution = array() ): array {
		return array(
			'groups'      => array(
				'B' => array( $this->line( 'Beaufort', array( array( 1 ), array( 2 ), array( 3 ), array( 4, 'main' ), array( 5, 'main' ), array( 9 ) ) ) ),
				'D' => array( $this->line( 'Doron <river>', array( array( 2, 'main' ) ), array( 'index-kind-waterway', 'index-kind-place' ) ) ),
			),
			'attribution' => $attribution + array(
				'osm'       => false,
				'wikimedia' => false,
			),
		);
	}

	/**
	 * The first heading of each post, and the pages of those headings (the post 9 is not in the PDF).
	 *
	 * @return array{0:array<int,string>,1:array<string,int>}
	 */
	private function pages(): array {
		return array(
			array(
				1 => '1#1',
				2 => '2#1',
				3 => '3#1',
				4 => '4#1',
				5 => '5#1',
				9 => '9#1',
			),
			array(
				'1#1' => 12,
				'2#1' => 13,
				'3#1' => 14,
				'4#1' => 20,
				'5#1' => 21,
			),
		);
	}

	/**
	 * In print the locators are pages in order: consecutive pages are collapsed, a main page is in bold and breaks a run, a post that is not in the
	 * PDF has none; the groups have their letters and the entries the classes of their kind.
	 *
	 * @return void
	 */
	public function test_the_pages(): void {
		list( $first, $pages ) = $this->pages();

		$html = IndexTable::render( $this->data(), true, $first, $pages, static fn() => '', static fn() => '' );

		$this->assertStringStartsWith( '<div class="books-index"><section class="books-index__group"><h3 class="books-index__letter no-toc">B</h3>', $html );
		$this->assertStringContainsString( '<li class="books-index__entry index-kind-place"><span class="books-index__label">Beaufort</span> <span class="books-index__locators">12–14, <strong class="books-index__main">20–21</strong></span></li>', $html );
		$this->assertStringContainsString( '<li class="books-index__entry index-kind-waterway index-kind-place"><span class="books-index__label">Doron &lt;river&gt;</span> <span class="books-index__locators"><strong class="books-index__main">13</strong></span></li>', $html, 'Escaped, with the classes of the kind and of its ancestors.' );
		$this->assertStringContainsString( '<h3 class="books-index__letter no-toc">D</h3>', $html );
	}

	/**
	 * The consecutive pages of different weight are two runs; the same page twice is one; a line whose posts are all outside the PDF has a dash;
	 * without a map the placeholder.
	 *
	 * @return void
	 */
	public function test_runs_weights_and_the_unknown(): void {
		$data = array(
			'groups' => array(
				'A' => array(
					$this->line( 'Mixed', array( array( 1 ), array( 2, 'main' ), array( 3 ) ) ),
					$this->line( 'Twice', array( array( 1 ), array( 1 ) ) ),
					$this->line( 'Absent', array( array( 9 ) ) ),
				),
			),
		);
		list( $first, $pages ) = $this->pages();

		$html = IndexTable::render( $data, true, $first, $pages, static fn() => '', static fn() => '' );

		$this->assertStringContainsString( '<span class="books-index__locators">12, <strong class="books-index__main">13</strong>, 14</span>', $html );
		$this->assertStringContainsString( '<span class="books-index__label">Twice</span> <span class="books-index__locators">12</span>', $html );
		$this->assertStringContainsString( '<span class="books-index__locators">–</span>', $html );
		$this->assertStringContainsString( '<span class="books-index__locators">000</span>', IndexTable::render( $data, true, $first, array(), static fn() => '', static fn() => '' ), 'No pages yet.' );
	}

	/**
	 * Outside print the locators are links with the title of the post, a main one in bold; a post with no address is its title; the title and
	 * the empty index.
	 *
	 * @return void
	 */
	public function test_the_links_and_the_title(): void {
		$html = IndexTable::render(
			array(
				'groups' => array( 'D' => array( $this->line( 'Doron', array( array( 2, 'main' ), array( 3 ) ) ) ) ),
			),
			false,
			array(),
			array(),
			static fn( $id ) => 2 === $id ? 'https://example.test/?p=2' : '',
			static fn( $id ) => 'Day <' . $id . '>',
			array( 'title' => ' Places & rivers ' )
		);

		$this->assertStringContainsString( '<h2 class="books-index__title no-toc">Places &amp; rivers</h2>', $html );
		$this->assertStringContainsString( '<strong class="books-index__main"><a href="https://example.test/?p=2">Day &lt;2&gt;</a></strong>, Day &lt;3&gt;', $html );
		$this->assertSame( '', IndexTable::render( array( 'groups' => array() ), true, array(), array(), static fn() => '', static fn() => '' ) );
	}

	/**
	 * One notice ends the index: only what is owed in `auto`, both in `yes`, none in `no`; with links; the filter can change the wording.
	 *
	 * @return void
	 */
	public function test_the_notice(): void {
		list( $first, $pages ) = $this->pages();
		$render                = fn( $attribution, $mode ) => IndexTable::render( $this->data( $attribution ), true, $first, $pages, static fn() => '', static fn() => '', array( 'attribution' => $mode ) );

		$this->assertStringNotContainsString( 'books-index__attribution', $render( array(), 'auto' ), 'Nothing owed: no notice.' );
		$this->assertStringNotContainsString( 'books-index__attribution', $render( array( 'osm' => true ), 'no' ) );

		$osm = $render( array( 'osm' => true ), 'auto' );

		$this->assertStringContainsString( '<p class="books-index__attribution">Places: <a href="https://www.openstreetmap.org/copyright">© OpenStreetMap contributors</a>.</p>', $osm );
		$this->assertStringNotContainsString( 'Wikidata', $osm );

		$both = $render(
			array(
				'osm' => true,
				'wikimedia' => true,
			),
			'auto'
		);

		$this->assertStringContainsString( 'Identifiers and names: <a href="https://www.wikidata.org/">Wikidata</a>, Wikimedia Foundation.', $both );
		$this->assertSame( 1, substr_count( $both, 'books-index__attribution' ), 'One notice, not one per entry.' );

		$yes = $render( array(), 'yes' );

		$this->assertStringContainsString( 'OpenStreetMap', $yes );
		$this->assertStringContainsString( 'Wikimedia Foundation', $yes );

		$GLOBALS['otherguise_test_filters']['books_index_attribution'] = static fn( $html, $owed ) => '<em>Colophon: ' . implode( '+', array_keys( array_filter( $owed ) ) ) . '</em>';

		$this->assertStringContainsString( '<p class="books-index__attribution"><em>Colophon: osm</em></p>', $render( array( 'osm' => true ), 'auto' ) );
	}

	/**
	 * The slots: a block leaves a numbered marker; the index replaces it in the piece of the page that holds it, the data is asked only for that
	 * one, with the posts of the merge in order and the options; without units, without data or without the module the marker goes away.
	 *
	 * @return void
	 */
	public function test_the_slots(): void {
		$asked = array();
		$slots = new IndexSlots(
			static fn() => '',
			static fn( $id ) => 'Day ' . $id,
			function ( $posts, $options ) use ( &$asked ) {
				$asked[] = array( $posts, $options );

				return 'none' === $options['exclude'] ? null : $this->data();
			}
		);

		$one = $slots->slot(
			array(
				'include' => 'kind:place',
				'context' => false,
			),
			true
		);
		$two = $slots->slot( array( 'exclude' => 'none' ), false );

		$this->assertSame( IndexSlots::MARKER . '0 -->', $one );
		$this->assertSame( IndexSlots::MARKER . '1 -->', $two );
		$this->assertSame( 1, $slots->numbered() );

		list( $first, $pages ) = $this->pages();
		$rows                  = array();

		foreach ( $first as $unit => $key ) {
			$rows[] = array(
				'unit' => $unit,
				'n'    => 1,
			);
		}

		$filled = $slots->fill( 'before ' . $one . ' after', array( 1, 2, 3 ), $rows, $pages );

		$this->assertStringContainsString( '<span class="books-index__locators">12–14, <strong class="books-index__main">20–21</strong></span>', $filled );
		$this->assertStringStartsWith( 'before <div class="books-index">', $filled );
		$this->assertCount( 1, $asked, 'The data is asked only for the slot that is in this piece.' );
		$this->assertSame( array( 1, 2, 3 ), $asked[0][0] );
		$this->assertSame( 'kind:place', $asked[0][1]['include'] );
		$this->assertFalse( $asked[0][1]['context'] );

		$this->assertSame( 'x  y', $slots->fill( 'x ' . $two . ' y', array( 1 ), $rows, $pages ), 'No data (no module of the index): the marker goes away.' );
		$this->assertSame( 'x  y', $slots->fill( 'x ' . $one . ' y', array(), $rows, $pages ), 'No units: the marker goes away.' );
		$this->assertSame( 'plain', $slots->fill( 'plain', array( 1 ), $rows, $pages ) );
	}
}
