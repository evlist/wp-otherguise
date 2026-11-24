<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the client of the Wikimedia services and of its readers (slice 604), without the network.
 *
 * The answers of the services are **handwritten from their documented shape**: they prove the code, not the services.
 *
 * @package Otherguise
 */

use Otherguise\Index\Wikidata\ClassTable;
use Otherguise\Index\Wikidata\Client;
use Otherguise\Index\Wikidata\ItemReader;
use Otherguise\Index\Wikidata\Options;
use Otherguise\Index\Wikidata\TitleResolver;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * Stubs and doubles only.
 *
 * @covers \Otherguise\Index\Wikidata\Client
 * @covers \Otherguise\Index\Wikidata\Options
 * @covers \Otherguise\Index\Wikidata\TitleResolver
 * @covers \Otherguise\Index\Wikidata\ItemReader
 * @covers \Otherguise\Index\Wikidata\ClassTable
 */
class IndexWikidataTest extends TestCase {

	/**
	 * The options, in memory.
	 *
	 * @var array<string, mixed>
	 */
	private $stored = array();

	/**
	 * The addresses asked and their arguments.
	 *
	 * @var array<int, array{0:string,1:array}>
	 */
	private $asked = array();

	/**
	 * The seconds slept.
	 *
	 * @var float[]
	 */
	private $slept = array();

	/**
	 * The time.
	 *
	 * @var float
	 */
	private $time = 1700000000.0;

	/**
	 * Starts clean, with the built-in kinds.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		Otherguise_Test_Memory_Kinds::install();

		$this->stored = array();
		$this->asked  = array();
		$this->slept  = array();
		$this->time   = 1700000000.0;
	}

	/**
	 * The options.
	 *
	 * @return Options
	 */
	private function options(): Options {
		return new Options(
			fn( $name, $default_value = false ) => $this->stored[ $name ] ?? $default_value,
			function ( $name, $value ) {
				$this->stored[ $name ] = $value;
			}
		);
	}

	/**
	 * A client on a double of the request.
	 *
	 * @param mixed       $answer  What the request returns (or a callable that gets the address).
	 * @param string|null $contact The contact; null for the default (option, then address of the site).
	 * @return Client
	 */
	private function client( $answer, $contact = null ): Client {
		return new Client(
			$this->options(),
			function ( $url, $args ) use ( $answer ) {
				$this->asked[] = array( $url, $args );

				return is_callable( $answer ) ? $answer( $url ) : $answer;
			},
			null === $contact ? null : static fn() => $contact,
			'1.2.3',
			fn() => $this->time,
			function ( $seconds ) {
				$this->slept[] = round( $seconds, 3 );
				$this->time   += $seconds;
			}
		);
	}

