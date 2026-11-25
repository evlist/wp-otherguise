<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the mentions and of the indexer of the index (slice 600), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Index\Candidate;
use Otherguise\Index\Indexer;
use Otherguise\Index\Mentions;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Resolver;
use Otherguise\Index\Source;
use Otherguise\Index\Source\Terms;
use Otherguise\Index\Topics;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * The statements are real (MariaDB); the topics are in memory; posts and terms are stubs.
 *
 * @covers \Otherguise\Index\Mentions
 * @covers \Otherguise\Index\Indexer
 * @covers \Otherguise\Index\Source\Terms
 * @covers \Otherguise\Index\Module::register_predicates
 */
class IndexMentionsDbTest extends Otherguise_Test_Database_Case {

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
	 * The terms of each post, by taxonomy: post => list of term arrays.
	 *
	 * @var array<string, array<int, array<int, array<string, mixed>>>>
	 */
	private $post_terms = array();

	/**
	 * The terms by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $terms = array();

	/**
	 * The kind of each term.
	 *
	 * @var array<int, string>
	 */
	private $kinds = array();

	/**
	 * Published posts.
	 *
	 * @var int[]
	 */
	private $published = array( 12, 13 );

	/**
	 * The indexer.
	 *
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * Wires the statements, the topics and a source of categories.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Otherguise_Test_Memory_Kinds::install();

		otherguise_test_wp_objects( array( new WP_Post( 12 ), new WP_Post( 13 ), new WP_Post( 14 ) ) );

		$module     = new IndexModule();
		$site       = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $module ) {
					$module->register_predicates( $registry );
				},
			)
		);
		$statements = $site->statements;

		$this->topics  = new Otherguise_Test_Memory_Topics();
		$this->mentions = new Mentions(
			static function () use ( $statements ) {
				return $statements;
			}
		);

		$this->terms = array(
			1 => array(
				'id'     => 1,
				'name'   => 'France',
				'parent' => 0,
			),
			2 => array(
				'id'     => 2,
				'name'   => 'Beaufort',
				'parent' => 1,
			),
			3 => array(
				'id'     => 3,
				'name'   => 'Repos',
				'parent' => 0,
			),
		);
		$this->kinds = array(
			1 => 'place',
			2 => 'place',
		);

		$categories = new Terms(
			'category',
			'categories',
			function ( $post_id ) {
				return $this->post_terms[ $post_id ] ?? array();
			},
			function ( $id ) {
				return $this->terms[ $id ] ?? null;
			},
			function ( $id ) {
				return $this->kinds[ $id ] ?? '';
			}
		);

		$this->indexer = new Indexer(
			new Resolver( $this->topics ),
			$this->mentions,
			array( $categories ),
			function ( $post_id ) {
				return in_array( $post_id, $this->published, true );
			}
		);
	}

	/**
	 * Gives a post some terms.
	 *
	 * @param int   $post  Post.
	 * @param int[] $terms Term ids.
	 * @return void
	 */
	private function categorize( $post, array $terms ): void {
		$this->post_terms[ $post ] = array_map(
			function ( $id ) {
				return $this->terms[ $id ];
			},
			$terms
		);
	}

	/**
	 * A category with a kind is mentioned and its topic is approved, its parent is a topic too, and a category with no kind is read as well (slice 635): a candidate
	 * topic of the kind `any`, outside the index until a person approves it.
	 *
	 * @return void
	 */
	public function test_a_category_with_a_kind_is_mentioned(): void {
		$this->categorize( 12, array( 2, 3 ) );

		$report = $this->indexer->index_post( 12 );

		$this->assertSame( 3, $report['created'], 'Beaufort and its parent France, and Repos, which has no kind.' );
		$this->assertSame( 2, $report['added'] );

		$mentions = $this->mentions->of_post( 12 );

		$this->assertCount( 2, $mentions );

		$by_label = array();

		foreach ( array_keys( $mentions ) as $id ) {
			$by_label[ $this->topics->get( $id )['label'] ] = $this->topics->get( $id );
		}

		$this->assertSame( Topics::APPROVED, $by_label['Beaufort']['status'], 'A person chose its kind: sure.' );
		$this->assertSame( Topics::CANDIDATE, $by_label['Repos']['status'], 'Nobody classified it: a candidate, outside the index.' );
		$this->assertSame( 'any', $by_label['Repos']['kind'] );
		$this->assertSame( array( 'post/category' ), $mentions[ $by_label['Beaufort']['id'] ]['sources'] );
		$this->assertSame( 'passing', $mentions[ $by_label['Beaufort']['id'] ]['strength'] );
	}

	/**
	 * Running the indexer twice writes nothing the second time.
	 *
	 * @return void
	 */
	public function test_it_is_idempotent(): void {
		$this->categorize( 12, array( 2 ) );

		$this->indexer->index_post( 12 );
		$count = count( $this->wpdb->get_results( 'SELECT id FROM `' . $this->table() . '`' ) );
		$again = $this->indexer->index_post( 12 );

		$this->assertSame( 0, $again['added'] );
		$this->assertSame( 0, $again['created'] );
		$this->assertSame( 0, $again['removed'] );
		$this->assertSame( $count, count( $this->wpdb->get_results( 'SELECT id FROM `' . $this->table() . '`' ) ) );
	}

	/**
	 * A category taken off the post withdraws the mention; the topic stays.
	 *
	 * @return void
	 */
	public function test_a_category_taken_off_withdraws_the_mention(): void {
		$this->categorize( 12, array( 2 ) );
		$this->indexer->index_post( 12 );

		$this->categorize( 12, array() );
		$report = $this->indexer->index_post( 12 );

		$this->assertSame( 1, $report['removed'] );
		$this->assertSame( array(), $this->mentions->of_post( 12 ) );
		$this->assertCount( 2, $this->topics->topics );
	}

