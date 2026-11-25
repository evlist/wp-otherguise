<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the enrichment before the creation of a topic (slice 627), on a real database for the statements.
 *
 * @package Otherguise
 */

use Otherguise\Index\Candidate;
use Otherguise\Index\Designation\Keys;
use Otherguise\Index\Designations;
use Otherguise\Index\Enrich\DesignationEnricher;
use Otherguise\Index\Enrich\DesignationRetry;
use Otherguise\Index\Enrich\Failures;
use Otherguise\Index\Enrich\Lookup;
use Otherguise\Index\Indexer;
use Otherguise\Index\Mentions;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Observations;
use Otherguise\Index\Resolver;
use Otherguise\Index\Source;
use Otherguise\Index\Topics;
use Otherguise\Index\Wikidata\Client;
use Otherguise\Index\Wikidata\ClassTable;
use Otherguise\Index\Wikidata\ItemReader;
use Otherguise\Index\Wikidata\Options;
use Otherguise\Index\Wikidata\TitleResolver;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-designations.php';

/**
 * The statements are real; the topics, the motifs and the kinds are in memory; the services of Wikimedia are doubles.
 *
 * @covers \Otherguise\Index\Enrich\DesignationEnricher
 * @covers \Otherguise\Index\Enrich\DesignationRetry
 * @covers \Otherguise\Index\Enrich\Failures
 * @covers \Otherguise\Index\Enrich\Lookup
 * @covers \Otherguise\Index\Indexer
 */
class IndexEnrichBeforeCreationDbTest extends Otherguise_Test_Database_Case {

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
	 * The indexer.
	 *
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * The queue of the motifs that waited.
	 *
	 * @var DesignationRetry
	 */
	private $retry;

	/**
	 * The options.
	 *
	 * @var array<string, mixed>
	 */
	private $stored = array();

	/**
	 * The requests made.
	 *
	 * @var array<int, string>
	 */
	private $requests = array();

	/**
	 * What the services answer.
	 *
	 * @var array<string, array>
	 */
	private $answers = array();

	/**
	 * The time.
	 *
	 * @var float
	 */
	private $time = 1700000000.0;

	/**
	 * What the source says, by post.
	 *
	 * @var array<int, Candidate[]>
	 */
	private $says = array();

