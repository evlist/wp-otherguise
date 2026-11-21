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
use Otherguise\Index\Entries;
use Otherguise\Index\Folding;
use Otherguise\Index\Resolver;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-memory-entries.php';

/**
 * No database and no WordPress: the entries are in memory.
 *
 * @covers \Otherguise\Index\Resolver
 * @covers \Otherguise\Index\Candidate
 * @covers \Otherguise\Index\Folding
 */
class IndexResolverTest extends TestCase {

	/**
	 * The entries.
	 *
	 * @var Otherguise_Test_Memory_Entries
	 */
	private $entries;

	/**
	 * The resolver.
	 *
	 * @var Resolver
	 */
	private $resolver;

	/**
	 * Starts with no entry.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();

		$this->entries  = new Otherguise_Test_Memory_Entries();
		$this->resolver = new Resolver( $this->entries );
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
	 * A candidate no entry holds creates an approved entry that holds its keys and its label key.
	 *
	 * @return void
	 */
	public function test_a_new_candidate_creates_an_entry(): void {
		$done = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ) ) );

		$this->assertSame( Resolver::CREATED, $done['result'] );

		$entry = $this->entries->get( $done['entry'] );

		$this->assertSame( 'Beaufort', $entry['label'] );
		$this->assertSame( Entries::APPROVED, $entry['status'] );
		$this->assertSame( array( 'term:category:7', 'label:0/beaufort' ), $entry['keys'] );
	}

	/**
	 * A source that is not sure creates a candidate entry.
	 *
	 * @return void
	 */
	public function test_a_source_that_is_not_sure_creates_a_candidate(): void {
		$done = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'x:1' ) ), false );

		$this->assertSame( Entries::CANDIDATE, $this->entries->get( $done['entry'] )['status'] );
	}

	/**
	 * Resolving twice gives the same entry and creates nothing.
	 *
	 * @return void
	 */
	public function test_resolving_twice_is_idempotent(): void {
		$first  = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ) ) );
		$second = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ) ) );

		$this->assertSame( Resolver::RESOLVED, $second['result'] );
		$this->assertSame( $first['entry'], $second['entry'] );
		$this->assertCount( 1, $this->entries->entries );
	}

	/**
	 * A tag and a category of the same name and kind are one entry, and each keeps its own key for later.
	 *
	 * @return void
	 */
	public function test_a_category_and_a_tag_of_one_label_share_an_entry(): void {
		$a = $this->resolver->resolve( new Candidate( 'Étroubles', 'place', array( 'term:category:7' ) ) );
		$b = $this->resolver->resolve( new Candidate( 'etroubles', 'place', array( 'term:post_tag:99' ) ) );

		$this->assertSame( $a['entry'], $b['entry'] );
		$this->assertContains( 'term:post_tag:99', $this->entries->get( $a['entry'] )['keys'] );
	}

	/**
	 * Renaming the term does not lose the entry: the key of the term finds it.
	 *
	 * @return void
	 */
	public function test_a_renamed_term_keeps_its_entry(): void {
		$a = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ) ) );
		$b = $this->resolver->resolve( new Candidate( 'Beaufort-sur-Doron', 'place', array( 'term:category:7' ) ) );

		$this->assertSame( $a['entry'], $b['entry'] );
		$this->assertCount( 1, $this->entries->entries );
	}

	/**
	 * Two homonyms with two parents are two entries.
	 *
	 * @return void
	 */
	public function test_homonyms_under_two_parents_are_two_entries(): void {
		$fr = new Candidate( 'France', 'place', array( 'term:category:1' ) );
		$it = new Candidate( 'Italie', 'place', array( 'term:category:2' ) );

		$a = $this->resolver->resolve( new Candidate( 'Saint-Rhémy', 'place', array( 'term:category:3' ), $fr ) );
		$b = $this->resolver->resolve( new Candidate( 'Saint-Rhémy', 'place', array( 'term:category:4' ), $it ) );

		$this->assertNotSame( $a['entry'], $b['entry'] );
		$this->assertNotSame( $this->entries->get( $a['entry'] )['parent'], $this->entries->get( $b['entry'] )['parent'] );
		$this->assertCount( 4, $this->entries->entries );
	}

	/**
	 * The parent is resolved and created first.
	 *
	 * @return void
	 */
	public function test_the_parent_is_an_entry_too(): void {
		$done = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ), new Candidate( 'France', 'place', array( 'term:category:1' ) ) ) );
		$up   = $this->entries->get( $this->entries->get( $done['entry'] )['parent'] );

		$this->assertSame( 'France', $up['label'] );
	}

	/**
	 * Two entries hold the key: the candidate is ambiguous, nothing is mentioned, and it is recorded once as a candidate.
	 *
	 * @return void
	 */
	public function test_a_key_held_by_two_entries_is_ambiguous(): void {
		$one = $this->entries->create( 'A', 'place', 0, Entries::APPROVED, array( 'wikidata:Q1' ) );
		$two = $this->entries->create( 'B', 'place', 0, Entries::APPROVED, array( 'wikidata:Q1' ) );

		$done = $this->resolver->resolve( new Candidate( 'C', 'place', array( 'wikidata:Q1' ) ) );
		$this->resolver->resolve( new Candidate( 'C', 'place', array( 'wikidata:Q1' ) ) );

		$this->assertSame( Resolver::AMBIGUOUS, $done['result'] );
		$this->assertSame( 0, $done['entry'] );
		$this->assertCount( 1, $this->entries->all( array( 'status' => Entries::CANDIDATE ) ) );
		$this->assertNotEmpty( array( $one, $two ) );
	}

	/**
	 * Linking is adding a key to an entry: the key then decides, and the candidate is resolved.
	 *
	 * @return void
	 */
	public function test_linking_a_key_settles_the_candidate(): void {
		$paris = $this->entries->create( 'Paris', 'place', 0, Entries::APPROVED, array( 'label:0/paris' ) );
		$this->entries->create( 'Paris', 'species', 0, Entries::APPROVED, array( 'label:0/paris-x' ) );

		$this->entries->add_keys( $paris, array( 'term:post_tag:5' ) );

		$done = $this->resolver->resolve( new Candidate( 'Paris (tag)', 'species', array( 'term:post_tag:5' ) ) );

		$this->assertSame( $paris, $done['entry'] );
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
	 * A refused entry is never found again: the candidate gets a new one.
	 *
	 * @return void
	 */
	public function test_a_refused_entry_is_ignored(): void {
		$refused = $this->entries->create( 'Repos', 'other', 0, Entries::REFUSED, array( 'term:category:9', 'label:0/repos' ) );

		$done = $this->resolver->resolve( new Candidate( 'Repos', 'other', array( 'term:category:9' ) ) );

		$this->assertSame( Resolver::CREATED, $done['result'] );
		$this->assertNotSame( $refused, $done['entry'] );
	}

	/**
	 * A dry run reports and writes nothing.
	 *
	 * @return void
	 */
	public function test_a_dry_run_writes_nothing(): void {
		$done = $this->resolver->resolve( new Candidate( 'Beaufort', 'place', array( 'term:category:7' ), new Candidate( 'France', 'place', array( 'term:category:1' ) ) ), true, true );

		$this->assertSame( Resolver::CREATED, $done['result'] );
		$this->assertSame( array(), $this->entries->entries );
	}
}
