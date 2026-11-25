<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the Wikidata enrichment of the topics (slice 604), without the network.
 *
 * The answers of the services are **handwritten from their documented shape**: they prove the code, not the services.
 *
 * @package Otherguise
 */

use Otherguise\Index\Enrich\Enricher;
use Otherguise\Index\Topics;
use Otherguise\Index\Job\PagesJob;
use Otherguise\Index\Wikidata\ClassTable;
use Otherguise\Index\Wikidata\Client;
use Otherguise\Index\Wikidata\ItemReader;
use Otherguise\Index\Wikidata\Options;
use Otherguise\Index\Wikidata\TitleResolver;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * Topics and kinds in memory, the services answered by a double.
 *
 * @covers \Otherguise\Index\Enrich\Enricher
 * @covers \Otherguise\Index\Job\PagesJob
 */
class IndexEnrichTest extends TestCase {

	/**
	 * The topics.
	 *
	 * @var Otherguise_Test_Memory_Topics
	 */
	private $topics;

	/**
	 * The options.
	 *
	 * @var array<string, mixed>
	 */
	private $stored = array();

	/**
	 * The requests made: `array( service, parameters )`.
	 *
	 * @var array<int, array{0:string,1:array}>
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
	 * The topics that a published post mentions.
	 *
	 * @var int[]
	 */
	private $mentioned = array();

	/**
	 * The ids of the topics by name.
	 *
	 * @var array<string, int>
	 */
	private $id = array();

	/**
	 * The enricher.
	 *
	 * @var Enricher
	 */
	private $enricher;

