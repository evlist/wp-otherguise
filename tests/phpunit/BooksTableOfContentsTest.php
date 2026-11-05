<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the table of contents of a section (slice 228).
 *
 * @package Otherguise
 */

use Otherguise\Books\Merge\TableOfContents;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: the headings are those of a manifest.
 *
 * @covers \Otherguise\Books\Merge\TableOfContents
 */
class BooksTableOfContentsTest extends TestCase {

	/**
	 * A heading of the manifest.
	 *
	 * @param int    $unit  Post.
	 * @param int    $n     Number in the post.
	 * @param int    $level Level.
	 * @param string $text  Text.
	 * @param int    $depth Depth of the post.
	 * @param bool   $toc   Whether a table of contents lists it.
	 * @return array
	 */
	private function row( $unit, $n, $level, $text, $depth, $toc = true ) {
		return compact( 'unit', 'n', 'level', 'text', 'toc', 'depth' );
	}

	/**
	 * The manifest of a book: the title of the book and its table (left out), a part (a section) with a unit, a unit with subtitles, a closing page.
	 *
	 * @return array
	 */
	private function book() {
		return array(
			$this->row( 100, 1, 1, 'The book', 0, false ),
			$this->row( 100, 2, 1, 'Contents', 0, false ),
			$this->row( 101, 1, 1, 'Part one', 1 ),
			$this->row( 3, 1, 1, 'Day 3', 2 ),
			$this->row( 3, 2, 2, 'Morning', 2 ),
			$this->row( 1, 1, 1, 'Day 1', 1 ),
			$this->row( 1, 2, 3, 'A detail', 1 ),
			$this->row( 1, 3, 2, '', 1 ),
			$this->row( 100, 3, 1, 'Afterword', 0 ),
		);
	}

	/**
	 * The entries: the headings with `no-toc` and without text are left out; the level of an entry is the depth of its unit plus the distance of the
	 * heading from the title of its unit; a limit of levels drops the deeper entries.
	 *
	 * @return void
	 */
	public function test_the_entries(): void {
		$all = TableOfContents::entries( $this->book(), 0 );

		$this->assertSame(
			array(
				array( 101, 1, 1, 'Part one' ),
				array( 3, 1, 2, 'Day 3' ),
				array( 3, 2, 3, 'Morning' ),
				array( 1, 1, 1, 'Day 1' ),
				array( 1, 2, 3, 'A detail' ),
				array( 100, 3, 1, 'Afterword' ),
			),
			array_map( static fn( $e ) => array( $e['unit'], $e['n'], $e['level'], $e['text'] ), $all ),
			'The depth of a unit gives its level, a subtitle goes one level below its title; a gap of levels keeps its size.'
		);

		$two = TableOfContents::entries( $this->book(), 2 );

		$this->assertSame( array( 'Part one', 'Day 3', 'Day 1', 'Afterword' ), array_column( $two, 'text' ) );
		$this->assertSame( array( 'Part one', 'Day 1', 'Afterword' ), array_column( TableOfContents::entries( $this->book(), 1 ), 'text' ) );
		$this->assertSame( array(), TableOfContents::entries( array(), 2 ) );
	}

	/**
	 * In print an entry has its text, a leader and the number of its page, or the placeholder; elsewhere, a link; the text is escaped.
	 *
	 * @return void
	 */
	public function test_the_html(): void {
		$entries = TableOfContents::entries(
			array(
				$this->row( 1, 1, 1, 'Day <1> & co', 1 ),
				$this->row( 2, 1, 1, 'Day 2', 1 ),
			),
			0
		);

		$this->assertSame(
			'<ul class="books-toc"><li class="books-toc__entry books-toc__level-1"><span class="books-toc__text">Day &lt;1&gt; &amp; co</span><span class="books-toc__leader" aria-hidden="true"></span><span class="books-toc__page">12</span></li>'
			. '<li class="books-toc__entry books-toc__level-1"><span class="books-toc__text">Day 2</span><span class="books-toc__leader" aria-hidden="true"></span><span class="books-toc__page">–</span></li></ul>',
			TableOfContents::render( $entries, true, array( '1#1' => 12 ), static fn() => '' ),
			'The pages are known: a heading that is not in the map is not printed, a dash.'
		);
		$this->assertStringContainsString( '<span class="books-toc__page">000</span>', TableOfContents::render( $entries, true, array(), static fn() => '' ), 'No pages yet: the placeholder.' );
		$this->assertSame(
			'<ul class="books-toc"><li class="books-toc__entry books-toc__level-1"><a href="https://example.test/?p=1">Day &lt;1&gt; &amp; co</a></li>'
			. '<li class="books-toc__entry books-toc__level-1">Day 2</li></ul>',
			TableOfContents::render( $entries, false, array(), static fn( $id ) => 1 === $id ? 'https://example.test/?p=1' : '' ),
			'A post with no address: the text alone.'
		);
		$this->assertSame( '', TableOfContents::render( array(), true, array(), static fn() => '' ) );
	}
}
