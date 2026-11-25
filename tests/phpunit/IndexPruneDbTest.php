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
use Otherguise\Index\Cli\PurgeCommand;
use Otherguise\Index\Cli\ResetCommand;
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
		$wait    = $this->topics->create( 'En attente', 'place', 0, Topics::AMBIGUOUS, array() );
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

		foreach ( array( $region, $prov, $town, $hand, $named, $refused, $wait, $species ) as $id ) {
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

	/**
	 * Dropping a source (slice 620): its mentions go (a mention that another source also makes stays, without it); the topics that nothing mentions any more
	 * go, with the broader topics that nothing else mentions; what a person chose, a topic that is mentioned by something else, and the other sources stay.
	 * A dry run says the same and changes nothing; the sub-sources go with their source.
	 *
	 * @return void
	 */
	public function test_dropping_a_source(): void {
		Kinds::add( 'commune', 'Commune', 'place' );

		$country = $this->topics->create( 'Espagne', 'place', 0, Topics::APPROVED, array() );
		$region  = $this->topics->create( 'Galice', 'admin2', $country, Topics::APPROVED, array() );
		$town    = $this->topics->create( 'A Guarda', 'commune', $region, Topics::APPROVED, array() );
		$both    = $this->topics->create( 'Vigo', 'commune', $region, Topics::APPROVED, array() );
		$other   = $this->topics->create( 'Lugo', 'commune', $country, Topics::APPROVED, array() );
		$chosen  = $this->topics->create( 'Choisi', 'commune', $region, Topics::APPROVED, array() );
		$mark    = $this->topics->create( 'Merle', 'species', 0, Topics::APPROVED, array() );

		$this->topics->set_kind( $chosen, 'commune', true );

		$this->mentions->sync(
			1,
			'image/address',
			array(
				$town => array(),
				$both => array(),
				$chosen => array(),
			)
		);
		$this->mentions->sync( 1, 'post/tag', array( $both => array() ) );
		$this->mentions->sync( 1, 'trace/start', array( $other => array() ) );
		$this->mentions->sync( 2, 'post/mark', array( $mark => array() ) );

		$lines = array();
		$done  = array();
		$fail  = array();
		$cmd   = new PurgeCommand(
			$this->topics,
			$this->mentions,
			new TopicPruner( $this->topics, $this->mentions ),
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $line ) use ( &$done ) {
				$done[] = $line;
			},
			static function ( $line ) use ( &$fail ) {
				$fail[] = $line;
			}
		);

		$cmd( array(), array( 'source' => 'nonsense' ) );
		$this->assertStringContainsString( '--source: one of', $fail[0] );

		$cmd( array(), array( 'source' => 'image' ) );

		$this->assertStringContainsString( '3 mention(s) of the source image would be dropped, 1 topic(s) would be deleted', $done[0] );
		$this->assertSame( $town . "\tA Guarda [commune]", $lines[0], 'Only the topic that nothing mentions and nobody chose: not the chosen one, not Vigo (a tag mentions it).' );
		$this->assertNotNull( $this->topics->get( $town ), 'Nothing changed.' );
		$this->assertSame( array( 1 ), array_keys( $this->mentions->of_topic( $town ) ) );

		$lines = array();

		$cmd(
			array(),
			array(
				'source' => 'image',
				'apply' => true,
			)
		);

		$this->assertStringContainsString( '3 mention(s) of the source image dropped, 1 topic(s) deleted.', $done[1] );
		$this->assertNull( $this->topics->get( $town ) );
		$this->assertNotNull( $this->topics->get( $region ), 'Galice stays: Vigo, which a tag mentions, is below it.' );
		$this->assertSame( array( 'post/tag' ), $this->mentions->of_topic( $both )[1]['sources'], 'The mention of the tag stays, without the photographs.' );
		$this->assertSame( array(), $this->mentions->of_topic( $chosen ), 'A topic that a person chose stays, without its mention.' );
		$this->assertNotNull( $this->topics->get( $chosen ) );
		$this->assertSame( array( 'trace/start' ), $this->mentions->of_topic( $other )[1]['sources'], 'Another source is not touched.' );
		$this->assertNotNull( $this->topics->get( $mark ) );

		// The sub-sources go with their source: the tracks, then the broader topic that nothing mentions any more.
		$lines = array();

		$cmd(
			array(),
			array(
				'source' => 'trace',
				'apply' => true,
			)
		);

		$this->assertNull( $this->topics->get( $other ) );
		$this->assertNotNull( $this->topics->get( $country ), 'Espagne stays: Galice and Vigo are below it and Vigo is mentioned.' );
	}

	/**
	 * The complete reset (slice 623): a dry run counts and changes nothing; the real one stops the synchronisation, drops the mentions of the pages, the tags
	 * and the photographs, deletes the topics that nothing mentions any more, and forgets the state of the reads; what a person decided stays (a mention made
	 * by hand, a chosen kind, a refused topic); with `--everything` nothing stays.
	 *
	 * @return void
	 */
	public function test_the_reset(): void {
		$made    = $this->topics->create( 'Fait par une source', 'place', 0, Topics::APPROVED, array() );
		$shared  = $this->topics->create( 'Deux sources', 'place', 0, Topics::APPROVED, array() );
		$chosen  = $this->topics->create( 'Choisi', 'place', 0, Topics::APPROVED, array() );
		$refused = $this->topics->create( 'Refusé', 'place', 0, Topics::REFUSED, array() );
		$hand    = $this->topics->create( 'A la main', 'place', 0, Topics::APPROVED, array() );

		$this->topics->set_kind( $chosen, 'place', true );
		$this->mentions->sync(
			1,
			'post/link',
			array(
				$made => array(),
				$shared => array(),
			)
		);
		$this->mentions->sync(
			1,
			'hand',
			array(
				$hand => array(),
				$shared => array(),
			)
		);
		$this->mentions->sync( 2, 'image/address', array( $shared => array() ) );

		$lines   = array();
		$done    = array();
		$called  = array();
		$command = new ResetCommand(
			$this->topics,
			$this->mentions,
			new TopicPruner( $this->topics, $this->mentions ),
			static function () use ( &$called ) {
				$called[] = 'stop';
			},
			static function ( $everything ) use ( &$called ) {
				$called[] = $everything ? 'forget everything' : 'forget';
			},
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $line ) use ( &$done ) {
				$done[] = $line;
			}
		);

		$command( array(), array() );

		$this->assertSame( array(), $called, 'A dry run stops and forgets nothing.' );
		$this->assertSame( array( $made . "\tFait par une source [place]" ), $lines );
		$this->assertStringContainsString( '3 mention(s) would be dropped, 0 motif(s) and 1 topic(s) would be deleted', $done[0] );
		$this->assertNotNull( $this->topics->get( $made ) );
		$this->assertCount( 3, $this->mentions->mentioned_topics(), 'A dry run keeps every mention.' );

		$command( array(), array( 'apply' => true ) );

		$this->assertSame( array( 'stop', 'forget' ), $called );
		$this->assertNull( $this->topics->get( $made ), 'What only a source said goes.' );
		$this->assertNotNull( $this->topics->get( $shared ), 'It stays: a mention made by hand keeps it.' );
		$this->assertNotNull( $this->topics->get( $hand ) );
		$this->assertNotNull( $this->topics->get( $chosen ), 'A chosen kind stays.' );
		$this->assertNotNull( $this->topics->get( $refused ), 'A refused topic stays.' );
		$this->assertEquals(
			array(
				$hand => true,
				$shared => true,
			),
			$this->mentions->mentioned_topics()
		);

		$called = array();

		$command(
			array(),
			array(
				'apply' => true,
				'everything' => true,
			)
		);

		$this->assertSame( array( 'stop', 'forget everything' ), $called );
		$this->assertSame( array(), $this->mentions->mentioned_topics(), 'Every mention goes, the one made by hand too.' );
		$this->assertSame( array(), $this->topics->all(), 'Every topic goes, the refused and the chosen ones too.' );
	}

	/**
	 * The placeholders (slice 629): a candidate topic with a key `ambiguous:…` only stands for a doubt, which the motifs hold now: it goes; a candidate
	 * that waits for a decision, and a refused topic, stay.
	 *
	 * @return void
	 */
	public function test_the_placeholders_of_the_doubts_go(): void {
		$placeholder = $this->topics->create( 'Moulins', 'any', 0, Topics::AMBIGUOUS, array( 'ambiguous:wikipedia:fr:Moulins' ) );
		$waiting     = $this->topics->create( 'En attente', 'any', 0, Topics::AMBIGUOUS, array( 'wikipedia:fr:Attente' ) );
		$refused     = $this->topics->create( 'Refusé', 'any', 0, Topics::REFUSED, array( 'ambiguous:x' ) );

		$pruner = new TopicPruner( $this->topics, $this->mentions );

		$this->assertSame( array( $placeholder ), array_column( $pruner->candidates( array( 'any' ) ), 'id' ) );

		$pruner->prune( array( 'any' ), false );

		$this->assertNull( $this->topics->get( $placeholder ) );
		$this->assertNotNull( $this->topics->get( $waiting ) );
		$this->assertNotNull( $this->topics->get( $refused ) );
	}
}
