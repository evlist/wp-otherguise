<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the motifs, the observations and the assignments (slice 626), on a real database for the statements.
 *
 * @package Otherguise
 */

use Otherguise\Index\Candidate;
use Otherguise\Index\Cli\DesignationsCommand;
use Otherguise\Index\Cli\ResetCommand;
use Otherguise\Index\Designation\Assigner;
use Otherguise\Index\Designation\Migration;
use Otherguise\Index\Merge\TopicMerger;
use Otherguise\Index\Merge\TopicPruner;
use Otherguise\Index\Designation\Cleaner;
use Otherguise\Index\Designation\Evidence;
use Otherguise\Index\Designation\Exclusion;
use Otherguise\Index\Designation\Reresolver;
use Otherguise\Index\Designation\Keys;
use Otherguise\Index\Designations;
use Otherguise\Index\Indexer;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Observations;
use Otherguise\Index\Resolver;
use Otherguise\Index\Source;
use Otherguise\Index\Topics;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-designations.php';

/**
 * The statements are real; the topics, the motifs and the kinds are in memory.
 *
 * @covers \Otherguise\Index\Observations
 * @covers \Otherguise\Index\Designation\Keys
 * @covers \Otherguise\Index\Designation\Cleaner
 * @covers \Otherguise\Index\Indexer
 * @covers \Otherguise\Index\Mentions
 */
class IndexDesignationsDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The topics.
	 *
	 * @var Otherguise_Test_Memory_Topics
	 */
	private $topics;

	/**
	 * The motifs.
	 *
	 * @var Otherguise_Test_Memory_Designations
	 */
	private $designations;

	/**
	 * The observations.
	 *
	 * @var Observations
	 */
	private $observations;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * What the source says, by post: a list of candidates.
	 *
	 * @var array<int, Candidate[]>
	 */
	private $says = array();

	/**
	 * The indexer.
	 *
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * Wires the statements, the source and the indexer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Otherguise_Test_Memory_Kinds::install();
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

		$statements         = $site->statements;
		$get                = static function () use ( $statements ) {
			return $statements;
		};
		$this->topics       = new Otherguise_Test_Memory_Topics();
		$this->designations = new Otherguise_Test_Memory_Designations();
		$this->observations = new Observations( $get );
		$this->mentions     = new Mentions( $get, $this->observations, $this->designations );
		$this->says         = array();

		$says         = &$this->says;
		$source       = new class( $says ) implements Source {
			/**
			 * What it says.
			 *
			 * @var array
			 */
			private $says;

			/**
			 * Builds the source.
			 *
			 * @param array $says What it says.
			 */
			public function __construct( array &$says ) {
				$this->says = &$says;
			}

			/**
			 * The slug.
			 *
			 * @return string
			 */
			public function id() {
				return 'links';
			}

			/**
			 * The leaves.
			 *
			 * @return string[]
			 */
			public function mention_sources() {
				return array( 'post/link' );
			}

			/**
			 * Whether it is sure.
			 *
			 * @return bool
			 */
			public function is_sure() {
				return true;
			}

			/**
			 * The candidates.
			 *
			 * @param int $post_id Post.
			 * @return Candidate[]
			 */
			public function candidates( $post_id ) {
				return $this->says[ $post_id ] ?? array();
			}
		};
		$this->indexer = new Indexer( new Resolver( $this->topics ), $this->mentions, array( $source ), static fn() => true, $this->designations, $this->observations );
	}

	/**
	 * A candidate: a link to a Wikipedia page.
	 *
	 * @param string $label Label.
	 * @param string $page  The page.
	 * @param string $kind  Kind.
	 * @param string $via   What produced it.
	 * @return Candidate
	 */
	private function link( $label, $page, $kind = 'any', $via = 'a' ): Candidate {
		return ( new Candidate( $label, $kind, array( 'wikipedia:fr:' . $page ), null, Candidate::PASSING ) )->from( 'post/link', array( $via ) );
	}

	/**
	 * The key of a motif: the strongest identity, the source in front; a chain of labels when there is none.
	 *
	 * @return void
	 */
	public function test_the_key_of_a_designation(): void {
		$france  = new Candidate( 'France', 'country', array( 'label:0/france' ) );
		$commune = new Candidate( 'Moulins', 'commune', array( 'label:5/moulins' ), $france );

		$this->assertSame( 'post/link|wikipedia:fr:Allier_(rivière)', Keys::of( $this->link( 'Allier', 'Allier_(rivière)' ), 'post/link' ) );
		$this->assertSame( 'image/address|chain:france > moulins', Keys::of( $commune, 'image/address' ) );
		$this->assertSame(
			'post/link|wikidata:Q184883',
			Keys::of( new Candidate( 'Moulins', 'commune', array( 'label:0/moulins', 'wikidata:Q184883', 'wikipedia:fr:Moulins' ) ), 'post/link' ),
			'An identifier of Wikidata is stronger than one of Wikipedia.'
		);
		$this->assertSame( 'hand|topic:7', Keys::by_hand( 7 ) );
		$this->assertLessThanOrEqual( 180, mb_strlen( Keys::of( new Candidate( str_repeat( 'x', 400 ), 'any', array() ), 'post/mark' ) ) );
	}

	/**
	 * A link seen in two posts is **one motif** with two observations; reading again changes nothing; the mentions are the same as without the model.
	 *
	 * @return void
	 */
	public function test_one_designation_many_observations(): void {
		$this->says[1] = array( $this->link( 'Allier', 'Allier_(rivière)', 'any', 'a' ) );
		$this->says[2] = array( $this->link( 'Allier', 'Allier_(rivière)', 'any', 'b' ), $this->link( 'Allier', 'Allier_(rivière)', 'any', 'c' ) );

		$this->indexer->index_post( 1 );
		$this->indexer->index_post( 2 );
		$this->indexer->index_post( 2 );

		$this->assertCount( 1, $this->designations->designations, 'One motif, whatever the posts.' );

		$id    = (int) array_key_first( $this->designations->designations );
		$topic = $this->observations->assignment( $id );

		$this->assertSame( Observations::SYSTEM, $topic['by'] );
		$this->assertSame( Designations::ASSIGNED, $this->designations->get( $id )['state'] );
		$this->assertSame( array( 1, 2 ), $this->sorted( $this->observations->posts_of( $id ) ) );
		$this->assertSame( array( 'b', 'c' ), $this->observations->of_post( 2 )[ $id ]['via'], 'The occurrences of a post are the values of its qualifiers.' );
		$this->assertSame( 'post/link', $this->observations->of_post( 1 )[ $id ]['source'] );
		$this->assertArrayHasKey( $topic['topic'], $this->mentions->of_post( 1 ), 'The mention is the one of the topic.' );
		$this->assertSame( 'Allier', $this->topics->get( $topic['topic'] )['label'] );
	}

	/**
	 * What a person assigned is never changed by a reading: the motif goes where it was put, the resolver is not asked, and the mention follows.
	 *
	 * @return void
	 */
	public function test_an_assignment_by_hand_is_kept(): void {
		$department = $this->topics->create( 'Allier', 'admin2', 0, Topics::APPROVED, array() );
		$river      = $this->topics->create( 'Allier', 'waterway', 0, Topics::APPROVED, array() );

		$this->says[1] = array( $this->link( 'Allier', 'Allier_(rivière)' ) );
		$this->says[3] = array( $this->link( 'Allier', 'Allier_(rivière)' ) );

		$this->indexer->index_post( 1 );
		$this->indexer->index_post( 3 );

		$id = (int) array_key_first( $this->designations->designations );

		$this->assertTrue( $this->observations->assign( $id, $river, Observations::HAND ) );
		$this->assertFalse( $this->observations->assign( $id, $river, Observations::HAND ), 'Nothing changed.' );

		$before = count( $this->topics->topics );

		$this->indexer->index_post( 1 );

		$this->assertSame( array( $river ), array_keys( $this->mentions->of_post( 1 ) ), 'The mention goes to the topic that was chosen.' );
		$this->assertSame( $before, count( $this->topics->topics ), 'The resolver was not asked: no topic was made.' );
		$this->assertSame( array( $id => Observations::HAND ), $this->observations->designations_of_topic( $river ) );
		$this->assertSame( array(), $this->observations->designations_of_topic( $department ) );
		$this->assertArrayHasKey( $river, $this->mentions->mentioned_topics() + $this->mentions->mentioned_topics() );
	}

	/**
	 * A topic that a decision holds is not mentioned by a post, but is not cleaned away either; a source dropped takes its observations and not its
	 * motifs; the cleaner deletes the motifs that the system proposed and that nobody observes, not the ones that a person assigned.
	 *
	 * @return void
	 */
	public function test_dropping_a_source_and_cleaning(): void {
		$river = $this->topics->create( 'Allier', 'waterway', 0, Topics::APPROVED, array() );

		$this->says[1] = array( $this->link( 'Allier', 'Allier_(rivière)' ), $this->link( 'Lyon', 'Lyon' ) );
		$this->indexer->index_post( 1 );

		$ids = array_keys( $this->designations->designations );
		$this->observations->assign( $ids[0], $river, Observations::HAND );

		$dropped = $this->mentions->drop_source( 'post' );

		$this->assertSame( 2, $dropped['mentions'] );
		$this->assertSame( array(), $this->observations->of_post( 1 ), 'The observations go with the source.' );
		$this->assertCount( 2, $this->designations->designations, 'The motifs stay.' );
		$this->assertArrayHasKey( $river, $this->mentions->mentioned_topics(), 'A decision holds its topic.' );

		$cleaner = new Cleaner( $this->designations, $this->observations );

		$this->assertSame( array( $ids[1] ), $cleaner->candidates(), 'The one that a person assigned stays.' );
		$this->assertSame( 2, count( $cleaner->candidates( true ) ) );
		$this->assertSame( 1, $cleaner->delete( $cleaner->candidates() ) );
		$this->assertNull( $this->observations->assignment( $ids[1] ) );
		$this->assertNotNull( $this->designations->get( $ids[0] ) );
	}

	/**
	 * Merging two topics assigns the motifs of the first to the second, by a person.
	 *
	 * @return void
	 */
	public function test_a_move_of_topic_assigns_the_designations(): void {
		$this->says[1] = array( $this->link( 'Moulins', 'Moulins' ) );
		$this->indexer->index_post( 1 );

		$id    = (int) array_key_first( $this->designations->designations );
		$from  = $this->observations->assignment( $id )['topic'];
		$to    = $this->topics->create( 'Moulins (Allier)', 'commune', 0, Topics::APPROVED, array() );
		$moved = $this->mentions->move_topic( $from, $to );

		$this->assertSame( 1, $moved['moved'] );
		$this->assertSame(
			array(
				'topic' => $to,
				'by' => Observations::HAND,
			),
			$this->observations->assignment( $id )
		);

		$this->indexer->index_post( 1 );

		$this->assertSame( array( $to ), array_keys( $this->mentions->of_post( 1 ) ), 'A new reading follows the decision.' );
	}

	/**
	 * A mention made by a person has its motif (the source `hand`), assigned by hand, and its observation.
	 *
	 * @return void
	 */
	public function test_a_mention_by_hand(): void {
		$topic = $this->topics->create( 'Choisi', 'place', 0, Topics::APPROVED, array() );

		$this->mentions->add_by_hand( 1, $topic );

		$id = $this->designations->find( Keys::by_hand( $topic ) );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame(
			array(
				'topic' => $topic,
				'by' => Observations::HAND,
			),
			$this->observations->assignment( $id )
		);
		$this->assertSame( array( 1 ), $this->observations->posts_of( $id ) );

		$this->mentions->remove_by_hand( 1, $topic );

		$this->assertSame( array(), $this->observations->posts_of( $id ) );
		$this->assertSame( array(), $this->mentions->of_post( 1 ) );
	}

	/**
	 * The assignment by a person: a dry run changes nothing; the real one moves the motif, **the mentions of the posts follow without reading
	 * the pages**, the motif and its observations stay, and a kind conflict is refused unless forced.
	 *
	 * @return void
	 */
	public function test_the_assigner(): void {
		$river      = $this->topics->create( 'Allier', 'waterway', 0, Topics::APPROVED, array( 'wikidata:Q2464' ) );
		$department = $this->topics->create( 'Allier', 'admin2', 0, Topics::APPROVED, array( 'wikidata:Q12561' ) );

		$this->says[1] = array( $this->link( 'Allier', 'Allier_(rivière)', 'admin2' ) );
		$this->says[2] = array( $this->link( 'Allier', 'Allier_(rivière)', 'admin2' ) );
		$this->indexer->index_post( 1 );
		$this->indexer->index_post( 2 );

		$id       = (int) array_key_first( $this->designations->designations );
		$assigner = new Assigner( $this->designations, $this->observations, $this->mentions, $this->topics );
		$was      = $this->observations->assignment( $id )['topic'];

		$dry = $assigner->assign( array( $id ), $river );

		$this->assertFalse( $dry['ok'], 'Neither kind is a sort of the other.' );
		$this->assertStringContainsString( '--force', $dry['error'] );

		$dry = $assigner->assign( array( $id ), $river, true, true );

		$this->assertTrue( $dry['ok'] );
		$this->assertSame( array( 1, 2, 2 ), array( $dry['designations'], $dry['observations'], $dry['posts'] ) );
		$this->assertSame( $was, $this->observations->assignment( $id )['topic'], 'A dry run changes nothing.' );

		$done = $assigner->assign( array( $id ), $river, false, true );

		$this->assertTrue( $done['ok'] );
		$this->assertSame(
			array(
				'topic' => $river,
				'by' => Observations::HAND,
			),
			$this->observations->assignment( $id )
		);
		$this->assertSame( array( $river ), array_keys( $this->mentions->of_post( 1 ) ), 'The mentions follow, with no reading.' );
		$this->assertSame( array( $river ), array_keys( $this->mentions->of_post( 2 ) ) );
		$this->assertSame( array( 1, 2 ), $this->sorted( $this->observations->posts_of( $id ) ), 'The observations stay.' );

		$this->indexer->index_post( 1 );

		$this->assertSame( array( $river ), array_keys( $this->mentions->of_post( 1 ) ), 'A reading does not undo it.' );
		$this->assertFalse( $assigner->assign( array( 999999 ), $river )['ok'] );
		$this->assertFalse( $assigner->assign( array( $id ), 424242 )['ok'] );
		$this->assertNotNull( $this->topics->get( $department ) );
	}

	/**
	 * The mentions made by hand before the model are converted: a motif assigned by hand and an observation; run twice, it finds them again.
	 *
	 * @return void
	 */
	public function test_the_migration_of_the_mentions_made_by_hand(): void {
		$topic = $this->topics->create( 'Choisi', 'place', 0, Topics::APPROVED, array() );
		$plain = new Mentions( fn() => $this->statementsOf( $this->mentions ) );

		$plain->add_by_hand( 1, $topic );
		$plain->add_by_hand( 2, $topic );

		$migration = new Migration( fn() => $this->statementsOf( $this->mentions ), $this->designations, $this->observations );

		$this->assertSame(
			array(
				'posts' => 2,
				'observations' => 2,
				'created' => 2,
			),
			$migration->run( true ),
			'A dry run counts (one motif is counted for each post).'
		);
		$this->assertCount( 0, $this->designations->designations );

		$migration->run( false );
		$migration->run( false );

		$id = $this->designations->find( Keys::by_hand( $topic ) );

		$this->assertCount( 1, $this->designations->designations );
		$this->assertSame( array( 1, 2 ), $this->sorted( $this->observations->posts_of( $id ) ) );
		$this->assertSame(
			array(
				'topic' => $topic,
				'by' => Observations::HAND,
			),
			$this->observations->assignment( $id )
		);
	}

	/**
	 * The commands: the list, the assignment (a dry run first), the cleaning, the measure.
	 *
	 * @return void
	 */
	public function test_the_commands(): void {
		$river = $this->topics->create( 'Allier', 'waterway', 0, Topics::APPROVED, array() );

		$this->says[1] = array( $this->link( 'Allier', 'Allier_(rivière)' ), $this->link( 'Lyon', 'Lyon' ) );
		$this->indexer->index_post( 1 );

		$lines   = array();
		$done    = array();
		$command = new DesignationsCommand(
			$this->designations,
			$this->observations,
			$this->topics,
			new Assigner( $this->designations, $this->observations, $this->mentions, $this->topics ),
			new Cleaner( $this->designations, $this->observations ),
			new Migration( fn() => $this->statementsOf( $this->mentions ), $this->designations, $this->observations ),
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $line ) use ( &$done ) {
				$done[] = $line;
			},
			static function () {
				throw new RuntimeException( 'A command failed.' );
			},
			new TopicMerger( $this->topics, $this->mentions )
		);

		$command->list( array(), array() );

		$this->assertCount( 2, $lines );
		$this->assertStringContainsString( 'post/link|wikipedia:fr:Allier_(rivière)', $lines[0] );
		$this->assertStringContainsString( ', system)', $lines[0] );
		$this->assertSame( '2 motif(s).', $done[0] );

		$first = (int) array_key_first( $this->designations->designations );
		$lines = array();

		$command->assign(
			array(),
			array(
				'topic' => $river,
				'motif' => (string) $first,
			)
		);

		$this->assertStringContainsString( 'nothing was changed', end( $done ) );
		$this->assertNotSame( $river, $this->observations->assignment( $first )['topic'] );

		$command->assign(
			array(),
			array(
				'topic' => $river,
				'motif' => (string) $first,
				'apply' => true,
			)
		);

		$this->assertSame( $river, $this->observations->assignment( $first )['topic'] );

		$command->measure( array(), array() );

		$this->assertStringContainsString( 'designations: 2', implode( "\n", $lines ) );
		$this->assertStringContainsString( 'observations: 2, assignments: 2 (by hand: 1)', implode( "\n", $lines ) );

		$this->expectException( RuntimeException::class );
		$command->assign( array(), array( 'topic' => $river ) );
	}

	/**
	 * `assign --from-topic --with-sub-topics`: everything of a topic goes to another, down the tree; a preview first; the topic that is left with nothing
	 * is deleted.
	 *
	 * @return void
	 */
	public function test_assigning_a_topic_with_its_sub_topics(): void {
		$to   = $this->topics->create( 'Aragon', 'admin1', 0, Topics::APPROVED, array() );
		$from = $this->topics->create( 'Aragon (x)', 'admin1', 0, Topics::APPROVED, array( 'wikipedia:fr:Aragon_(x)' ) );
		$kid  = $this->topics->create( 'Huesca', 'admin2', $from, Topics::APPROVED, array() );

		$this->says[1] = array( $this->link( 'Aragon (x)', 'Aragon_(x)' ) );
		$this->indexer->index_post( 1 );

		$id = (int) array_key_first( $this->designations->designations );

		$this->assertSame( $from, $this->observations->assignment( $id )['topic'] );

		$done    = array();
		$command = new DesignationsCommand(
			$this->designations,
			$this->observations,
			$this->topics,
			new Assigner( $this->designations, $this->observations, $this->mentions, $this->topics ),
			new Cleaner( $this->designations, $this->observations ),
			new Migration( fn() => $this->statementsOf( $this->mentions ), $this->designations, $this->observations ),
			static function () {},
			static function ( $line ) use ( &$done ) {
				$done[] = $line;
			},
			static function () {
				throw new RuntimeException( 'A command failed.' );
			},
			new TopicMerger( $this->topics, $this->mentions )
		);

		$command->assign(
			array(),
			array(
				'topic' => $to,
				'from-topic' => $from,
				'with-sub-topics' => true,
			)
		);

		$this->assertStringContainsString( 'Nothing changed', end( $done ) );
		$this->assertNotNull( $this->topics->get( $from ) );

		$command->assign(
			array(),
			array(
				'topic' => $to,
				'from-topic' => $from,
				'with-sub-topics' => true,
				'apply' => true,
			)
		);

		$this->assertSame(
			array(
				'topic' => $to,
				'by' => Observations::HAND,
			),
			$this->observations->assignment( $id )
		);
		$this->assertSame( array( $to ), array_keys( $this->mentions->of_post( 1 ) ) );
		$this->assertNull( $this->topics->get( $from ), 'The topic that is left with nothing is deleted.' );
		$this->assertSame( $to, $this->topics->get( $kid )['parent'], 'Its sub-topic moved under the one that received.' );
	}

	/**
	 * The reset takes the motifs that the system proposed and keeps the ones that a person assigned; with `--everything` nothing stays.
	 *
	 * @return void
	 */
	public function test_the_reset_and_the_designations(): void {
		$river = $this->topics->create( 'Allier', 'waterway', 0, Topics::APPROVED, array() );

		$this->says[1] = array( $this->link( 'Allier', 'Allier_(rivière)' ), $this->link( 'Lyon', 'Lyon' ) );
		$this->indexer->index_post( 1 );

		$kept = (int) array_key_first( $this->designations->designations );
		$this->observations->assign( $kept, $river, Observations::HAND );

		$command = new ResetCommand(
			$this->topics,
			$this->mentions,
			new TopicPruner( $this->topics, $this->mentions ),
			static function () {},
			static function () {},
			static function () {},
			static function () {},
			new Cleaner( $this->designations, $this->observations )
		);

		$command( array(), array() );

		$this->assertCount( 2, $this->designations->designations, 'A dry run deletes nothing.' );

		$command( array(), array( 'apply' => true ) );

		$this->assertSame( array( $kept ), array_keys( $this->designations->designations ), 'What the system proposed goes, what a person assigned stays.' );
		$this->assertSame( array(), $this->observations->of_post( 1 ) );

		$command(
			array(),
			array(
				'apply' => true,
				'everything' => true,
			)
		);

		$this->assertSame( array(), $this->designations->designations );
		$this->assertNull( $this->observations->assignment( $kept ) );
	}

	/**
	 * The service of the statements that a `Mentions` was built on (the tests share the one of the case).
	 *
	 * @param Mentions $mentions The mentions.
	 * @return \Otherguise\Triples\Statements
	 */
	private function statementsOf( Mentions $mentions ) {
		$property = new ReflectionProperty( Mentions::class, 'statements' );
		$property->setAccessible( true );

		return ( $property->getValue( $mentions ) )();
	}

	/**
	 * An ambiguous candidate no longer leaves a placeholder topic (slice 629): **the motif holds the doubt** (state `ambiguous`, no assignment, no
	 * mention), and the queue is made of motifs.
	 *
	 * @return void
	 */
	public function test_a_doubt_is_a_designation_not_a_topic(): void {
		$this->topics->create( 'Moulins', 'commune', 0, Topics::APPROVED, array( 'wikipedia:fr:Moulins' ) );
		$this->topics->create( 'Moulins (autre)', 'commune', 0, Topics::APPROVED, array( 'wikipedia:fr:Moulins' ) );

		$this->says[1] = array( $this->link( 'Moulins', 'Moulins' ) );
		$report        = $this->indexer->index_post( 1 );

		$id = (int) array_key_first( $this->designations->designations );

		$this->assertSame( 1, $report['ambiguous'] );
		$this->assertCount( 2, $this->topics->topics, 'No placeholder topic is made.' );
		$this->assertSame( Designations::AMBIGUOUS, $this->designations->get( $id )['state'] );
		$this->assertNull( $this->observations->assignment( $id ) );
		$this->assertSame( array(), $this->mentions->of_post( 1 ) );
		$this->assertSame( array( 1 ), $this->observations->posts_of( $id ), 'The observation is kept.' );
	}

	/**
	 * The cleaning phase deletes the motifs that the system proposed and that nobody observes any more, and keeps the ones that a person assigned.
	 *
	 * @return void
	 */
	public function test_the_cleaning_of_the_designations(): void {
		$river = $this->topics->create( 'Allier', 'waterway', 0, Topics::APPROVED, array() );

		$this->says[1] = array( $this->link( 'Allier', 'Allier_(rivière)' ), $this->link( 'Lyon', 'Lyon' ) );
		$this->indexer->index_post( 1 );

		$ids = array_keys( $this->designations->designations );
		$this->observations->assign( $ids[0], $river, Observations::HAND );

		$this->assertSame( 0, $this->mentions->clean_designations(), 'Both are observed.' );

		$this->mentions->drop_source( 'post' );

		$this->assertSame( 1, $this->mentions->clean_designations( true ), 'A count only.' );
		$this->assertCount( 2, $this->designations->designations );
		$this->assertSame( 1, $this->mentions->clean_designations() );
		$this->assertSame( array( $ids[0] ), array_keys( $this->designations->designations ), 'What a person assigned stays.' );
	}

	/**
	 * The chain of a candidate: a place under two namesakes of the same level.
	 *
	 * @param string $name The lowest place.
	 * @return Candidate
	 */
	private function place( $name ): Candidate {
		$spain  = new Candidate( 'Espagne', 'country', array() );
		$commune = new Candidate( 'Fisterra', 'commune', array(), $spain );

		return ( new Candidate( $name, 'commune', array(), $commune ) )->from( 'post/link', array( 'a' ) );
	}

	/**
	 * An ambiguous level **records the topics that it hesitated between**, and no placeholder topic is made; the evidence keeps every level, so the chain can be
	 * made again without the page; the evidence of before (the labels of the broader levels only) still makes a chain.
	 *
	 * @return void
	 */
	public function test_an_ambiguous_level_records_its_candidates(): void {
		$spain = $this->topics->create( 'Espagne', 'country', 0, Topics::APPROVED, array( 'label:0/espagne' ) );
		$a     = $this->topics->create( 'Fisterra', 'commune', $spain, Topics::APPROVED, array( 'label:' . $spain . '/fisterra' ) );
		$b     = $this->topics->create( 'Fisterra', 'commune', $spain, Topics::APPROVED, array( 'label:' . $spain . '/fisterra' ) );

		$this->says[1] = array( $this->place( 'A Atalaia' ) );
		$this->indexer->index_post( 1 );

		$id = (int) array_key_first( $this->designations->designations );
		$on = $this->designations->get( $id )['proposal']['ambiguity'];

		$this->assertSame( Designations::AMBIGUOUS, $this->designations->get( $id )['state'] );
		$this->assertSame( 'Fisterra', $on['level'], 'The level that hesitated, not the lowest.' );
		$this->assertSame( 'label:' . $spain . '/fisterra', $on['key'] );
		$this->assertSame( array( $a, $b ), $on['candidates'] );
		$this->assertCount( 3, $this->topics->topics, 'No placeholder topic.' );

		$again = Evidence::candidate( $this->designations->get( $id )['evidence'], 'post/link' );

		$this->assertSame( 'A Atalaia', $again->label );
		$this->assertSame( 'Fisterra', $again->parent->label );
		$this->assertSame( 'commune', $again->parent->kind );
		$this->assertSame( 'Espagne', $again->parent->parent->label );
		$this->assertNull( $again->parent->parent->parent );

		$old = Evidence::candidate(
			array(
				'label' => 'A Atalaia',
				'kind'  => 'site',
				'keys'  => array( 'k' ),
				'above' => array( 'Espagne', 'Fisterra' ),
			),
			'image/address'
		);

		$this->assertSame( array( 'A Atalaia', 'Fisterra', 'Espagne' ), array( $old->label, $old->parent->label, $old->parent->parent->label ) );
		$this->assertSame( 'any', $old->parent->kind, 'The kinds of the broader levels were not kept.' );
		$this->assertSame( 'image/address', $old->source );
	}

	/**
	 * A choice among the candidates: the topic is pinned to the level, the levels below are made under it, the motif is assigned **by hand**, the mentions
	 * follow, and **the next motif that hesitates in the same way gets the same topic**; a topic that is not a candidate is refused.
	 *
	 * @return void
	 */
	public function test_a_choice_makes_the_levels_below(): void {
		$spain = $this->topics->create( 'Espagne', 'country', 0, Topics::APPROVED, array( 'label:0/espagne' ) );
		$a     = $this->topics->create( 'Fisterra', 'commune', $spain, Topics::APPROVED, array( 'label:' . $spain . '/fisterra' ) );
		$b     = $this->topics->create( 'Fisterra', 'commune', $spain, Topics::APPROVED, array( 'label:' . $spain . '/fisterra' ) );
		$other = $this->topics->create( 'Ailleurs', 'commune', 0, Topics::APPROVED, array() );

		$this->says[1] = array( $this->place( 'A Atalaia' ) );
		$this->says[2] = array( $this->place( 'A Barxa' ) );
		$this->indexer->index_post( 1 );
		$this->indexer->index_post( 2 );

		$ids      = array_keys( $this->designations->designations );
		$resolver = new Reresolver( $this->designations, $this->observations, $this->mentions, new Resolver( $this->topics ), $this->topics );

		$refused = $resolver->choose( $ids[0], $other );

		$this->assertFalse( $refused['ok'] );
		$this->assertStringContainsString( 'not one of those', $refused['error'] );
		$this->assertFalse( $resolver->choose( 999999, $a )['ok'] );

		$done = $resolver->choose( $ids[0], $b );
		$have = $this->observations->assignment( $ids[0] );

		$this->assertTrue( $done['ok'] );
		$this->assertSame(
			array(
				'topic' => $done['topic'],
				'by' => Observations::HAND,
			),
			$have
		);
		$this->assertSame( $b, $this->topics->get( $done['topic'] )['parent'] );
		$this->assertSame( 'A Atalaia', $this->topics->get( $done['topic'] )['label'] );
		$this->assertSame( array( $done['topic'] ), array_keys( $this->mentions->of_post( 1 ) ) );
		$this->assertSame( $b, $this->topics->pinned( 'label:' . $spain . '/fisterra' ) );

		$this->indexer->index_post( 2 );

		$second = $this->observations->assignment( $ids[1] );

		$this->assertNotNull( $second, 'The other motif is no longer ambiguous: the choice was kept for the level.' );
		$this->assertSame( $b, $this->topics->get( $second['topic'] )['parent'] );
		$this->assertSame( Designations::ASSIGNED, $this->designations->get( $ids[1] )['state'] );
	}

	/**
	 * Resolving the ambiguous motifs again: a motif read before the candidates were kept gets them without its page; a dry run changes nothing; a
	 * motif that the topics made since have cleared up is settled; the command says it.
	 *
	 * @return void
	 */
	public function test_resolving_again(): void {
		$spain = $this->topics->create( 'Espagne', 'country', 0, Topics::APPROVED, array( 'label:0/espagne' ) );
		$a     = $this->topics->create( 'Fisterra', 'commune', $spain, Topics::APPROVED, array( 'label:' . $spain . '/fisterra' ) );
		$b     = $this->topics->create( 'Fisterra', 'commune', $spain, Topics::APPROVED, array( 'label:' . $spain . '/fisterra' ) );
		$id    = $this->designations->create(
			'post/link|chain:espagne > fisterra > a atalaia',
			'post/link',
			array(
				'label' => 'A Atalaia',
				'kind'  => 'commune',
				'keys'  => array(),
				'above' => array( 'Espagne', 'Fisterra' ),
			)
		);

		$this->designations->set_state(
			$id,
			Designations::AMBIGUOUS,
			array(
				'topic' => 0,
				'why' => 'label',
			)
		);
		$this->observations->sync( 1, 'post/link', array( $id => array( 'strength' => 'main' ) ) );

		$resolver = new Reresolver( $this->designations, $this->observations, $this->mentions, new Resolver( $this->topics ), $this->topics );

		$this->assertSame(
			array(
				'looked' => 1,
				'settled' => 0,
				'still' => 1,
				'recorded' => 0,
			),
			$resolver->run( true )
		);
		$this->assertArrayNotHasKey( 'ambiguity', $this->designations->get( $id )['proposal'], 'A dry run changes nothing.' );

		$this->assertSame(
			array(
				'looked' => 1,
				'settled' => 0,
				'still' => 1,
				'recorded' => 1,
			),
			$resolver->run( false )
		);
		$this->assertSame( array( $a, $b ), $this->designations->get( $id )['proposal']['ambiguity']['candidates'] );

		$this->topics->pin( $a, 'label:' . $spain . '/fisterra' );

		$this->assertSame(
			array(
				'looked' => 1,
				'settled' => 1,
				'still' => 0,
				'recorded' => 0,
			),
			$resolver->run( false )
		);
		$this->assertSame( Designations::ASSIGNED, $this->designations->get( $id )['state'] );
		$this->assertSame( Observations::SYSTEM, $this->observations->assignment( $id )['by'] );
		$this->assertCount( 1, $this->mentions->of_post( 1 ), 'The mention appears without the page being read.' );
	}

	/**
	 * WordPress refuses a topic whose name is a sibling's: the topic that is there is taken instead of leaving the motif waiting for ever; of another kind,
	 * it is a doubt that a person settles.
	 *
	 * @return void
	 */
	public function test_a_topic_that_wordpress_refuses_is_the_one_that_is_there(): void {
		$this->topics->refuse_duplicates = true;

		$moulins = $this->topics->create( 'Moulins', 'commune', 0, Topics::APPROVED, array( 'wikipedia:fr:Autre_page' ) );

		$this->says[1] = array( $this->link( 'moulins', 'Moulins', 'commune' ) );
		$report        = $this->indexer->index_post( 1 );

		$id = (int) array_key_first( $this->designations->designations );

		$this->assertSame( 1, $report['resolved'] );
		$this->assertSame(
			array(
				'topic' => $moulins,
				'by' => Observations::SYSTEM,
			),
			$this->observations->assignment( $id )
		);
		$this->assertContains( 'wikipedia:fr:Moulins', $this->topics->get( $moulins )['keys'] );
		$this->assertCount( 1, $this->topics->topics );

		$this->topics->create( 'Lyon', 'waterway', 0, Topics::APPROVED, array( 'wikipedia:fr:Rhone' ) );

		$this->says[2] = array( $this->link( 'Lyon', 'Lyon', 'commune' ) );
		$this->indexer->index_post( 2 );

		$lyon = (int) array_key_last( $this->designations->designations );

		$this->assertSame( Designations::AMBIGUOUS, $this->designations->get( $lyon )['state'], 'Another kind: a person decides.' );
		$this->assertCount( 1, $this->designations->get( $lyon )['proposal']['ambiguity']['candidates'] );
	}

	/**
	 * Sorts a list of numbers.
	 *
	 * @param int[] $numbers Numbers.
	 * @return int[]
	 */
	private function sorted( array $numbers ): array {
		sort( $numbers );

		return $numbers;
	}

	/**
	 * A tag with no kind (slice 635): read as a motif of the kind `any`, its topic is a candidate; a person **excludes** the motif: no assignment, no mention, the
	 * observations stay; a reading and the cleaner leave it alone; including it puts it back and the next reading proposes a topic.
	 *
	 * @return void
	 */
	public function test_a_tag_with_no_kind_and_the_exclusion_of_a_motif(): void {
		$tag = function () {
			$candidate = ( new Candidate( 'allemagne', 'any', array( 'term:post_tag:9' ), null, Candidate::PASSING ) )->from( 'post/tag' );

			$candidate->sure = false;

			return $candidate;
		};

		$this->says[1] = array( $tag() );
		$this->says[2] = array( $tag() );

		$this->indexer->index_post( 1 );
		$this->indexer->index_post( 2 );

		$id    = (int) array_key_first( $this->designations->designations );
		$topic = (int) $this->observations->assignment( $id )['topic'];

		$this->assertSame( Topics::CANDIDATE, $this->topics->get( $topic )['status'], 'Nobody classified it: a candidate, outside the index.' );
		$this->assertArrayHasKey( $topic, $this->mentions->of_post( 1 ) );

		$exclusion = new Exclusion( $this->designations, $this->observations, $this->mentions );
		$done      = $exclusion->exclude( array( $id, 9999 ) );

		$this->assertSame( 1, $done['designations'], 'A motif that does not exist is skipped.' );
		$this->assertSame( 2, $done['posts'] );
		$this->assertNull( $this->observations->assignment( $id ) );
		$this->assertSame( Designations::EXCLUDED, $this->designations->get( $id )['state'] );
		$this->assertSame( array(), $this->mentions->of_post( 1 ), 'The mentions of its posts are gone.' );
		$this->assertSame( array( 1, 2 ), $this->sorted( $this->observations->posts_of( $id ) ), 'The observations stay.' );

		$this->indexer->index_post( 1 );

		$this->assertNull( $this->observations->assignment( $id ), 'A reading leaves it alone.' );
		$this->assertSame( Designations::EXCLUDED, $this->designations->get( $id )['state'] );
		$this->assertSame( array(), $this->mentions->of_post( 1 ) );

		$this->says = array();
		$this->indexer->index_post( 1 );
		$this->indexer->index_post( 2 );

		$this->assertNotContains( $id, ( new Cleaner( $this->designations, $this->observations ) )->candidates(), 'A decision of a person is not cleaned away, observed or not.' );

		$this->says[1] = array( $tag() );

		$this->assertSame( 1, $exclusion->include( array( $id ) ) );
		$this->assertSame( 0, $exclusion->include( array( $id ) ), 'Only an excluded motif is included.' );
		$this->assertSame( Designations::PENDING, $this->designations->get( $id )['state'] );

		$this->indexer->index_post( 1 );

		$this->assertSame( $topic, (int) $this->observations->assignment( $id )['topic'], 'The next reading proposes a topic again.' );
	}
}
