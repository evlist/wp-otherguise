<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the rename of the taxonomy of the topics (entries until 2026-11-22), and of the signals kept under the old name.
 *
 * @package Otherguise
 */

use Otherguise\Index\Harvest\Signal;
use Otherguise\Index\TopicsMigration;
use PHPUnit\Framework\TestCase;

/**
 * Stubs and doubles only.
 *
 * @covers \Otherguise\Index\TopicsMigration
 * @covers \Otherguise\Index\Harvest\Signal::from_array
 */
class IndexTopicsMigrationTest extends TestCase {

	/**
	 * Starts clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * The taxonomy is renamed once: the double is called with the old and the new name, the number of terms moved is returned, the option records
	 * it, and a second run does nothing (the double is not called).
	 *
	 * @return void
	 */
	public function test_it_runs_once(): void {
		$calls = array();
		$do    = static function ( $from, $to ) use ( &$calls ) {
			$calls[] = array( $from, $to );

			return 964;
		};

		$this->assertSame( 964, TopicsMigration::run( $do ) );
		$this->assertSame( array( array( 'index_entry', 'index_topic' ) ), $calls );
		$this->assertSame( '1', get_option( TopicsMigration::OPTION ) );

		$this->assertSame( 0, TopicsMigration::run( $do ) );
		$this->assertCount( 1, $calls, 'Done once, not asked again.' );
	}

	/**
	 * A site with no topic has nothing to move and is done all the same; a failure is not recorded as done, so the next request tries again.
	 *
	 * @return void
	 */
	public function test_nothing_to_move_and_a_failure(): void {
		$this->assertSame( 0, TopicsMigration::run( static fn() => 0 ) );
		$this->assertSame( '1', get_option( TopicsMigration::OPTION ) );

		otherguise_test_reset();

		$this->assertSame( 0, TopicsMigration::run( static fn() => false ) );
		$this->assertFalse( get_option( TopicsMigration::OPTION ), 'A failure is not done.' );

		$this->assertSame( 3, TopicsMigration::run( static fn() => 3 ) );
		$this->assertSame( '1', get_option( TopicsMigration::OPTION ) );
	}

	/**
	 * A signal kept before the rename calls a mark `entry`: it is read as a topic, so the mentions of the marks do not vanish before the pages
	 * are read again.
	 *
	 * @return void
	 */
	public function test_an_old_signal_is_a_topic(): void {
		$old = Signal::from_array(
			array(
				'type'     => 'entry',
				'value'    => 'wikidata:Q9',
				'scope'    => 'page',
				'evidence' => 'data-index',
				'data'     => array( 'identifier' => true ),
			)
		);

		$this->assertSame( Signal::TOPIC, $old->type );
		$this->assertSame( 'topic', Signal::TOPIC );
		$this->assertSame(
			'image',
			Signal::from_array(
				array(
					'type' => 'image',
					'value' => 'a.jpg',
				)
			)->type,
			'The other types are as they were.'
		);
	}
}
