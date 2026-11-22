<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the markers of the mentions in a unit (slice 612).
 *
 * @package Otherguise
 */

use Otherguise\Books\Merge\MentionMarks;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: the module of the index is a closure that answers the filter `index_mention_elements`.
 *
 * @covers \Otherguise\Books\Merge\MentionMarks
 */
class BooksMentionMarksTest extends TestCase {

	/**
	 * Starts clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * A marker goes in front of each element the module of the index marked, numbered in the order of the page, and the manifest notes it; what the
	 * module did not mark gets nothing; the text of a script is left alone.
	 *
	 * @return void
	 */
	public function test_the_marked_elements_get_a_marker(): void {
		$GLOBALS['otherguise_test_filters']['index_mention_elements'] = static fn( $html, $id ) => str_replace(
			array( '<a href="w">', '<span id="obs">', '<a href="q">' ),
			array( '<a href="w" data-index-mention="link:fr:Beaufort">', '<span id="obs" data-index-mention="mark:#obs">', '<a href="q" data-index-mention="link:fr:Un &amp; deux">' ),
			$html
		);

		$marks = array();
		$html  = MentionMarks::unit( '<p>See <a href="w">Beaufort</a>, <span id="obs">a merle</span> and <a href="x">another</a>. <a href="q">Q</a></p><script>var a = \'<a href="w">\';</script>', 7, $marks );

		$this->assertSame( 3, substr_count( $html, 'https://marker.invalid/mention/7/' ), 'The script is left alone, the link that made no mention is not marked.' );
		$this->assertStringContainsString( '<a class="books-merge-marker" href="https://marker.invalid/mention/7/1" tabindex="-1" aria-hidden="true" style="display:inline-block;width:1px;height:1px;vertical-align:top;margin-bottom:-1px;"></a><a href="w" data-index-mention="link:fr:Beaufort">', $html );
		$this->assertStringContainsString( 'mention/7/2', $html );
		$this->assertStringNotContainsString( 'mention/7/4', $html );
		$this->assertSame(
			array(
				array(
					'unit'  => 7,
					'n'     => 1,
					'type'  => 'link',
					'value' => 'link:fr:Beaufort',
				),
				array(
					'unit'  => 7,
					'n'     => 2,
					'type'  => 'mark',
					'value' => 'mark:#obs',
				),
				array(
					'unit'  => 7,
					'n'     => 3,
					'type'  => 'link',
					'value' => 'link:fr:Un & deux',
				),
			),
			$marks
		);
		$this->assertSame(
			array(
				7 => array(
					'link:fr:Beaufort' => array( 1 ),
					'mark:#obs'        => array( 2 ),
					'link:fr:Un & deux' => array( 3 ),
				),
			),
			MentionMarks::by_post( $marks )
		);
	}

	/**
	 * Without the module of the index (the filter gives back the HTML), or without a mention, the unit is unchanged and nothing is noted.
	 *
	 * @return void
	 */
	public function test_without_the_module_nothing_is_marked(): void {
		$marks = array();

		$this->assertSame( '<p><a href="w">x</a></p>', MentionMarks::unit( '<p><a href="w">x</a></p>', 3, $marks ) );
		$this->assertSame( array(), $marks );

		$GLOBALS['otherguise_test_filters']['index_mention_elements'] = static fn() => 42;

		$this->assertSame( '<p>y</p>', MentionMarks::unit( '<p>y</p>', 3, $marks ), 'A filter that answers with something else than a string is ignored.' );
	}
}
