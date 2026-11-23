<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Test of the pages of a unit cut by page breaks.
 *
 * @package Otherguise
 */

use Otherguise\Books\Merge\UnitRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Pure: the rendering itself needs WordPress (checked on a scratch WordPress: the three pages of a post come out).
 *
 * @covers \Otherguise\Books\Merge\UnitRenderer::one_page
 */
class BooksUnitRendererTest extends TestCase {

	/**
	 * The pages of a post cut by "Page break" blocks are joined into one, so that the merge does not lose what follows the first break.
	 *
	 * @return void
	 */
	public function test_the_pages_of_a_post_are_joined(): void {
		$this->assertSame( array( "<p>1</p>\n<p>2</p>\n<p>3</p>" ), UnitRenderer::one_page( array( '<p>1</p>', '<p>2</p>', '<p>3</p>' ) ) );
		$this->assertSame( array( '<p>1</p>' ), UnitRenderer::one_page( array( '<p>1</p>' ) ) );
		$this->assertSame( 'x', UnitRenderer::one_page( 'x' ), 'Anything else is left alone.' );
	}
}
