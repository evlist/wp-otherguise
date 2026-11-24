<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the designations, the observations and the assignments (slice 626), on a real database for the statements.
 *
 * @package Otherguise
 */

use Otherguise\Index\Candidate;
use Otherguise\Index\Cli\DesignationsCommand;
use Otherguise\Index\Cli\ResetCommand;
use Otherguise\Index\Designation\Assigner;
use Otherguise\Index\Designation\Migration;
use Otherguise\Index\Merge\TopicPruner;
use Otherguise\Index\Designation\Cleaner;
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
 * The statements are real; the topics, the designations and the kinds are in memory.
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
	 * The designations.
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
	 * The key of a designation: the strongest identity, the source in front; a chain of labels when there is none.
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
	 * A link seen in two posts is **one designation** with two observations; reading again changes nothing; the mentions are the same as without the model.
	 *
	 * @return void
	 */
	public function test_one_designation_many_observations(): void {
		$this->says[1] = array( $this->link( 'Allier', 'Allier_(rivière)', 'any', 'a' ) );
		$this->says[2] = array( $this->link( 'Allier', 'Allier_(rivière)', 'any', 'b' ), $this->link( 'Allier', 'Allier_(rivière)', 'any', 'c' ) );

		$this->indexer->index_post( 1 );
		$this->indexer->index_post( 2 );
		$this->indexer->index_post( 2 );

		$this->assertCount( 1, $this->designations->designations, 'One designation, whatever the posts.' );

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
	 * What a person assigned is never changed by a reading: the designation goes where it was put, the resolver is not asked, and the mention follows.
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
	 * designations; the cleaner deletes the designations that the system proposed and that nobody observes, not the ones that a person assigned.
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
		$this->assertCount( 2, $this->designations->designations, 'The designations stay.' );
		$this->assertArrayHasKey( $river, $this->mentions->mentioned_topics(), 'A decision holds its topic.' );

		$cleaner = new Cleaner( $this->designations, $this->observations );

		$this->assertSame( array( $ids[1] ), $cleaner->candidates(), 'The one that a person assigned stays.' );
		$this->assertSame( 2, count( $cleaner->candidates( true ) ) );
		$this->assertSame( 1, $cleaner->delete( $cleaner->candidates() ) );
		$this->assertNull( $this->observations->assignment( $ids[1] ) );
		$this->assertNotNull( $this->designations->get( $ids[0] ) );
	}

	/**
	 * Merging two topics assigns the designations of the first to the second, by a person.
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
	 * A mention made by a person has its designation (the source `hand`), assigned by hand, and its observation.
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
	 * The assignment by a person: a dry run changes nothing; the real one moves the designation, **the mentions of the posts follow without reading
	 * the pages**, the designation and its observations stay, and a kind conflict is refused unless forced.
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
	 * The mentions made by hand before the model are converted: a designation assigned by hand and an observation; run twice, it finds them again.
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
			'A dry run counts (one designation is counted for each post).'
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
			}
		);

		$command->list( array(), array() );

		$this->assertCount( 2, $lines );
		$this->assertStringContainsString( 'post/link|wikipedia:fr:Allier_(rivière)', $lines[0] );
		$this->assertStringContainsString( ', system)', $lines[0] );
		$this->assertSame( '2 designation(s).', $done[0] );

		$first = (int) array_key_first( $this->designations->designations );
		$lines = array();

		$command->assign(
			array(),
			array(
				'topic' => $river,
				'designation' => (string) $first,
			)
		);

		$this->assertStringContainsString( 'nothing was changed', end( $done ) );
		$this->assertNotSame( $river, $this->observations->assignment( $first )['topic'] );

		$command->assign(
			array(),
			array(
				'topic' => $river,
				'designation' => (string) $first,
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
	 * The reset takes the designations that the system proposed and keeps the ones that a person assigned; with `--everything` nothing stays.
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
	 * Sorts a list of numbers.
	 *
	 * @param int[] $numbers Numbers.
	 * @return int[]
	 */
	private function sorted( array $numbers ): array {
		sort( $numbers );

		return $numbers;
	}
}
