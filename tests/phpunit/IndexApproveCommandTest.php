<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the command that approves candidate topics (slice 635).
 *
 * @package Otherguise
 */

use Otherguise\Index\Cli\ApproveCommand;
use Otherguise\Index\Topics;
use PHPUnit\Framework\TestCase;

/**
 * `wp otherguise index approve`: a dry run by default, the filters, `--apply`.
 *
 * @covers \Otherguise\Index\Cli\ApproveCommand
 */
class IndexApproveCommandTest extends TestCase {
	/**
	 * The topics.
	 *
	 * @var Otherguise_Test_Memory_Topics
	 */
	private $topics;

	/**
	 * Starts with no topic and the kinds of the tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		Otherguise_Test_Memory_Kinds::install();

		$this->topics = new Otherguise_Test_Memory_Topics();
	}

	/**
	 * A dry run changes nothing and lists; the filters (a kind with its sub-kinds, a text); `--apply` approves; a kind that does not exist is refused.
	 *
	 * @return void
	 */
	public function test_approving_candidates(): void {
		$place   = $this->topics->create( 'Allemagne', 'country', 0, Topics::CANDIDATE, array() );
		$unclear = $this->topics->create( 'Voyages', 'any', 0, Topics::CANDIDATE, array() );
		$already = $this->topics->create( 'France', 'country', 0, Topics::APPROVED, array() );
		$lines   = array();
		$said    = array();
		$command = new ApproveCommand(
			$this->topics,
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $message ) use ( &$said ) {
				$said[] = $message;
			},
			static function ( $message ) use ( &$said ) {
				$said[] = 'ERROR ' . $message;
			}
		);

		$command( array(), array() );

		$this->assertSame( Topics::CANDIDATE, $this->topics->get( $place )['status'], 'A dry run changes nothing.' );
		$this->assertCount( 2, $lines );
		$this->assertSame( '2 candidate topic(s) would be approved: nothing was changed, add --apply to do it.', end( $said ) );

		$command(
			array(),
			array(
				'kind' => 'place',
				'apply' => true,
			)
		);

		$this->assertSame( Topics::APPROVED, $this->topics->get( $place )['status'], 'A country is a sort of place.' );
		$this->assertSame( Topics::CANDIDATE, $this->topics->get( $unclear )['status'], 'The kind any is not a sort of place.' );

		$command(
			array(),
			array(
				'search' => 'VOY',
				'apply' => true,
			)
		);

		$this->assertSame( Topics::APPROVED, $this->topics->get( $unclear )['status'] );
		$this->assertSame( Topics::APPROVED, $this->topics->get( $already )['status'] );

		$command( array(), array( 'kind' => 'nonsense' ) );

		$this->assertSame( 'ERROR Unknown kind "nonsense".', end( $said ) );
	}
}