	/**
	 * Taking the kind off a term keeps its topic (slice 635: a term with no kind is read too, and the topic that was approved stays so); to take a term out of the
	 * index, exclude its motif.
	 *
	 * @return void
	 */
	public function test_taking_the_kind_off_keeps_the_topic(): void {
		$this->categorize( 12, array( 2 ) );
		$this->indexer->index_post( 12 );

		$topic = array_key_first( $this->mentions->of_post( 12 ) );

		unset( $this->kinds[2] );
		$this->indexer->index_post( 12 );

		$this->assertSame( array( $topic ), array_keys( $this->mentions->of_post( 12 ) ) );
		$this->assertSame( Topics::APPROVED, $this->topics->get( $topic )['status'] );
	}

	/**
	 * A post that is not published has no mention, and loses the ones it had.
	 *
	 * @return void
	 */
	public function test_a_draft_has_no_mention(): void {
		$this->categorize( 12, array( 2 ) );
		$this->categorize( 14, array( 2 ) );

		$this->indexer->index_post( 12 );
		$this->indexer->index_post( 14 );

		$this->assertSame( array(), $this->mentions->of_post( 14 ) );

		$this->published = array();
		$this->indexer->index_post( 12 );

		$this->assertSame( array(), $this->mentions->of_post( 12 ) );
	}

	/**
	 * The mention made by hand, and the one of another source, survive the sync of a source.
	 *
	 * @return void
	 */
	public function test_another_source_and_the_hand_are_not_touched(): void {
		$this->categorize( 12, array( 2 ) );
		$this->indexer->index_post( 12 );

		$topic = array_key_first( $this->mentions->of_post( 12 ) );

		$this->mentions->sync( 12, 'post/tag', array( $topic => 'main' ) );
		$this->mentions->add_by_hand( 12, $topic );

		$this->categorize( 12, array() );
		$this->indexer->index_post( 12 );

		$found = $this->mentions->of_post( 12 );

		$this->assertSame( array( 'hand', 'post/tag' ), $found[ $topic ]['sources'] );
		$this->assertSame( 'main', $found[ $topic ]['strength'], 'The strongest strength said wins.' );

		$this->mentions->sync( 12, 'post/tag', array() );
		$this->assertSame( array( 'hand' ), $this->mentions->of_post( 12 )[ $topic ]['sources'] );

		$this->mentions->remove_by_hand( 12, $topic );
		$this->assertSame( array(), $this->mentions->of_post( 12 ) );
	}

	/**
	 * The posts that mention a topic, and the posts that mention a topic below it when rolled up.
	 *
	 * @return void
	 */
	public function test_the_posts_of_an_topic_roll_up(): void {
		$this->categorize( 12, array( 2 ) );
		$this->categorize( 13, array( 1 ) );

		$this->indexer->index_post( 12 );
		$this->indexer->index_post( 13 );

		$beaufort = array_key_first( $this->mentions->of_post( 12 ) );
		$france   = $this->topics->get( $beaufort )['parent'];

		$this->assertSame( array( 13 ), array_keys( $this->mentions->of_topic( $france ) ) );
		$this->assertSame( array( 12, 13 ), array_keys( $this->mentions->of_topic( $france, array( $beaufort ) ) ) );
	}

	/**
	 * A dry run writes no topic and no statement.
	 *
	 * @return void
	 */
	public function test_a_dry_run_writes_nothing(): void {
		$this->categorize( 12, array( 2 ) );

		$report = $this->indexer->index_post( 12, null, true );

		$this->assertSame( 2, $report['created'] );
		$this->assertSame( array(), $this->topics->topics );
		$this->assertSame( array(), $this->mentions->of_post( 12 ) );
	}

	/**
	 * Only the source asked for is read.
	 *
	 * @return void
	 */
	public function test_only_one_source(): void {
		$this->categorize( 12, array( 2 ) );

		$report = $this->indexer->index_post( 12, array( 'tags' ) );

		$this->assertSame( 0, $report['created'] );
		$this->assertSame( array( 'categories' ), $this->indexer->source_ids() );
	}

	/**
	 * A strong mention wins over a passing one of the same topic in one source.
	 *
	 * @return void
	 */
	public function test_main_wins_over_passing_in_one_source(): void {
		$source = new class() implements Source {
			/**
			 * Slug.
			 *
			 * @return string
			 */
			public function id() {
				return 'test';
			}

			/**
			 * The source of its mentions.
			 *
			 * @return string[]
			 */
			public function mention_sources() {
				return array( 'post/link' );
			}

			/**
			 * Sure.
			 *
			 * @return bool
			 */
			public function is_sure() {
				return true;
			}

			/**
			 * Two candidates for one topic.
			 *
			 * @param int $post_id Post.
			 * @return Candidate[]
			 */
			public function candidates( $post_id ) {
				unset( $post_id );

				return array(
					new Candidate( 'Beaufort', 'place', array( 'k:1' ), null, Candidate::PASSING ),
					new Candidate( 'Beaufort', 'place', array( 'k:1' ), null, Candidate::MAIN ),
				);
			}
		};

		$indexer = new Indexer( new Resolver( $this->topics ), $this->mentions, array( $source ), fn() => true );
		$indexer->index_post( 12 );

		$this->assertSame( 'main', array_values( $this->mentions->of_post( 12 ) )[0]['strength'] );
	}
}