	/**
	 * Wires the statements, the doubles of the services and the indexer with the enrichment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Otherguise_Test_Memory_Kinds::install();
		otherguise_test_wp_objects( array( new WP_Post( 1 ), new WP_Post( 2 ), new WP_Post( 3 ) ) );

		$this->stored   = array( 'index_wikidata_contact' => 'me@example.test' );
		$this->requests = array();
		$this->time     = 1700000000.0;
		$this->says     = array();
		$this->answers  = array(
			'wikipedia' => $this->ok( '{"query":{"pages":[{"title":"Têt (fleuve)","pageprops":{"wikibase_item":"Q4040"}},{"title":"Page inexistante","missing":true}]}}' ),
			'items'     => $this->ok(
				'{"results":{"bindings":[{"item":{"value":"http://www.wikidata.org/entity/Q4040"},"itemLabel":{"value":"Têt"},"class":{"value":"http://www.wikidata.org/entity/Q4022"},"classLabel":{"value":"cours d\'eau"}}]}}'
			),
			'ancestors' => $this->ok( '{"results":{"bindings":[{"class":{"value":"http://www.wikidata.org/entity/Q4022"},"ancestor":{"value":"http://www.wikidata.org/entity/Q4022"}}]}}' ),
		);

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

		$options = new Options(
			fn( $name, $default_value = false ) => $this->stored[ $name ] ?? $default_value,
			function ( $name, $value ) {
				$this->stored[ $name ] = $value;
			}
		);
		$client  = new Client(
			$options,
			function ( $url ) {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

				if ( false !== strpos( $url, 'wikipedia.org' ) ) {
					$this->requests[] = 'wikipedia';

					return $this->answers['wikipedia'];
				}

				$kind             = false !== strpos( (string) ( $query['query'] ?? '' ), 'P279' ) ? 'ancestors' : 'items';
				$this->requests[] = $kind;

				return $this->answers[ $kind ];
			},
			null,
			'1.0',
			fn() => $this->time,
			function ( $seconds ) {
				$this->time += $seconds;
			}
		);

		$enrichment = new DesignationEnricher(
			new Lookup(
				new TitleResolver( $client ),
				new ItemReader( $client, static fn() => 'https://query.test/sparql' ),
				new ClassTable( $options, fn() => (int) $this->time, static fn( $min ) => $min ),
				'fr'
			),
			new Failures( $options, fn() => (int) $this->time ),
			$this->designations,
			fn() => (int) $this->time,
			static fn( $min ) => $min
		);
		$resolver   = new Resolver( $this->topics );
		$says       = &$this->says;
		$source     = new class( $says ) implements Source {
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
			 * The candidates.
			 *
			 * @param int $post_id Post.
			 * @return Candidate[]
			 */
			public function candidates( $post_id ) {
				return $this->says[ $post_id ] ?? array();
			}
		};

		$this->indexer = new Indexer( $resolver, $this->mentions, array( $source ), static fn() => true, $this->designations, $this->observations, $enrichment );
		$this->retry   = new DesignationRetry( $this->designations, $this->observations, $this->mentions, $resolver, $enrichment );
	}

	/**
	 * A response of a service with the status 200.
	 *
	 * @param string $body Body.
	 * @return array<string, mixed>
	 */
	private function ok( $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => $body,
		);
	}

	/**
	 * A link to a page of Wikipedia.
	 *
	 * @param string $title The page.
	 * @return Candidate
	 */
	private function link( $title ): Candidate {
		return ( new Candidate( $title, 'any', array( 'wikipedia:fr:' . $title ), null, Candidate::PASSING ) )->from( 'post/link', array( 'a' ) );
	}

	/**
	 * The topic gets its identifier, its kind and its label **when it is made**; a link seen in a second post is not asked again.
	 *
	 * @return void
	 */
	public function test_the_topic_is_made_complete(): void {
		$this->says[1] = array( $this->link( 'Têt (fleuve)' ) );
		$this->says[2] = array( $this->link( 'Têt (fleuve)' ) );

		$this->indexer->index_post( 1 );

		$this->assertSame( array( 'wikipedia', 'items', 'ancestors' ), $this->requests );

		$topic = $this->topics->get( (int) array_key_first( $this->mentions->of_post( 1 ) ) );

		$this->assertContains( 'wikidata:Q4040', $topic['keys'] );
		$this->assertSame( 'waterway', $topic['kind'], 'The kind is the one of the classes.' );
		$this->assertSame( 'Têt (fleuve)', $topic['label'], 'The qualifier tells it from its namesakes: it is kept.' );

		$id = (int) array_key_first( $this->designations->designations );

		$this->assertSame( 'done', $this->designations->enrichment( $id )['state'] );
		$this->assertSame( 'Q4040', $this->designations->enrichment( $id )['qid'] );

		$this->indexer->index_post( 2 );

		$this->assertCount( 3, $this->requests, 'The motif was looked at once, whatever the posts.' );
		$this->assertCount( 1, $this->topics->topics );
		$this->assertSame( array_keys( $this->mentions->of_post( 1 ) ), array_keys( $this->mentions->of_post( 2 ) ) );
	}

	/**
	 * A topic that holds the identifier is found by it: a link in another language, or another spelling, goes to the same topic.
	 *
	 * @return void
	 */
	public function test_the_identifier_finds_the_topic(): void {
		$existing = $this->topics->create( 'Têt', 'waterway', 0, Topics::APPROVED, array( 'wikidata:Q4040' ) );

		$this->says[1] = array( $this->link( 'Têt (fleuve)' ) );
		$this->indexer->index_post( 1 );

		$this->assertSame( array( $existing ), array_keys( $this->mentions->of_post( 1 ) ) );
		$this->assertCount( 1, $this->topics->topics, 'No topic was made.' );
	}

	/**
	 * A page that does not exist is a final answer: the topic is made as the link says it, without an identifier, and the question comes back only after a
	 * delay.
	 *
	 * @return void
	 */
	public function test_a_missing_page(): void {
		$this->says[1] = array( $this->link( 'Page inexistante' ) );
		$this->indexer->index_post( 1 );

		$id = (int) array_key_first( $this->designations->designations );

		$this->assertSame( 'missing', $this->designations->enrichment( $id )['state'] );
		$this->assertCount( 1, $this->mentions->of_post( 1 ) );
		$this->assertSame( array( 'wikipedia' ), $this->requests, 'No item, so nothing to read.' );

		$this->indexer->index_post( 1 );

		$this->assertCount( 1, $this->requests, 'Not asked again before its delay.' );
	}

	/**
	 * While the service asks to wait, **no topic is made**: the observation is kept, the motif is `pending`, the index is incomplete and not degraded;
	 * the queue then resolves it with what Wikidata says, and the mentions appear without reading the page again.
	 *
	 * @return void
	 */
	public function test_a_service_that_asks_to_wait(): void {
		$this->answers['wikipedia'] = array(
			'response' => array( 'code' => 429 ),
			'headers'  => array( 'retry-after' => '600' ),
			'body'     => '',
		);

		$this->says[1] = array( $this->link( 'Têt (fleuve)' ) );
		$report        = $this->indexer->index_post( 1 );

		$id = (int) array_key_first( $this->designations->designations );

		$this->assertSame( 1, $report['pending'] );
		$this->assertCount( 0, $this->topics->topics, 'No topic is made unenriched.' );
		$this->assertSame( array(), $this->mentions->of_post( 1 ) );
		$this->assertSame( Designations::PENDING, $this->designations->get( $id )['state'] );
		$this->assertSame( array( 1 ), $this->observations->posts_of( $id ), 'The observation is kept.' );
		$this->assertNull( $this->observations->assignment( $id ) );

		$count = count( $this->requests );

		$this->indexer->index_post( 1 );
		$this->assertCount( $count, $this->requests, 'Nothing is asked during the pause.' );
		$this->assertSame( array( $id ), array_keys( $this->retry->pending() ) );

		$this->time                += 601;
		$this->answers['wikipedia'] = $this->ok( '{"query":{"pages":[{"title":"Têt (fleuve)","pageprops":{"wikibase_item":"Q4040"}}]}}' );

		$done = $this->retry->run_batch();

		$this->assertTrue( $done['ok'] );
		$this->assertSame( array( $id => Designations::ASSIGNED ), $done['report'] );
		$this->assertCount( 1, $this->topics->topics );
		$this->assertContains( 'wikidata:Q4040', reset( $this->topics->topics )['keys'] );
		$this->assertCount( 1, $this->mentions->of_post( 1 ), 'The mention appears with no reading of the page.' );
		$this->assertSame( array(), $this->retry->pending() );
	}

	/**
	 * What a person assigned is not enriched again, and the service is not asked.
	 *
	 * @return void
	 */
	public function test_a_decision_is_not_asked_about(): void {
		$river = $this->topics->create( 'Rivière', 'waterway', 0, Topics::APPROVED, array() );
		$key   = Keys::of( $this->link( 'Têt (fleuve)' ), 'post/link' );
		$id    = $this->designations->create( $key, 'post/link', array() );

		$this->observations->assign( $id, $river, Observations::HAND );

		$this->says[1] = array( $this->link( 'Têt (fleuve)' ) );
		$this->indexer->index_post( 1 );

		$this->assertSame( array( $river ), array_keys( $this->mentions->of_post( 1 ) ) );
		$this->assertSame( array(), $this->requests, 'Nothing is asked about a motif that a person assigned.' );
	}
}