	/**
	 * A response of the service.
	 *
	 * @param int    $status  Status.
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 * @return array<string, mixed>
	 */
	private function response( $status, $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => $body,
		);
	}

	/**
	 * The User-Agent names the plugin and its version and gives the contact of the option, else the address of the site; with neither, nothing
	 * is sent.
	 *
	 * @return void
	 */
	public function test_the_user_agent_and_the_contact(): void {
		$this->assertSame( 'Otherguise-Index/1.2.3 (me@example.test)', $this->client( array(), 'me@example.test' )->user_agent() );
		$this->assertSame( 'Otherguise-Index/1.2.3 (https://example.test/)', ( new Client( $this->options(), static fn() => array(), null, '1.2.3' ) )->user_agent(), 'The address of the site by default.' );

		$this->stored['index_wikidata_contact'] = ' https://blog.test/contact ';
		$this->assertSame( 'Otherguise-Index/1.2.3 (https://blog.test/contact)', ( new Client( $this->options(), static fn() => array(), null, '1.2.3' ) )->user_agent() );

		$none = $this->client( $this->response( 200, '{}' ), '  ' )->get( 'https://x.test/', array() );

		$this->assertFalse( $none['ok'] );
		$this->assertSame( 'fatal', $none['class'] );
		$this->assertSame( array(), $this->asked, 'Nothing is sent without a contact.' );
	}

	/**
	 * A request carries the User-Agent, asks for JSON, has a timeout and is a GET with its parameters in the address.
	 *
	 * @return void
	 */
	public function test_the_request(): void {
		$answer = $this->client( $this->response( 200, '{"a":1}' ), 'me@example.test' )->get( 'https://x.test/api', array( 'q' => 'a b|c' ) );

		$this->assertTrue( $answer['ok'] );
		$this->assertSame( array( 'a' => 1 ), $answer['json'] );
		$this->assertSame( 'https://x.test/api?q=a+b%7Cc', $this->asked[0][0] );
		$this->assertSame( 'Otherguise-Index/1.2.3 (me@example.test)', $this->asked[0][1]['headers']['User-Agent'] );
		$this->assertSame( 'application/json', $this->asked[0][1]['headers']['Accept'] );
		$this->assertSame( 20, $this->asked[0][1]['timeout'] );
	}

	/**
	 * Two requests are at least a second apart, even from two clients (the time of the last one is kept); a request long after waits nothing.
	 *
	 * @return void
	 */
	public function test_the_interval_between_requests(): void {
		$client = $this->client( $this->response( 200, '{}' ), 'me@example.test' );

		$client->get( 'https://x.test/', array() );
		$this->time += 0.25;
		$this->client( $this->response( 200, '{}' ), 'me@example.test' )->get( 'https://x.test/', array() );

		$this->assertSame( array( 0.75 ), $this->slept );

		$this->time += 30;
		$client->get( 'https://x.test/', array() );

		$this->assertSame( array( 0.75 ), $this->slept );
	}

	/**
	 * "Wait" in every form: 429 and 503 with `Retry-After` (seconds or a date, bounded), the `maxlag` and `ratelimited` errors, a text that is
	 * not JSON; the other failures are errors.
	 *
	 * @return void
	 */
	public function test_the_answers_that_say_wait_and_the_failures(): void {
		$get = fn( $answer ) => $this->client( $answer, 'me@example.test' )->get( 'https://x.test/', array() );

		$too_many = $get( $this->response( 429, 'Too many requests', array( 'retry-after' => '120' ) ) );
		$this->assertSame( 'retry', $too_many['class'] );
		$this->assertSame( 120, $too_many['retry_after'] );

		$this->assertSame( 3600, $get( $this->response( 503, '', array( 'retry-after' => '99999' ) ) )['retry_after'], 'An hour at most.' );
		$this->assertSame( 60, $get( $this->response( 429, '' ) )['retry_after'], 'A minute when there is no header.' );
		$this->assertEqualsWithDelta( 90, $get( $this->response( 429, '', array( 'retry-after' => gmdate( 'D, d M Y H:i:s', (int) $this->time + 90 ) . ' GMT' ) ) )['retry_after'], 2, 'A date: the seconds from now.' );

		$lag = $get( $this->response( 200, '{"error":{"code":"maxlag","info":"Waiting for a database server"}}', array( 'retry-after' => '5' ) ) );
		$this->assertSame( 'retry', $lag['class'] );
		$this->assertSame( 5, $lag['retry_after'] );
		$this->assertSame( 'retry', $get( $this->response( 200, '{"error":{"code":"ratelimited"}}' ) )['class'] );

		$text = $get( $this->response( 200, 'You are making too many requests to the API.' ) );
		$this->assertSame( 'retry', $text['class'], 'A rate limit that comes as plain text with the status 200.' );
		$this->assertStringContainsString( 'not JSON', $text['error'] );

		$this->assertSame( 'error', $get( $this->response( 404, '' ) )['class'] );
		$this->assertSame( 'error', $get( $this->response( 200, '{"error":{"code":"badtitle"}}' ) )['class'] );

		$down = $get( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );
		$this->assertSame( 'error', $down['class'] );
		$this->assertStringContainsString( 'timed out', $down['error'] );
	}

	/**
	 * The titles of a language become items; the maps `normalized` and `redirects` bring each page back to the title asked; a page with no
	 * page, a disambiguation page and a page with no item are told apart; at most 50 titles go in one request, joined by a pipe.
	 *
	 * @return void
	 */
	public function test_the_titles(): void {
		$json = '{"batchcomplete":true,"query":{"normalized":[{"from":"tet","to":"Tet"}],"redirects":[{"from":"Tet","to":"Têt (fleuve)"}],"pages":['
			. '{"pageid":1,"title":"Têt (fleuve)","pageprops":{"wikibase_item":"Q4040"}},'
			. '{"pageid":2,"title":"Paris (homonymie)","pageprops":{"disambiguation":"","wikibase_item":"Q9"}},'
			. '{"pageid":3,"title":"Sans élément"},'
			. '{"title":"Page inexistante","missing":true}]}}';

		$resolver = new TitleResolver( $this->client( $this->response( 200, $json ), 'me@example.test' ) );
		$answer   = $resolver->resolve( 'fr', array( 'tet', 'Têt (fleuve)', 'Paris (homonymie)', 'Sans élément', 'Page inexistante' ) );

		$this->assertTrue( $answer['response']['ok'] );
		$this->assertSame(
			array(
				'tet'               => array(
					'state' => 'resolved',
					'qid'   => 'Q4040',
				),
				'Têt (fleuve)'      => array(
					'state' => 'resolved',
					'qid'   => 'Q4040',
				),
				'Paris (homonymie)' => array(
					'state' => 'ambiguous',
					'qid'   => '',
				),
				'Sans élément'      => array(
					'state' => 'none',
					'qid'   => '',
				),
				'Page inexistante'  => array(
					'state' => 'missing',
					'qid'   => '',
				),
			),
			$answer['results']
		);
		$this->assertStringStartsWith( 'https://fr.wikipedia.org/w/api.php?', $this->asked[0][0] );

		parse_str( (string) wp_parse_url( $this->asked[0][0], PHP_URL_QUERY ), $query );

		$this->assertSame( 'wikibase_item|disambiguation', $query['ppprop'] );
		$this->assertSame( '5', $query['maxlag'] );
		$this->assertSame( 'tet|Têt (fleuve)|Paris (homonymie)|Sans élément|Page inexistante', $query['titles'] );

		$resolver->resolve( 'fr', array_map( static fn( $n ) => 'Page ' . $n, range( 1, 80 ) ) );
		parse_str( (string) wp_parse_url( $this->asked[1][0], PHP_URL_QUERY ), $second );

		$this->assertCount( 50, explode( '|', $second['titles'] ) );
	}

	/**
	 * A language that is not a code is never put in an address; a failed request gives no result.
	 *
	 * @return void
	 */
	public function test_the_language_is_checked_and_a_failure_gives_nothing(): void {
		$resolver = new TitleResolver( $this->client( $this->response( 500, '' ), 'me@example.test' ) );

		$this->assertFalse( $resolver->resolve( 'fr.evil.test/', array( 'A' ) )['response']['ok'] );
		$this->assertSame( array(), $this->asked );
		$this->assertSame( array(), $resolver->resolve( 'fr', array( 'A' ) )['results'] );
		$this->assertTrue( TitleResolver::valid_language( 'zh-hans' ) );
		$this->assertFalse( TitleResolver::valid_language( 'FR' ) );
	}

	/**
	 * The label and the classes of items: the label service answers the identifier when there is no label, which is not a label; an item with
	 * no class keeps an empty list.
	 *
	 * @return void
	 */
	public function test_the_items(): void {
		$json = '{"head":{"vars":[]},"results":{"bindings":['
			. '{"item":{"type":"uri","value":"http://www.wikidata.org/entity/Q4040"},"itemLabel":{"type":"literal","value":"Têt"},"class":{"type":"uri","value":"http://www.wikidata.org/entity/Q4022"},"classLabel":{"type":"literal","value":"cours d\'eau"}},'
			. '{"item":{"type":"uri","value":"http://www.wikidata.org/entity/Q4040"},"itemLabel":{"type":"literal","value":"Têt"},"class":{"type":"uri","value":"http://www.wikidata.org/entity/Q355304"},"classLabel":{"type":"literal","value":"Q355304"}},'
			. '{"item":{"type":"uri","value":"http://www.wikidata.org/entity/Q77"},"itemLabel":{"type":"literal","value":"Q77"}}]}}';

		$reader = new ItemReader( $this->client( $this->response( 200, $json ), 'me@example.test' ), static fn() => 'https://query.test/sparql' );
		$answer = $reader->read( array( 'Q4040', 'Q77', 'Q4040' ), 'fr' );

		$this->assertSame(
			array(
				'Q4040' => array(
					'label'   => 'Têt',
					'classes' => array(
						'Q4022'   => 'cours d\'eau',
						'Q355304' => '',
					),
				),
				'Q77'   => array(
					'label'   => '',
					'classes' => array(),
				),
			),
			$answer['items']
		);
		$this->assertStringStartsWith( 'https://query.test/sparql?', $this->asked[0][0] );

		parse_str( (string) wp_parse_url( $this->asked[0][0], PHP_URL_QUERY ), $query );

		$this->assertSame( 'json', $query['format'] );
		$this->assertStringContainsString( 'VALUES ?item { wd:Q4040 wd:Q77 }', $query['query'] );
		$this->assertStringContainsString( 'wikibase:language "fr,en"', $query['query'] );
	}

	/**
	 * Only identifiers that are identifiers are put in a query: whatever else comes (a title, a quote, a brace) is dropped; a language that is
	 * not a code is refused; at most 50 identifiers go in one query.
	 *
	 * @return void
	 */
	public function test_nothing_but_identifiers_goes_into_a_query(): void {
		$reader = new ItemReader( $this->client( $this->response( 200, '{"results":{"bindings":[]}}' ), 'me@example.test' ), static fn() => 'https://query.test/sparql' );

		$reader->read( array( 'Q1', 'Q2" } . DROP', 'q3', 'Q0', 'Q4 Q5', '', 'Q06', 'Q7' ), 'fr' );
		parse_str( (string) wp_parse_url( $this->asked[0][0], PHP_URL_QUERY ), $query );

		$this->assertStringContainsString( 'VALUES ?item { wd:Q1 wd:Q7 }', $query['query'] );
		$this->assertStringNotContainsString( 'DROP', $query['query'] );

		$this->assertFalse( $reader->read( array( 'Q1' ), 'fr"; ' )['response']['ok'] );
		$this->assertFalse( $reader->read( array( 'nonsense' ), 'fr' )['response']['ok'] );
		$this->assertCount( 1, $this->asked );

		$reader->read( array_map( static fn( $n ) => 'Q' . $n, range( 1, 80 ) ), 'fr' );
		parse_str( (string) wp_parse_url( $this->asked[1][0], PHP_URL_QUERY ), $big );

		$this->assertSame( 50, substr_count( $big['query'], 'wd:Q' ) );
		$this->assertTrue( ItemReader::valid_id( 'Q42' ) );
		$this->assertFalse( ItemReader::valid_id( 'Q042' ) );
	}

	/**
	 * The ancestors of classes, each class included.
	 *
	 * @return void
	 */
	public function test_the_ancestors(): void {
		$json = '{"results":{"bindings":['
			. '{"class":{"value":"http://www.wikidata.org/entity/Q4022"},"ancestor":{"value":"http://www.wikidata.org/entity/Q4022"}},'
			. '{"class":{"value":"http://www.wikidata.org/entity/Q4022"},"ancestor":{"value":"http://www.wikidata.org/entity/Q355304"}},'
			. '{"class":{"value":"http://www.wikidata.org/entity/Q4022"},"ancestor":{"value":"http://www.wikidata.org/entity/Q618123"}}]}}';

		$reader = new ItemReader( $this->client( $this->response( 200, $json ), 'me@example.test' ), static fn() => 'https://query.test/sparql' );
		$answer = $reader->ancestors( array( 'Q4022', 'Q9' ) );

		$this->assertSame(
			array(
				'Q4022' => array( 'Q4022', 'Q355304', 'Q618123' ),
				'Q9'    => array( 'Q9' ),
			),
			$answer['ancestors']
		);

		parse_str( (string) wp_parse_url( $this->asked[0][0], PHP_URL_QUERY ), $query );

		$this->assertStringContainsString( '?class wdt:P279* ?ancestor', $query['query'] );
		$this->assertSame( array(), $reader->ancestors( array() )['ancestors'] );
	}

	/**
	 * The itineraries (slice 633, checked on Wikidata on 2026-11-24): EuroVelo 9 is a *long-distance cycling route*, which comes under *cycling route* and
	 * *thoroughfare*; a hiking trail, a road and a motorway come under the thoroughfare too, so they are all of the kind `route`; a river is still a waterway.
	 *
	 * @return void
	 */
	public function test_the_itineraries_are_routes(): void {
		$table = new ClassTable( $this->options(), fn() => (int) $this->time, static fn( $min ) => $min );

		$table->remember(
			array(
				'Q353027'  => array( 'Q353027', 'Q102307360', 'Q83620', 'Q137255322' ),
				'Q13405588' => array( 'Q13405588', 'Q2143825', 'Q1322323', 'Q1297806', 'Q83620' ),
				'Q46622'   => array( 'Q46622', 'Q269949', 'Q34442', 'Q83620' ),
				'Q4022'    => array( 'Q4022', 'Q355304' ),
			)
		);

		$this->assertSame( 'route', $table->kind_for( array( 'Q353027' ) ), 'A long-distance cycling route (EuroVelo 9).' );
		$this->assertSame( 'route', $table->kind_for( array( 'Q13405588' ) ), 'A long-distance trail.' );
		$this->assertSame( 'route', $table->kind_for( array( 'Q46622' ) ), 'A motorway.' );
		$this->assertSame( 'waterway', $table->kind_for( array( 'Q4022' ) ) );
	}

	/**
	 * The table of classes: the default one, a line added (identifier and kind checked), removed.
	 *
	 * @return void
	 */
	public function test_the_table_of_classes(): void {
		$table = new ClassTable( $this->options(), fn() => (int) $this->time, static fn( $min ) => $min );

		$this->assertSame( ClassTable::DEFAULTS, $table->mapping() );
		$this->assertSame( 'waterway', $table->mapping()['Q4022'] );

		$this->assertTrue( $table->set( 'Q628179', 'route' ) );
		$this->assertSame( 'route', $table->mapping()['Q628179'] );
		$this->assertArrayHasKey( 'Q4022', $table->mapping(), 'The default lines are kept when one is added.' );
		$this->assertStringContainsString( 'not the identifier', $table->set( 'river', 'waterway' ) );
		$this->assertStringContainsString( 'Unknown kind', $table->set( 'Q1', 'nonsense' ) );

		$this->assertTrue( $table->remove( 'Q628179' ) );
		$this->assertFalse( $table->remove( 'Q628179' ) );
	}

	/**
	 * The ancestors are kept about 90 days with a jitter, and a class whose ancestors expired is asked again.
	 *
	 * @return void
	 */
	public function test_the_cache_of_ancestors(): void {
		$table = new ClassTable( $this->options(), fn() => (int) $this->time, static fn( $min ) => $min );

		$this->assertSame( array( 'Q1', 'Q2' ), $table->unknown( array( 'Q1', 'Q2', 'Q1' ) ) );

		$table->remember( array( 'Q1' => array( 'Q1', 'Q10' ) ) );

		$this->assertSame( array( 'Q2' ), $table->unknown( array( 'Q1', 'Q2' ) ) );
		$this->assertSame( (int) $this->time + (int) ( ClassTable::TTL * 0.8 ), $this->stored['index_wikidata_ancestors']['Q1']['until'], 'The expiry is within 20 % of 90 days.' );

		$this->time += ClassTable::TTL * 0.8 + 1;

		$this->assertSame( array( 'Q1', 'Q2' ), $table->unknown( array( 'Q1', 'Q2' ) ) );
	}

	/**
	 * The kind of the classes of an item: the ancestors count, the most specific kind wins, the table decides a tie, a class that is not in the
	 * table says nothing.
	 *
	 * @return void
	 */
	public function test_the_kind_of_an_item(): void {
		\Otherguise\Index\Kinds::add( 'hamlet', 'Hamlet', 'place' );

		$table = new ClassTable( $this->options(), fn() => (int) $this->time, static fn( $min ) => $min );
		$table->set( 'Q10', 'place' );
		$table->set( 'Q11', 'hamlet' );
		$table->set( 'Q12', 'person' );

		$this->assertNull( $table->kind_for( array( 'Q999' ) ) );
		$this->assertSame( 'place', $table->kind_for( array( 'Q10' ) ) );

		$table->remember(
			array(
				'Q20' => array( 'Q20', 'Q11', 'Q10' ),
				'Q21' => array( 'Q21', 'Q12' ),
			)
		);

		$this->assertSame( 'hamlet', $table->kind_for( array( 'Q20' ) ), 'The ancestors count, and the most specific kind wins.' );
		$this->assertSame( 'hamlet', $table->kind_for( array( 'Q10', 'Q20' ) ) );

		$this->assertSame( 'person', $table->kind_for( array( 'Q21', 'Q999' ) ) );
		$this->assertSame( 'place', $table->kind_for( array( 'Q12', 'Q10' ) ), 'A tie (both are first-level kinds): Q10 comes before Q12 in the table.' );
	}

	/**
	 * A commune of France is a commune (the class that Wikidata gave on the blog), not only a place: the most specific kind wins over the settlement
	 * and the administrative entity that it descends from.
	 *
	 * @return void
	 */
	public function test_a_commune_of_france_is_a_commune(): void {
		\Otherguise\Index\Kinds::add( 'commune', 'Commune', 'place' );

		$table = new ClassTable( $this->options(), fn() => (int) $this->time, static fn( $min ) => $min );

		$this->assertSame( 'commune', $table->mapping()['Q484170'] );

		$table->remember( array( 'Q484170' => array( 'Q484170', 'Q486972', 'Q56061' ) ) );

		$this->assertSame( 'commune', $table->kind_for( array( 'Q484170' ) ) );
		$this->assertSame( 'place', $table->kind_for( array( 'Q486972' ) ), 'A settlement alone is a place.' );
	}
}
