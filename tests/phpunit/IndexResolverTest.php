<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the resolver of the index (slice 600).
 *
 * @package Otherguise
 */

use Otherguise\Index\Candidate;
use Otherguise\Index\Topics;
use Otherguise\Index\Folding;
use Otherguise\Index\Resolver;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * No database and no WordPress: the topics are in memory.
 *
 * @covers \Otherguise\Index\Resolver
 * @covers \Otherguise\Index\Candidate
 * @covers \Otherguise\Index\Folding
 */
class IndexResolverTest extends TestCase {

	/**
	 * The topics.
	 *
	 * @var Otherguise_Test_Memory_Topics
	 */
	private $topics;

	/**
	 * The resolver.
	 *
	 * @var Resolver
	 */
	private $resolver;

	/**
	 * Starts with no topic.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		Otherguise_Test_Memory_Kinds::install();

		$this->topics  = new Otherguise_Test_Memory_Topics();
		$this->resolver = new Resolver( $this->topics );
	}

	/**
	 * The label key ignores the case, the accents and the spaces around.
	 *
	 * @return void
	 */
	public function test_folding(): void {
		$this->assertSame( 'etroubles', Folding::key( '  Étroubles ' ) );
		$this->assertSame( 'le pont', Folding::key( 'Le   Pont' ) );
	}

