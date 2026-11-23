<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the merge of topics and of the search for duplicates (slice 616), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Index\Candidate;
use Otherguise\Index\Cli\MergeCommand;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Merge\DuplicateFinder;
use Otherguise\Index\Merge\TopicMerger;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Resolver;
use Otherguise\Index\Topics;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * The statements are real; the topics and the kinds are in memory.
 *
 * @covers \Otherguise\Index\Merge\TopicMerger
 * @covers \Otherguise\Index\Merge\DuplicateFinder
 * @covers \Otherguise\Index\Cli\MergeCommand
 * @covers \Otherguise\Index\Mentions::move_topic
 */
class IndexMergeDbTest extends Otherguise_Test_Database_Case {

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
	 * The merger.
	 *
	 * @var TopicMerger
	 */
	private $merger;

	/**
	 * Wires the statements.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Otherguise_Test_Memory_Kinds::install();
		Kinds::add( 'commune', 'Commune', 'place' );
		Kinds::add( 'admin1', 'Division', 'place' );
		otherguise_test_wp_objects( array( new WP_Post( 1 ), new WP_Post( 2 ), new WP_Post( 3 ) ) );

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
		$this->merger   = new TopicMerger( $this->topics, $this->mentions );
	}

	/**
	 * The number of rows of the table of the statements.
	 *
	 * @return int
	 */
	private function rows(): int {
		return (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The mentions go to the topic that stays: a post that had both has one mention with the union, the strongest strength; nothing stays on the
	 * topic that goes, not even a row of the table; the keys, the kind and the children follow.
	 *
	 * @return void
	 */
	public function test_the_mentions_keys_kind_and_children_follow(): void {
		$keep = $this->topics->create( 'Aragon', 'any', 0, Topics::APPROVED, array( 'label:0/aragon' ) );
		$go   = $this->topics->create( 'Aragon (communauté autonome)', 'admin1', 0, Topics::APPROVED, array( 'wikipedia:fr:Aragon (communauté autonome)', 'label:0/aragon (communaute autonome)' ) );

		$this->mentions->sync(
			1,
			'post/link',
			array(
				$go => array(
					'strength' => 'passing',
					'via' => array( 'link:fr:Aragon' ),
					'visible' => array( 'web' ),
				),
			)
		);
		$this->mentions->sync( 2, 'post/tag', array( $keep => array( 'strength' => 'passing' ) ) );
		$this->mentions->sync(
			2,
			'post/mark',
			array(
				$go => array(
					'strength' => 'main',
					'via' => array( 'mark:#a' ),
				),
			)
		);
		$this->mentions->sync(
			3,
			'image/address',
			array(
				$keep => array(
					'strength' => 'passing',
					'via' => array( 'image:abc' ),
				),
			)
		);

		$before = $this->rows();
		$report = $this->merger->merge( $keep, $go, true );

		$this->assertTrue( $report['ok'] );
		$this->assertSame( 1, $report['mentions_moved'] );
		$this->assertSame( 1, $report['mentions_joined'] );
		$this->assertSame( $before, $this->rows(), 'A dry run changes nothing.' );
		$this->assertNotNull( $this->topics->get( $go ) );

		$report = $this->merger->merge( $keep, $go );

		$this->assertTrue( $report['ok'], $report['error'] );
		$this->assertNull( $this->topics->get( $go ), 'It goes away.' );
		$this->assertSame( array(), $this->mentions->of_topic( $go ), 'Nothing is left on it.' );

		$of_keep = $this->mentions->of_topic( $keep );

		$this->assertSame( array( 1, 2, 3 ), array_keys( $of_keep ) );
		$this->assertSame( array( 'post/link' ), $of_keep[1]['sources'] );
		$this->assertSame( array( 'link:fr:Aragon' ), $of_keep[1]['details']['post/link']['via'] );
		$this->assertSame( array( 'web' ), $of_keep[1]['details']['post/link']['visible'] );
		$this->assertSame( array( 'post/mark', 'post/tag' ), $of_keep[2]['sources'], 'A post that had both: the union.' );
		$this->assertSame( 'main', $of_keep[2]['strength'], 'The strongest.' );

		$topic = $this->topics->get( $keep );

		$this->assertSame( 'admin1', $topic['kind'], 'The kind that was not classified takes the more precise one.' );
		$this->assertContains( 'wikipedia:fr:Aragon (communauté autonome)', $topic['keys'], 'A source that names the other finds this one.' );
		$this->assertContains( 'label:0/aragon (communaute autonome)', $topic['keys'] );

		// The rows of the mention that went are gone (qualifiers included): three mentions with their qualifiers remain, nothing of the old ones.
		$this->assertLessThan( $before, $this->rows() );
	}

	/**
	 * The visibility of a source: a source that showed a mention in every mode keeps showing it in every mode, whichever side said it.
	 *
	 * @return void
	 */
	public function test_every_mode_stays_every_mode(): void {
		$keep = $this->topics->create( 'A', 'place', 0, Topics::APPROVED, array() );
		$go   = $this->topics->create( 'B', 'place', 0, Topics::APPROVED, array() );

		// One sync per post and source: it says every topic of that source in that post.
		$this->mentions->sync(
			1,
			'post/link',
			array(
				$keep => array( 'visible' => array( 'web' ) ),
				$go => array(),
			)
		);
		$this->mentions->sync(
			2,
			'post/link',
			array(
				$keep => array( 'visible' => array( 'web' ) ),
				$go => array( 'visible' => array( 'print' ) ),
			)
		);
		$this->mentions->sync(
			3,
			'post/link',
			array(
				$keep => array(),
				$go => array( 'visible' => array( 'print' ) ),
			)
		);

		$this->merger->merge( $keep, $go );

		$of = $this->mentions->of_topic( $keep );

		$this->assertNull( $of[1]['details']['post/link']['visible'], 'Web on one side, every mode on the other: every mode.' );
		$modes = (array) $of[2]['details']['post/link']['visible'];

		sort( $modes );

		$this->assertSame( array( 'print', 'web' ), $modes, 'Two lists of modes: their union.' );
		$this->assertNull( $of[3]['details']['post/link']['visible'], 'Every mode on this side: every mode.' );
	}

	/**
	 * The children go under the topic that stays, with the key that a source makes for them there, so that the resolver does not make them again; two
	 * children with the same label are merged in turn.
	 *
	 * @return void
	 */
	public function test_the_children_follow(): void {
		$keep = $this->topics->create( 'Zaragoza', 'commune', 0, Topics::APPROVED, array( 'label:0/zaragoza' ) );
		$go   = $this->topics->create( 'Saragosse', 'commune', 0, Topics::APPROVED, array( 'label:0/saragosse' ) );
		$mine = $this->topics->create( 'Pilar', 'place', $keep, Topics::APPROVED, array( 'label:' . $keep . '/pilar' ) );
		$twin = $this->topics->create( 'Pilar', 'place', $go, Topics::APPROVED, array( 'label:' . $go . '/pilar' ) );
		$only = $this->topics->create( 'Aljafería', 'place', $go, Topics::APPROVED, array( 'label:' . $go . '/aljaferia' ) );

		$this->mentions->sync( 1, 'post/tag', array( $twin => array() ) );

		$report = $this->merger->merge( $keep, $go );

		$this->assertTrue( $report['ok'], $report['error'] );
		$this->assertSame( 1, $report['children_moved'] );
		$this->assertSame( 1, $report['children_merged'] );
		$this->assertNull( $this->topics->get( $twin ), 'The twin merged into the child that was there.' );
		$this->assertSame( array( 1 ), array_keys( $this->mentions->of_topic( $mine ) ), 'Its mention followed.' );
		$this->assertSame( $keep, $this->topics->get( $only )['parent'] );

		// A source that names the child under the name of the topic that went: the resolver finds it, it makes no double.
		$resolver = new Resolver( $this->topics );
		$parent   = new Candidate( 'Saragosse', 'commune', array(), null, Candidate::PASSING );
		$found    = $resolver->resolve( new Candidate( 'Aljafería', 'place', array(), $parent, Candidate::PASSING ) );

		$this->assertSame( $only, $found['topic'] );
	}

	/**
	 * What is refused: a topic with itself or one that does not exist, kinds that cannot be one without `--force`, the topic that stays below the one
	 * that goes; a kind that a person chose is kept.
	 *
	 * @return void
	 */
	public function test_what_is_refused(): void {
		$place   = $this->topics->create( 'Beaufort', 'place', 0, Topics::APPROVED, array() );
		$species = $this->topics->create( 'Beaufort', 'species', 0, Topics::APPROVED, array() );
		$child   = $this->topics->create( 'Hameau', 'place', $place, Topics::APPROVED, array() );

		$this->assertFalse( $this->merger->merge( $place, $place )['ok'] );
		$this->assertFalse( $this->merger->merge( $place, 999 )['ok'] );
		$this->assertStringContainsString( 'not one a sort of the other', $this->merger->merge( $place, $species )['error'] );
		$this->assertStringContainsString( 'below the one that goes', $this->merger->merge( $child, $place )['error'] );
		$this->assertNotNull( $this->topics->get( $species ), 'Nothing was done.' );

		$forced = $this->merger->merge( $place, $species, false, true );

		$this->assertTrue( $forced['ok'] );

		$hand = $this->topics->create( 'Hand', 'any', 0, Topics::APPROVED, array() );
		$more = $this->topics->create( 'Hand', 'commune', 0, Topics::APPROVED, array() );

		$this->topics->set_kind( $hand, 'any', true );
		$this->merger->merge( $hand, $more );

		$this->assertSame( 'any', $this->topics->get( $hand )['kind'], 'A kind chosen by a person is kept.' );
	}

	/**
	 * The duplicates: the same label without the parentheses, or a letter or two apart under one parent; not homonyms under two parents, not kinds that
	 * cannot be one, not a topic and its child.
	 *
	 * @return void
	 */
	public function test_the_duplicates_are_offered_not_merged(): void {
		$spain = $this->topics->create( 'Espagne', 'place', 0, Topics::APPROVED, array() );
		$a     = $this->topics->create( 'Aragon', 'admin1', $spain, Topics::APPROVED, array() );
		$b     = $this->topics->create( 'Aragon (communauté autonome)', 'any', 0, Topics::APPROVED, array() );
		$x     = $this->topics->create( 'Iturtzeta', 'site', $spain, Topics::APPROVED, array() );
		$y     = $this->topics->create( 'Iturzaeta', 'site', $spain, Topics::APPROVED, array() );
		$jura  = $this->topics->create( 'Jura', 'place', 0, Topics::APPROVED, array() );
		$sav   = $this->topics->create( 'Savoie', 'place', 0, Topics::APPROVED, array() );
		$this->topics->create( 'Beaufort', 'commune', $jura, Topics::APPROVED, array() );
		$this->topics->create( 'Beaufort', 'commune', $sav, Topics::APPROVED, array() );
		$this->topics->create( 'Merle', 'species', 0, Topics::APPROVED, array() );
		$this->topics->create( 'Merle (musique)', 'work', 0, Topics::APPROVED, array() );
		$this->topics->create( 'Pending', 'place', 0, Topics::CANDIDATE, array() );
		$this->topics->create( 'Pending (x)', 'place', 0, Topics::APPROVED, array() );

		$pairs = ( new DuplicateFinder( $this->topics ) )->pairs();
		$ids   = array_map( static fn( $pair ) => array( $pair['a']['id'], $pair['b']['id'] ), $pairs );

		$this->assertContains( array( $a, $b ), $ids, 'Aragon and Aragon (communauté autonome).' );
		$this->assertContains( array( $x, $y ), $ids, 'Two letters apart under one parent.' );
		$this->assertCount( 2, $ids, 'Not the homonyms of two parents, not the kinds that cannot be one, not a candidate.' );
		$this->assertSame( 'same label without the parentheses', $pairs[0]['why'] );
	}

	/**
	 * The commands: the merge reports and stops at the first failure; a dry run says it; the duplicates are listed.
	 *
	 * @return void
	 */
	public function test_the_commands(): void {
		$keep = $this->topics->create( 'Aragon', 'any', 0, Topics::APPROVED, array() );
		$go   = $this->topics->create( 'Aragon (x)', 'any', 0, Topics::APPROVED, array() );
		$this->mentions->sync( 1, 'post/tag', array( $go => array() ) );

		$lines = array();
		$done  = array();
		$fail  = array();
		$cmd   = new MergeCommand(
			$this->merger,
			new DuplicateFinder( $this->topics ),
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

		$cmd( array( (string) $keep ), array() );
		$this->assertStringContainsString( 'at least one that goes', $fail[0] );

		$cmd->duplicates( array(), array() );
		$this->assertStringContainsString( 'Aragon (x)', $lines[0] );
		$this->assertStringContainsString( '1 pair(s)', $done[0] );

		$cmd( array( (string) $keep, (string) $go ), array( 'dry-run' => true ) );
		$this->assertStringContainsString( 'Dry run, nothing changed: 1 mention(s) moved', $done[1] );
		$this->assertNotNull( $this->topics->get( $go ) );

		$cmd( array( (string) $keep, (string) $go ), array() );
		$this->assertStringStartsWith( 'Merged: 1 mention(s) moved', $done[2] );
		$this->assertNull( $this->topics->get( $go ) );

		$cmd( array( (string) $keep, (string) $go ), array() );
		$this->assertStringContainsString( 'Both topics must exist', $fail[1], 'Run twice: it says there is nothing to merge.' );
	}
}
