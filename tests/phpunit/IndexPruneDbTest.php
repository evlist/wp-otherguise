<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the cleaning of the topics that nobody mentions (slice 619), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Index\Cli\PruneCommand;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Merge\TopicPruner;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Topics;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * The statements are real; the topics and the kinds are in memory.
 *
 * @covers \Otherguise\Index\Merge\TopicPruner
 * @covers \Otherguise\Index\Cli\PruneCommand
 * @covers \Otherguise\Index\Mentions::mentioned_topics
 */
class IndexPruneDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The topics.
	 *
	 * @var Otherguise_Test_Memory_Topics
	 */
	private $topics;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * Wires the statements.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Otherguise_Test_Memory_Kinds::install();
		Kinds::add( 'admin2', 'Second', 'place' );
		otherguise_test_wp_objects( array( new WP_Post( 1 ), new WP_Post( 2 ) ) );

		$module = new IndexModule();
		$site   = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $module ) {
					$module->register_predicates( $registry );
				},
			)
		);

		$statements     = $site->statements;
		$this->topics   = new Otherguise_Test_Memory_Topics();
		$this->mentions = new Mentions(
			static function () use ( $statements ) {
				return $statements;
			}
		);
	}

	/**
	 * What goes and what stays: the places that no post mentions go, the deepest first; a topic stays when a post mentions it, when a topic below it stays, when
	 * a person chose its kind or its label, when it is refused, or when it is of another kind than the ones asked for.
	 *
	 * @return void
	 */
	public function test_what_goes_and_what_stays(): void {
		$region  = $this->topics->create( 'Aragon', 'place', 0, Topics::APPROVED, array() );
		$old     = $this->topics->create( 'Somontano', 'admin2', $region, Topics::APPROVED, array() );
		$old_kid = $this->topics->create( 'Vieille commune', 'place', $old, Topics::APPROVED, array() );
		$prov    = $this->topics->create( 'Huesca', 'admin2', $region, Topics::APPROVED, array() );
		$town    = $this->topics->create( 'Barbastro', 'place', $prov, Topics::APPROVED, array() );
		$hand    = $this->topics->create( 'Choisi', 'place', 0, Topics::APPROVED, array() );
		$named   = $this->topics->create( 'Nommé', 'place', 0, Topics::APPROVED, array() );
		$refused = $this->topics->create( 'Refusé', 'place', 0, Topics::REFUSED, array() );
		$species = $this->topics->create( 'Merle', 'species', 0, Topics::APPROVED, array() );

		$this->topics->set_kind( $hand, 'place', true );
		$this->topics->set_label( $named, 'Nommé' );
		$this->topics->label_by_hand( $named );

		$this->mentions->sync( 1, 'post/tag', array( $town => array() ) );

		$pruner = new TopicPruner( $this->topics, $this->mentions );
		$goes   = $pruner->candidates();

		$this->assertSame( array( $old_kid, $old ), array_column( $goes, 'id' ), 'The child before its parent; the region stays because Huesca stays below it.' );

		$dry = $pruner->prune( array( 'place' ), true );

		$this->assertCount( 2, $dry );
		$this->assertNotNull( $this->topics->get( $old ), 'A dry run deletes nothing.' );

		$this->assertSame( array( $old_kid, $old ), array_column( $pruner->prune( array( 'place' ), false ), 'id' ) );
		$this->assertNull( $this->topics->get( $old ) );
		$this->assertNull( $this->topics->get( $old_kid ) );

		foreach ( array( $region, $prov, $town, $hand, $named, $refused, $species ) as $id ) {
			$this->assertNotNull( $this->topics->get( $id ), 'Stays.' );
		}

		$every = $pruner->candidates( array( 'any' ) );

		$this->assertSame( array( $species ), array_column( $every, 'id' ), 'Every kind: the species nobody mentions too.' );
	}

	/**
	 * The command only lists without --apply, and says so; with it deletes.
	 *
	 * @return void
	 */
	public function test_the_command_lists_then_deletes(): void {
		$old   = $this->topics->create( 'Somontano', 'admin2', 0, Topics::APPROVED, array() );
		$lines = array();
		$done  = array();
		$cmd   = new PruneCommand(
			new TopicPruner( $this->topics, $this->mentions ),
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $line ) use ( &$done ) {
				$done[] = $line;
			}
		);

		$cmd( array(), array() );

		$this->assertSame( $old . "\tSomontano [admin2]", $lines[0] );
		$this->assertStringContainsString( 'nothing deleted, add --apply', $done[0] );
		$this->assertNotNull( $this->topics->get( $old ) );

		$cmd( array(), array( 'apply' => true ) );

		$this->assertStringContainsString( '1 topic(s) deleted.', $done[1] );
		$this->assertNull( $this->topics->get( $old ) );
	}
}