	/**
	 * Wires the enricher on doubles, with five topics that come from links to Wikipedia.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		Otherguise_Test_Memory_Kinds::install();

		$this->stored    = array( 'index_wikidata_contact' => 'me@example.test' );
		$this->requests  = array();
		$this->time      = 1700000000.0;
		$this->topics   = new Otherguise_Test_Memory_Topics();
		$this->mentioned = array();

		$this->answers = array(
			'wikipedia' => $this->ok(
				'{"query":{"pages":['
				. '{"title":"Têt (fleuve)","pageprops":{"wikibase_item":"Q4040"}},'
				. '{"title":"Col de la Perche (Pyrénées-Orientales)","pageprops":{"wikibase_item":"Q1000"}},'
				. '{"title":"Paris (homonymie)","pageprops":{"disambiguation":"","wikibase_item":"Q9"}},'
				. '{"title":"Page inexistante","missing":true}]}}'
			),
			'items'     => $this->ok(
				'{"results":{"bindings":['
				. '{"item":{"value":"http://www.wikidata.org/entity/Q4040"},"itemLabel":{"value":"Têt"},"class":{"value":"http://www.wikidata.org/entity/Q4022"},"classLabel":{"value":"cours d\'eau"}},'
				. '{"item":{"value":"http://www.wikidata.org/entity/Q1000"},"itemLabel":{"value":"Col de la Perche"},"class":{"value":"http://www.wikidata.org/entity/Q133056"},"classLabel":{"value":"col de montagne"}}]}}'
			),
			'ancestors' => $this->ok(
				'{"results":{"bindings":['
				. '{"class":{"value":"http://www.wikidata.org/entity/Q4022"},"ancestor":{"value":"http://www.wikidata.org/entity/Q4022"}},'
				. '{"class":{"value":"http://www.wikidata.org/entity/Q133056"},"ancestor":{"value":"http://www.wikidata.org/entity/Q133056"}}]}}'
			),
		);

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
					$this->requests[] = array( 'wikipedia', $query );

					return $this->answers['wikipedia'];
				}

				$kind             = false !== strpos( (string) ( $query['query'] ?? '' ), 'P279' ) ? 'ancestors' : 'items';
				$this->requests[] = array( $kind, $query );

				return $this->answers[ $kind ];
			},
			null,
			'1.0',
			fn() => $this->time,
			function ( $seconds ) {
				$this->time += $seconds;
			}
		);

		$this->enricher = new Enricher(
			$this->topics,
			new TitleResolver( $client ),
			new ItemReader( $client, static fn() => 'https://query.test/sparql' ),
			new ClassTable( $options, fn() => (int) $this->time, static fn( $min ) => $min ),
			$options,
			fn( $id ) => in_array( $id, $this->mentioned, true ),
			'fr',
			fn() => (int) $this->time,
			static fn( $min ) => $min
		);

		foreach ( array(
			'tet'      => 'Têt (fleuve)',
			'col'      => 'Col de la Perche (Pyrénées-Orientales)',
			'paris'    => 'Paris (homonymie)',
			'missing'  => 'Page inexistante',
			'unlisted' => 'Sans article',
		) as $name => $title ) {
			$this->id[ $name ] = $this->topics->create( $title, 'other', 0, Topics::APPROVED, array( 'wikipedia:fr:' . $title, Otherguise\Index\Resolver::label_key( 0, $title ) ) );
		}

		$this->mentioned = array( $this->id['tet'], $this->id['col'], $this->id['paris'], $this->id['missing'] );
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
	 * A response that is not a success.
	 *
	 * @param int   $status  Status.
	 * @param array $headers Headers.
	 * @return array<string, mixed>
	 */
	private function refusal( $status, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => '',
		);
	}

	/**
	 * The queue is the approved topics with a Wikipedia key and no Wikidata key that a published post mentions.
	 *
	 * @return void
	 */
	public function test_the_queue(): void {
		$this->assertSame( array( $this->id['tet'], $this->id['col'], $this->id['paris'], $this->id['missing'] ), $this->enricher->pending() );
		$this->assertCount( 2, $this->enricher->pending( 2 ) );

		$this->topics->create( 'Candidate', 'other', 0, Topics::AMBIGUOUS, array( 'wikipedia:fr:Candidate' ) );
		$this->topics->create( 'Without any key', 'other', 0, Topics::APPROVED, array( 'term:category:5' ) );
		$this->topics->create( 'Done', 'other', 0, Topics::APPROVED, array( 'wikipedia:fr:Done', 'wikidata:Q1' ) );

		$this->mentioned = array_keys( $this->topics->topics );

		$this->assertCount( 5, $this->enricher->pending(), 'Only the five topics that wait.' );
	}

	/**
	 * A batch: the item is the key, the label is the one of Wikidata (the old label key stays), the kind comes from the classes and their
	 * ancestors; a disambiguation page and a missing page are told apart; a topic that no post mentions is left alone; three requests.
	 *
	 * @return void
	 */
	public function test_a_batch(): void {
		$done = $this->enricher->run_batch();

		$this->assertTrue( $done['ok'] );
		$this->assertFalse( $done['stop'] );
		$this->assertSame( 4, $done['processed'] );
		$this->assertSame( array( 'wikipedia', 'items', 'ancestors' ), array_column( $this->requests, 0 ) );

		$tet = $this->topics->get( $this->id['tet'] );

		$this->assertSame( 'Têt (fleuve)', $tet['label'], 'The qualifier in parentheses tells the topic from its namesakes: Wikidata does not strip it.' );
		$this->assertSame( 'waterway', $tet['kind'] );
		$this->assertContains( 'wikidata:Q4040', $tet['keys'] );
		$this->assertContains( 'wikipedia:fr:Têt (fleuve)', $tet['keys'] );
		$this->assertContains( 'label:0/tet (fleuve)', $tet['keys'] );
		$this->assertSame( 'done', $this->topics->enrich_state( $this->id['tet'] )['state'] );

		$col = $this->topics->get( $this->id['col'] );

		$this->assertSame( 'Col de la Perche (Pyrénées-Orientales)', $col['label'] );
		$this->assertSame( 'place', $col['kind'] );

		$this->assertSame( 'ambiguous', $this->topics->enrich_state( $this->id['paris'] )['state'] );
		$this->assertSame( 'missing', $this->topics->enrich_state( $this->id['missing'] )['state'] );
		$this->assertSame( 'Paris (homonymie)', $this->topics->get( $this->id['paris'] )['label'], 'An ambiguous topic is not changed.' );
		$this->assertSame( array(), $this->topics->enrich_state( $this->id['unlisted'] ) );
		$this->assertSame( array( 'Paris (homonymie)' ), array_column( $this->enricher->with_state( 'ambiguous' ), 'label' ) );
	}

	/**
	 * The topics that were not resolved are not asked again for about a week (a jitter of 20 %), and a second batch asks only what it needs: the
	 * ancestors of the classes are in the cache.
	 *
	 * @return void
	 */
	public function test_nothing_is_asked_twice(): void {
		$this->enricher->run_batch();

		$count = count( $this->requests );

		$again = $this->enricher->run_batch();

		$this->assertSame( 'Nothing to enrich.', $again['message'] );
		$this->assertCount( $count, $this->requests );

		$due = $this->topics->enrich_state( $this->id['paris'] )['due'];

		$this->assertGreaterThanOrEqual( (int) $this->time + (int) ( 7 * 86400 * 0.8 ), $due );
		$this->assertLessThanOrEqual( (int) $this->time + (int) ( 7 * 86400 * 1.2 ), $due );

		$this->time += 9 * 86400;

		$this->assertSame( array( $this->id['paris'], $this->id['missing'] ), $this->enricher->pending(), 'After the delay they are asked again.' );
	}

	/**
	 * A kind set by a person, a kind that is not `other` and a label set by a person are never overwritten; the key is added all the same.
	 *
	 * @return void
	 */
	public function test_what_a_person_decided_is_kept(): void {
		$this->topics->topics[ $this->id['tet'] ]['kind']          = 'route';
		$this->topics->topics[ $this->id['tet'] ]['kind_by_hand']  = true;
		$this->topics->topics[ $this->id['col'] ]['kind']          = 'person';
		$this->topics->topics[ $this->id['col'] ]['label_by_hand'] = true;

		$this->enricher->run_batch();

		$tet = $this->topics->get( $this->id['tet'] );
		$col = $this->topics->get( $this->id['col'] );

		$this->assertSame( 'route', $tet['kind'] );
		$this->assertSame( 'Têt (fleuve)', $tet['label'] );
		$this->assertSame( 'person', $col['kind'] );
		$this->assertSame( 'Col de la Perche (Pyrénées-Orientales)', $col['label'] );
		$this->assertContains( 'wikidata:Q1000', $col['keys'] );
	}

	/**
	 * The label of Wikidata replaces a label unless it only changes the case or the topic has a qualifier in parentheses that Wikidata does not carry.
	 *
	 * @return void
	 */
	public function test_when_a_label_is_renamed(): void {
		$renames = new ReflectionMethod( Otherguise\Index\Enrich\Enricher::class, 'renames' );

		$this->assertTrue( $renames->invoke( null, 'Tet', 'Têt' ) );
		$this->assertFalse( $renames->invoke( null, 'Têt', 'TÊT' ), 'A change of case only is not a rename.' );
		$this->assertFalse( $renames->invoke( null, 'Moulins (Allier)', 'Moulins' ) );
		$this->assertTrue( $renames->invoke( null, 'Moulins (Allier)', 'Moulins (Yzeure)' ), 'A qualifier on both sides is Wikidata\'s to give.' );
		$this->assertFalse( $renames->invoke( null, 'Têt', 'Têt' ) );
	}

	/**
	 * An item that another topic already holds is not merged: the topic is a duplicate, unchanged, listed, with the other topic in its note.
	 *
	 * @return void
	 */
	public function test_a_duplicate_is_not_merged(): void {
		$other = $this->topics->create( 'Têt', 'waterway', 0, Topics::APPROVED, array( 'wikidata:Q4040' ) );

		$this->enricher->run_batch();

		$tet = $this->topics->get( $this->id['tet'] );

		$this->assertSame( 'Têt (fleuve)', $tet['label'] );
		$this->assertNotContains( 'wikidata:Q4040', $tet['keys'] );
		$this->assertSame( 'duplicate', $this->topics->enrich_state( $this->id['tet'] )['state'] );
		$this->assertSame( 'The topic ' . $other . ' has Q4040 too.', $this->enricher->with_state( 'duplicate' )[ $this->id['tet'] ]['note'] );
	}

	/**
	 * A dry run makes the requests and reports the item, the label, the classes and the kind, and writes nothing on the topics.
	 *
	 * @return void
	 */
	public function test_a_dry_run_writes_nothing(): void {
		$done = $this->enricher->run_batch( true );

		$report = $done['report'][ $this->id['tet'] ];

		$this->assertSame( 'Q4040', $report['qid'] );
		$this->assertSame( '', $report['new'], 'A label with a qualifier is not renamed.' );
		$this->assertSame( 'waterway', $report['kind'] );
		$this->assertSame( array( 'Q4022' => 'cours d\'eau' ), $report['classes'] );
		$this->assertSame( 'Têt (fleuve)', $this->topics->get( $this->id['tet'] )['label'] );
		$this->assertSame( array(), $this->topics->enrich_state( $this->id['tet'] ) );
		$this->assertCount( 3, $this->requests );
	}

	/**
	 * One topic, asked for by its id, whatever its state.
	 *
	 * @return void
	 */
	public function test_one_topic(): void {
		$this->enricher->run_batch();
		$this->mentioned = array();

		$again = $this->enricher->run_batch( true, $this->id['paris'] );

		$this->assertSame( 1, $again['processed'], 'A topic asked for by its id is looked at again even if its delay has not passed and nobody mentions it.' );
		$this->assertSame( 'Nothing to enrich.', $this->enricher->run_batch( true )['message'] );

		$done = $this->enricher->run_batch( true, $this->id['tet'] );

		$this->assertSame( 1, $done['processed'], 'A topic asked for by its id is read again by its identifier, although it has its item and its label (slice 633).' );
	}

	/**
	 * The kinds (slice 633): a topic that has its identifier of Wikidata and no kind is read again when the table of the classes has a line that it lacked; only the
	 * kind changes (not the label, not the state), a kind that a person chose stays, and a dry run writes nothing.
	 *
	 * @return void
	 */
	public function test_the_kinds_are_deduced_again(): void {
		$route = $this->topics->create( 'Têt (le fleuve)', 'any', 0, Topics::APPROVED, array( 'wikidata:Q4040' ) );
		$hand  = $this->topics->create( 'Têt à la main', 'any', 0, Topics::APPROVED, array( 'wikidata:Q4040' ) );
		$kept  = $this->topics->create( 'Têt déjà classé', 'person', 0, Topics::APPROVED, array( 'wikidata:Q4040' ) );
		$none  = $this->topics->create( 'Sans identifiant', 'any', 0, Topics::APPROVED, array( 'wikipedia:fr:Sans identifiant' ) );

		$this->topics->set_kind( $hand, 'any', true );
		$this->topics->set_label( $route, 'Têt (le fleuve)', true );

		$this->assertSame( array( $route ), $this->enricher->unclassified(), 'An identifier, no kind, no kind chosen by a person, approved.' );

		$dry = $this->enricher->run_batch( true, null, array( $route ), true );

		$this->assertSame( 'waterway', $dry['report'][ $route ]['kind'] );
		$this->assertSame( 'any', $dry['report'][ $route ]['was'] );
		$this->assertSame( 'any', $this->topics->get( $route )['kind'], 'A dry run writes nothing.' );

		$done = $this->enricher->run_batch( false, null, array( $route ), true );

		$this->assertSame( 'waterway', $this->topics->get( $route )['kind'] );
		$this->assertSame( 'Têt (le fleuve)', $this->topics->get( $route )['label'], 'Only the kind changes.' );
		$this->assertSame( 1, $done['processed'] );
		$this->assertSame( 'person', $this->topics->get( $kept )['kind'] );
		$this->assertSame( array(), $this->enricher->unclassified() );
		$this->assertSame( 'any', $this->topics->get( $none )['kind'] );
	}

	/**
	 * Undoing a kind that a line too wide gave (slice 633): the topics of a kind that have an identifier of Wikidata and **that no person gave it** are read again and
	 * get what the table says now, or `any`; a kind given by hand, a topic born of a term (a person chose its kind in the screen of the terms), and a topic that the
	 * table still gives the kind to, stay.
	 *
	 * @return void
	 */
	public function test_taking_a_kind_away(): void {
		$wrong = $this->topics->create( 'Un pont', 'route', 0, Topics::APPROVED, array( 'wikidata:Q1000' ) );
		$right = $this->topics->create( 'Un fleuve', 'waterway', 0, Topics::APPROVED, array( 'wikidata:Q4040' ) );
		$hand  = $this->topics->create( 'Choisi', 'route', 0, Topics::APPROVED, array( 'wikidata:Q1000' ) );
		$term  = $this->topics->create( 'Un tag', 'route', 0, Topics::APPROVED, array( 'wikidata:Q1000', 'term:post_tag:5' ) );

		$this->topics->set_kind( $hand, 'route', true );

		$this->assertSame( array( $wrong ), $this->enricher->of_kind( 'route' ) );

		$dry = $this->enricher->reread_kinds( false, 'route' );

		$this->assertSame( 1, $dry['changed'] );
		$this->assertSame( array( $wrong . "\tUn pont\troute => place\tQ1000" ), $dry['lines'], 'The table gives a pass the kind place: it is read again, not just emptied.' );
		$this->assertSame( 'route', $this->topics->get( $wrong )['kind'], 'A dry run writes nothing.' );

		$this->enricher->reread_kinds( true, 'route' );

		$this->assertSame( 'place', $this->topics->get( $wrong )['kind'] );
		$this->assertSame( 'route', $this->topics->get( $hand )['kind'] );
		$this->assertSame( 'route', $this->topics->get( $term )['kind'] );
		$this->assertSame( 'waterway', $this->topics->get( $right )['kind'] );
	}

	/**
	 * The batches of the command `enrich kinds`: a dry run says and writes nothing, `--apply` writes, and what is left to do is none.
	 *
	 * @return void
	 */
	public function test_reading_the_kinds_again(): void {
		$one = $this->topics->create( 'Têt (le fleuve)', 'any', 0, Topics::APPROVED, array( 'wikidata:Q4040' ) );

		$dry = $this->enricher->reread_kinds( false );

		$this->assertTrue( $dry['ok'] );
		$this->assertSame( 1, $dry['read'] );
		$this->assertSame( 1, $dry['changed'] );
		$this->assertSame( array( $one . "\tTêt (le fleuve)\tany => waterway\tQ4040" ), $dry['lines'] );
		$this->assertSame( 'any', $this->topics->get( $one )['kind'], 'Nothing is written without --apply.' );

		$this->assertSame( 1, $this->enricher->reread_kinds( true )['changed'] );
		$this->assertSame( 'waterway', $this->topics->get( $one )['kind'] );
		$this->assertSame( 0, $this->enricher->reread_kinds( true )['read'], 'Nothing is left to read.' );
	}

	/**
	 * A topic that has an item and no label of its own (a link to Wikidata) gets its label and kind without a title to resolve.
	 *
	 * @return void
	 */
	public function test_an_topic_that_has_only_an_identifier(): void {
		$q = $this->topics->create( 'Q4040', 'other', 0, Topics::APPROVED, array( 'wikidata:Q4040' ) );

		$this->mentioned = array( $q );
		$this->enricher->run_batch();

		$this->assertSame( 'Têt', $this->topics->get( $q )['label'] );
		$this->assertSame( 'waterway', $this->topics->get( $q )['kind'] );
		$this->assertSame( array( 'items', 'ancestors' ), array_column( $this->requests, 0 ), 'No Wikipedia request: there was no title.' );
	}

	/**
	 * A service that says "wait" pauses the enrichment until the time given, nothing is written, the topics stay in the queue, and nothing is
	 * asked while it is paused.
	 *
	 * @return void
	 */
	public function test_a_refusal_pauses_the_enrichment(): void {
		$this->answers['wikipedia'] = $this->refusal( 429, array( 'retry-after' => '600' ) );

		$first = $this->enricher->run_batch();

		$this->assertFalse( $first['ok'] );
		$this->assertTrue( $first['stop'] );
		$this->assertStringContainsString( 'Paused until', $first['message'] );
		$this->assertSame( (int) $this->time + 600, $this->enricher->paused_until() );
		$this->assertSame( array( $this->id['tet'], $this->id['col'], $this->id['paris'], $this->id['missing'] ), $this->enricher->pending() );
		$this->assertSame( 'Têt (fleuve)', $this->topics->get( $this->id['tet'] )['label'] );

		$count  = count( $this->requests );
		$paused = $this->enricher->run_batch();

		$this->assertTrue( $paused['stop'] );
		$this->assertStringContainsString( 'Paused until', $paused['message'] );
		$this->assertCount( $count, $this->requests, 'Nothing is asked during a pause.' );

		$this->time                 += 601;
		$this->answers['wikipedia']  = $this->ok( '{"query":{"pages":[]}}' );

		$this->assertTrue( $this->enricher->run_batch()['ok'], 'After the pause it works again.' );
	}

	/**
	 * The delay is never shorter than an exponential back-off (5 seconds, doubling, 5 minutes at most) even when the service gives less.
	 *
	 * @return void
	 */
	public function test_the_back_off(): void {
		$this->answers['wikipedia'] = $this->refusal( 429, array( 'retry-after' => '1' ) );

		$this->enricher->run_batch();
		$this->assertSame( (int) $this->time + 5, $this->enricher->paused_until() );

		$this->time += 6;
		$this->enricher->run_batch();
		$this->assertSame( (int) $this->time + 10, $this->enricher->paused_until() );

		foreach ( range( 1, 6 ) as $unused ) {
			$this->time += 301;
			$this->enricher->run_batch();
		}

		$this->assertSame( (int) $this->time + 300, $this->enricher->paused_until(), 'Five minutes at most.' );
	}

	/**
	 * Three failures in a row stop the job (without a pause); a success starts the count again; nothing is written for a failed batch.
	 *
	 * @return void
	 */
	public function test_three_failures_stop_it(): void {
		$this->answers['wikipedia'] = $this->refusal( 500 );

		$one = $this->enricher->run_batch();
		$two = $this->enricher->run_batch();

		$this->assertFalse( $one['stop'] );
		$this->assertFalse( $two['stop'] );
		$this->assertSame( 0, $this->enricher->paused_until() );

		$three = $this->enricher->run_batch();

		$this->assertTrue( $three['stop'] );
		$this->assertStringContainsString( 'Stopped after 3 failure(s) in a row', $three['message'] );
		$this->assertSame( array(), $this->topics->enrich_state( $this->id['tet'] ) );

		$this->answers['wikipedia'] = $this->ok( '{"query":{"pages":[]}}' );
		$this->assertTrue( $this->enricher->run_batch()['ok'] );
		$this->assertSame( 0, $this->stored['index_enrich_failures'] );
	}

	/**
	 * A failure of the second or third request writes nothing either: the whole batch is tried again.
	 *
	 * @return void
	 */
	public function test_a_failure_of_a_later_request_writes_nothing(): void {
		$this->answers['ancestors'] = $this->refusal( 500 );

		$done = $this->enricher->run_batch();

		$this->assertFalse( $done['ok'] );
		$this->assertSame( 'Têt (fleuve)', $this->topics->get( $this->id['tet'] )['label'] );
		$this->assertSame( array(), $this->topics->enrich_state( $this->id['tet'] ) );
	}

	/**
	 * Without a contact nothing is sent and the job stops at once.
	 *
	 * @return void
	 */
	public function test_without_a_contact_nothing_is_sent(): void {
		$this->stored['index_wikidata_contact'] = '';

		$client = new Client( new Options( fn( $name, $default_value = false ) => $this->stored[ $name ] ?? $default_value, static function () {} ), static fn() => $this->fail( 'A request was made.' ), static fn() => '', '1.0' );
		$enrich = new Enricher(
			$this->topics,
			new TitleResolver( $client ),
			new ItemReader( $client ),
			new ClassTable( new Options( fn( $n, $d = false ) => $this->stored[ $n ] ?? $d, static function () {} ) ),
			new Options(
				fn( $n, $d = false ) => $this->stored[ $n ] ?? $d,
				function ( $n, $v ) {
					$this->stored[ $n ] = $v;
				}
			),
			fn( $id ) => true,
			'fr'
		);

		$done = $enrich->run_batch();

		$this->assertTrue( $done['stop'] );
		$this->assertStringContainsString( 'No contact', $done['message'] );
	}

	/**
	 * The job takes the stop of a unit without counting a failure, and counts the topics of a batch.
	 *
	 * @return void
	 */
	public function test_the_job_stops_when_a_unit_asks_and_counts_a_batch(): void {
		$state = array();
		$ticks = array();
		$calls = 0;
		$job   = new PagesJob(
			static function () use ( &$state ) {
				return $state;
			},
			static function ( array $new ) use ( &$state ) {
				$state = $new;
			},
			static fn() => array( 1 ),
			static fn() => 120,
			static function () use ( &$calls ) {
				++$calls;

				return 1 === $calls ? array(
					'ok'        => true,
					'processed' => 50,
				) : array(
					'ok'        => false,
					'stop'      => true,
					'message'   => 'Paused until later.',
					'processed' => 0,
				);
			},
			static function ( $delay ) use ( &$ticks ) {
				$ticks[] = $delay;
			}
		);

		$job->start( 1700000000 );
		$after = $job->tick( 1700000001 );

		$this->assertSame( 'stopped', $after['status'] );
		$this->assertSame( 50, $after['ok'] );
		$this->assertSame( 50, $after['offset'] );
		$this->assertSame( array(), $after['failed'] );
		$this->assertStringContainsString( 'stopped: Paused until later.', $after['log'][0] );
		$this->assertSame( array( 1 ), $ticks, 'A stopped job schedules nothing.' );
	}
}