	/**
	 * A candidate no topic holds creates an approved topic that holds its keys and its label key.
	 *
	 * @return void
	 */
	public function test_a_new_candidate_creates_an_topic(): void {
		$done = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ) ) );

		$this->assertSame( Resolver::CREATED, $done['result'] );

		$topic = $this->topics->get( $done['topic'] );

		$this->assertSame( 'Beaufort', $topic['label'] );
		$this->assertSame( Topics::APPROVED, $topic['status'] );
		$this->assertSame( array( 'term:category:7', 'label:0/beaufort' ), $topic['keys'] );
	}

	/**
	 * A source that is not sure creates a candidate topic.
	 *
	 * @return void
	 */
	public function test_a_source_that_is_not_sure_creates_a_candidate(): void {
		$done = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'x:1' ) ), false );

		$this->assertSame( Topics::CANDIDATE, $this->topics->get( $done['topic'] )['status'] );
	}

	/**
	 * Resolving twice gives the same topic and creates nothing.
	 *
	 * @return void
	 */
	public function test_resolving_twice_is_idempotent(): void {
		$first  = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ) ) );
		$second = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ) ) );

		$this->assertSame( Resolver::RESOLVED, $second['result'] );
		$this->assertSame( $first['topic'], $second['topic'] );
		$this->assertCount( 1, $this->topics->topics );
	}

	/**
	 * A tag and a category of the same name and kind are one topic, and each keeps its own key for later.
	 *
	 * @return void
	 */
	public function test_a_category_and_a_tag_of_one_label_share_an_topic(): void {
		$a = $this->resolver->resolve( new Candidate( 'Étroubles', 'place', array( 'term:category:7' ) ) );
		$b = $this->resolver->resolve( new Candidate( 'etroubles', 'place', array( 'term:post_tag:99' ) ) );

		$this->assertSame( $a['topic'], $b['topic'] );
		$this->assertContains( 'term:post_tag:99', $this->topics->get( $a['topic'] )['keys'] );
	}

	/**
	 * Renaming the term does not lose the topic: the key of the term finds it.
	 *
	 * @return void
	 */
	public function test_a_renamed_term_keeps_its_topic(): void {
		$a = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ) ) );
		$b = $this->resolver->resolve( new Candidate( 'Beaufort-sur-Doron', 'place', array( 'term:category:7' ) ) );

		$this->assertSame( $a['topic'], $b['topic'] );
		$this->assertCount( 1, $this->topics->topics );
	}

	/**
	 * Two homonyms with two parents are two topics.
	 *
	 * @return void
	 */
	public function test_homonyms_under_two_parents_are_two_topics(): void {
		$fr = new Candidate( 'France', 'place', array( 'term:category:1' ) );
		$it = new Candidate( 'Italie', 'place', array( 'term:category:2' ) );

		$a = $this->resolver->resolve( new Candidate( 'Saint-Rhémy', 'place', array( 'term:category:3' ), $fr ) );
		$b = $this->resolver->resolve( new Candidate( 'Saint-Rhémy', 'place', array( 'term:category:4' ), $it ) );

		$this->assertNotSame( $a['topic'], $b['topic'] );
		$this->assertNotSame( $this->topics->get( $a['topic'] )['parent'], $this->topics->get( $b['topic'] )['parent'] );
		$this->assertCount( 4, $this->topics->topics );
	}

	/**
	 * The parent is resolved and created first.
	 *
	 * @return void
	 */
	public function test_the_parent_is_an_topic_too(): void {
		$done = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ), new Candidate( 'France', 'place', array( 'term:category:1' ) ) ) );
		$up   = $this->topics->get( $this->topics->get( $done['topic'] )['parent'] );

		$this->assertSame( 'France', $up['label'] );
	}

	/**
	 * Two topics hold the key: the candidate is ambiguous, nothing is mentioned, and it is recorded once as a candidate.
	 *
	 * @return void
	 */
	public function test_a_key_held_by_two_topics_is_ambiguous(): void {
		$one = $this->topics->create( 'A', 'place', 0, Topics::APPROVED, array( 'wikidata:Q1' ) );
		$two = $this->topics->create( 'B', 'place', 0, Topics::APPROVED, array( 'wikidata:Q1' ) );

		$done = $this->resolver->resolve( new Candidate( 'C', 'place', array( 'wikidata:Q1' ) ) );
		$this->resolver->resolve( new Candidate( 'C', 'place', array( 'wikidata:Q1' ) ) );

		$this->assertSame( Resolver::AMBIGUOUS, $done['result'] );
		$this->assertSame( 0, $done['topic'] );
		$this->assertCount( 1, $this->topics->all( array( 'status' => Topics::CANDIDATE ) ) );
		$this->assertNotEmpty( array( $one, $two ) );
	}

	/**
	 * Linking is adding a key to a topic: the key then decides, and the candidate is resolved.
	 *
	 * @return void
	 */
	public function test_linking_a_key_settles_the_candidate(): void {
		$paris = $this->topics->create( 'Paris', 'place', 0, Topics::APPROVED, array( 'label:0/paris' ) );
		$this->topics->create( 'Paris', 'species', 0, Topics::APPROVED, array( 'label:0/paris-x' ) );

		$this->topics->add_keys( $paris, array( 'term:post_tag:5' ) );

		$done = $this->resolver->resolve( new Candidate( 'Paris (tag)', 'species', array( 'term:post_tag:5' ) ) );

		$this->assertSame( $paris, $done['topic'] );
	}

	/**
	 * The same label with a kind that cannot be the same thing is left to a person; `other` does not conflict.
	 *
	 * @return void
	 */
	public function test_a_conflict_of_kind_is_ambiguous(): void {
		$this->resolver->resolve( new Candidate( 'Mouche', 'species', array( 'term:category:1' ) ) );

		$conflict = $this->resolver->resolve( new Candidate( 'Mouche', 'place', array( 'term:post_tag:2' ) ) );
		$other    = $this->resolver->resolve( new Candidate( 'Mouche', 'other', array( 'term:post_tag:3' ) ) );

		$this->assertSame( Resolver::AMBIGUOUS, $conflict['result'] );
		$this->assertSame( Resolver::RESOLVED, $other['result'] );
	}

	/**
	 * A refused topic is never found again: the candidate gets a new one.
	 *
	 * @return void
	 */
	public function test_a_refused_topic_is_ignored(): void {
		$refused = $this->topics->create( 'Repos', 'other', 0, Topics::REFUSED, array( 'term:category:9', 'label:0/repos' ) );

		$done = $this->resolver->resolve( new Candidate( 'Repos', 'other', array( 'term:category:9' ) ) );

		$this->assertSame( Resolver::CREATED, $done['result'] );
		$this->assertNotSame( $refused, $done['topic'] );
	}

	/**
	 * A dry run reports and writes nothing.
	 *
	 * @return void
	 */
	public function test_a_dry_run_writes_nothing(): void {
		$done = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ), new Candidate( 'France', 'place', array( 'term:category:1' ) ) ), true, true );

		$this->assertSame( Resolver::CREATED, $done['result'] );
		$this->assertSame( array(), $this->topics->topics );
	}
}
