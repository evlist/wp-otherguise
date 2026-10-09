<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the merge of pinned items in an ordered list.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Service\PinnedOrder;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the merge of pinned items in an ordered list.
 *
 * @covers \Otherguise\Triples\Service\PinnedOrder
 */
class TriplesPinnedOrderTest extends TestCase {

	/**
	 * Without pins the order is kept.
	 *
	 * @return void
	 */
	public function test_without_pins_the_order_is_kept(): void {
		$this->assertSame( array( 1, 2, 3 ), PinnedOrder::merge( array( 1, 2, 3 ), array() ) );
	}

	/**
	 * An empty list stays empty.
	 *
	 * @return void
	 */
	public function test_an_empty_list_stays_empty(): void {
		$this->assertSame( array(), PinnedOrder::merge( array(), array( 5 => 1 ) ) );
	}

	/**
	 * A pinned item sits at its rank, ranks start at 1.
	 *
	 * @return void
	 */
	public function test_a_pinned_item_sits_at_its_rank(): void {
		$this->assertSame( array( 3, 1, 2, 4 ), PinnedOrder::merge( array( 1, 2, 3, 4 ), array( 3 => 1 ) ) );
		$this->assertSame( array( 1, 3, 2, 4 ), PinnedOrder::merge( array( 1, 2, 3, 4 ), array( 3 => 2 ) ) );
		$this->assertSame( array( 1, 2, 4, 3 ), PinnedOrder::merge( array( 1, 2, 3, 4 ), array( 3 => 4 ) ) );
	}

	/**
	 * Several pins are all honoured.
	 *
	 * @return void
	 */
	public function test_several_pins_are_honoured(): void {
		$this->assertSame(
			array( 4, 2, 1, 3 ),
			PinnedOrder::merge(
				array( 1, 2, 3, 4 ),
				array(
					4 => 1,
					2 => 2,
				)
			)
		);
	}

	/**
	 * A rank beyond the end puts the item last; two such items keep the order of their ranks.
	 *
	 * @return void
	 */
	public function test_a_rank_beyond_the_end_puts_the_item_last(): void {
		$this->assertSame( array( 2, 3, 1 ), PinnedOrder::merge( array( 1, 2, 3 ), array( 1 => 99 ) ) );
		$this->assertSame(
			array( 3, 1, 2 ),
			PinnedOrder::merge(
				array( 1, 2, 3 ),
				array(
					1 => 99,
					2 => 100,
				)
			)
		);
		$this->assertSame(
			array( 3, 2, 1 ),
			PinnedOrder::merge(
				array( 1, 2, 3 ),
				array(
					1 => 100,
					2 => 99,
				)
			)
		);
	}

	/**
	 * Equal ranks are ordered by id, the second item taking the next free place.
	 *
	 * @return void
	 */
	public function test_equal_ranks_are_ordered_by_id(): void {
		$this->assertSame(
			array( 2, 4, 1, 3 ),
			PinnedOrder::merge(
				array( 1, 2, 3, 4 ),
				array(
					4 => 1,
					2 => 1,
				)
			)
		);
		$this->assertSame(
			array( 2, 4, 1, 3 ),
			PinnedOrder::merge(
				array( 1, 2, 3, 4 ),
				array(
					2 => 1,
					4 => 1,
				)
			)
		);
	}

	/**
	 * Pins of items that are not in the list are ignored, and so are ranks below 1 (read as 1).
	 *
	 * @return void
	 */
	public function test_unknown_items_are_ignored_and_low_ranks_read_as_one(): void {
		$this->assertSame( array( 1, 2 ), PinnedOrder::merge( array( 1, 2 ), array( 9 => 1 ) ) );
		$this->assertSame( array( 2, 1 ), PinnedOrder::merge( array( 1, 2 ), array( 2 => 0 ) ) );
		$this->assertSame( array( 2, 1 ), PinnedOrder::merge( array( 1, 2 ), array( 2 => -4 ) ) );
	}

	/**
	 * Every item pinned: the result is ordered by rank, then by id.
	 *
	 * @return void
	 */
	public function test_everything_pinned_is_ordered_by_rank(): void {
		$this->assertSame(
			array( 3, 1, 2 ),
			PinnedOrder::merge(
				array( 1, 2, 3 ),
				array(
					1 => 2,
					2 => 3,
					3 => 1,
				)
			)
		);
	}
}
