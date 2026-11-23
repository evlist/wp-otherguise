<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Test of the change of the kind `other` to `any` (slice 615).
 *
 * @package Otherguise
 */

use Otherguise\Index\KindsMigration;
use PHPUnit\Framework\TestCase;

/**
 * No database: the update is a closure.
 *
 * @covers \Otherguise\Index\KindsMigration
 */
class IndexKindsMigrationTest extends TestCase {

	/**
	 * Starts clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * It runs once; a failure is not recorded and is tried again.
	 *
	 * @return void
	 */
	public function test_it_runs_once(): void {
		$calls = 0;
		$count = static function () use ( &$calls ) {
			++$calls;

			return 7;
		};

		$this->assertSame( 7, KindsMigration::run( $count ) );
		$this->assertSame( 0, KindsMigration::run( $count ), 'Once is enough.' );
		$this->assertSame( 1, $calls );

		otherguise_test_reset();

		$this->assertSame( 0, KindsMigration::run( static fn() => false ), 'A failure changes nothing...' );
		$this->assertSame( 3, KindsMigration::run( static fn() => 3 ), '...and is tried again.' );
	}
}
