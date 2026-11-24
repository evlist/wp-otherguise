<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the groups of duplicates, the pairs that are not doubles and the screen where they are merged (slice 618).
 *
 * @package Otherguise
 */

use Otherguise\Index\Admin\DuplicatesScreen;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;
use Otherguise\Index\Merge\DuplicateFinder;
use Otherguise\Index\Merge\IgnoredPairs;
use Otherguise\Index\Merge\TopicMerger;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Topics;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * The statements are real; the topics and the kinds are in memory; the request, the nonce and the redirection are doubles.
 *
 * @covers \Otherguise\Index\Merge\DuplicateFinder
 * @covers \Otherguise\Index\Merge\IgnoredPairs
 * @covers \Otherguise\Index\Admin\DuplicatesScreen
 */
class IndexDuplicatesScreenTest extends Otherguise_Test_Database_Case {

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
	 * The ignored pairs, in memory.
	 *
	 * @var string[]
	 */
	private $saved = array();

	/**
	 * The request, by type and name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $request = array();

	/**
	 * Wires the statements.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Otherguise_Test_Memory_Kinds::install();
		Kinds::add( 'commune', 'Commune', 'place' );
		Kinds::add( 'admin2', 'Province', 'place' );
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
		$this->saved    = array();
		$this->request  = array();
	}

	/**
	 * The store of the pairs that are not doubles.
	 *
	 * @return IgnoredPairs
	 */
	private function ignored(): IgnoredPairs {
		return new IgnoredPairs(
			fn() => $this->saved,
			function ( array $pairs ) {
				$this->saved = $pairs;
			}
		);
	}

	/**
	 * The finder, with the pairs that are not doubles left out.
	 *
	 * @return DuplicateFinder
	 */
	private function finder(): DuplicateFinder {
		return new DuplicateFinder( $this->topics, array( $this->ignored(), 'has' ) );
	}

	/**
	 * The screen.
	 *
	 * @param array<int, string> $urls    Receives the redirections.
	 * @param bool               $allowed Whether the user may.
	 * @param bool               $nonce   Whether the nonce is good.
	 * @return DuplicatesScreen
	 */
	private function screen( array &$urls, $allowed = true, $nonce = true ): DuplicatesScreen {
		return new DuplicatesScreen(
			$this->finder(),
			new TopicMerger( $this->topics, $this->mentions ),
			$this->ignored(),
			$this->topics,
			function ( $id ) {
				$of = $this->mentions->of_topic( (int) $id );

				return array(
					'posts'   => count( $of ),
					'sources' => array_values( array_unique( array_merge( array(), ...array_column( $of, 'sources' ) ) ) ),
				);
			},
			static fn() => $allowed,
			fn( $type, $name ) => $this->request[ $type ][ $name ] ?? null,
			static fn() => $nonce,
			static function ( $url ) use ( &$urls ) {
				$urls[] = $url;
			}
		);
	}

	/**
	 * The page, printed.
	 *
	 * @param DuplicatesScreen $screen The screen.
	 * @return string
	 */
	private function page( DuplicatesScreen $screen ): string {
		ob_start();
		$screen->body();

		return (string) ob_get_clean();
	}

	/**
	 * The pairs that are not doubles: names that differ by a number, names with two different qualifiers, and the pairs that a person kept; a group holds
	 * every topic that is linked by a pair; a group is safe when one topic has a place and a kind and the others are loose and unclassified.
	 *
	 * @return void
	 */
	public function test_the_groups_and_what_is_not_offered(): void {
		$france = $this->topics->create( 'Italie', 'place', 0, Topics::APPROVED, array() );
		$pise_p = $this->topics->create( 'Pise', 'admin2', $france, Topics::APPROVED, array() );
		$pise_c = $this->topics->create( 'Pise', 'commune', $pise_p, Topics::APPROVED, array() );
		$pise_l = $this->topics->create( 'Pise', 'any', 0, Topics::APPROVED, array() );
		$lucca  = $this->topics->create( 'Lucques', 'any', 0, Topics::APPROVED, array() );
		$lucc_c = $this->topics->create( 'Lucques', 'commune', $france, Topics::APPROVED, array() );
		$this->topics->create( 'EuroVelo 5', 'any', 0, Topics::APPROVED, array() );
		$this->topics->create( 'EuroVelo 8', 'any', 0, Topics::APPROVED, array() );
		$this->topics->create( 'Route nationale 1 (Italie)', 'any', 0, Topics::APPROVED, array() );
		$this->topics->create( 'Route nationale 1 (Albanie)', 'any', 0, Topics::APPROVED, array() );

		$groups = $this->finder()->groups();
		$labels = array_map( static fn( $group ) => $group['members'][0]['label'], $groups );

		$this->assertSame( array( 'Lucques', 'Pise' ), $labels, 'Neither the EuroVelo numbers nor the two qualifiers.' );

		$pise = $groups[1];

		$this->assertSame( array( $pise_p, $pise_c, $pise_l ), array_column( $pise['members'], 'id' ), 'The province and the commune are in the group, with the loose topic.' );
		$this->assertNull( $pise['safe'], 'Two topics have a place: a person chooses.' );
		$this->assertSame(
			array(
				'keep' => $lucc_c,
				'go'   => array( $lucca ),
			),
			$groups[0]['safe']
		);

		// A person says that Pise the province and Pise the commune are two.
		$ignored = $this->ignored();

		$this->assertSame( 1, $ignored->add_group( array( $pise_p, $pise_c ) ) );
		$this->assertSame( 0, $ignored->add_group( array( $pise_c, $pise_p ) ), 'Once is enough.' );
		$this->assertTrue( $ignored->has( $pise_c, $pise_p ) );

		$again = $this->finder()->groups();
		$pise  = $again[1];

		$this->assertSame( array( $pise_p, $pise_c, $pise_l ), array_column( $pise['members'], 'id' ), 'Still linked through the loose topic.' );

		$ignored->add_group( array( $pise_p, $pise_l, $pise_c ) );
		$ignored->remove_group( array( $pise_p, $pise_l ) );

		$this->assertFalse( $ignored->has( $pise_p, $pise_l ), 'A pair can be offered again.' );
		$this->assertTrue( $ignored->has( $pise_c, $pise_l ) );
	}

	/**
	 * The page lists the groups, with who stays, what goes in it, the kind, the place and the posts; the safe merges have their button.
	 *
	 * @return void
	 */
	public function test_the_list(): void {
		$under = $this->topics->create( 'Italie', 'place', 0, Topics::APPROVED, array() );
		$loose = $this->topics->create( 'Lucques', 'any', 0, Topics::APPROVED, array() );
		$kept  = $this->topics->create( 'Lucques', 'commune', $under, Topics::APPROVED, array() );

		$this->mentions->sync( 1, 'post/link', array( $loose => array() ) );

		$urls = array();
		$page = $this->page( $this->screen( $urls ) );

		$this->assertStringContainsString( '1 group(s) of topics that may be the same', $page );
		$this->assertStringContainsString( 'Look at the 1 safe suggestion(s)', $page );
		$this->assertStringContainsString( 'name="keep" value="' . $kept . '" checked', $page, 'The topic with a place stays by default.' );
		$this->assertStringContainsString( 'name="go[]" value="' . $loose . '" checked', $page );
		$this->assertStringContainsString( 'Italie', $page, 'Its place.' );
		$this->assertStringContainsString( 'These are not doubles', $page );

		$this->request[ INPUT_GET ] = array( 's' => 'nothing like it' );

		$this->assertStringContainsString( '0 group(s)', $this->page( $this->screen( $urls ) ) );
	}

	/**
	 * The preview says what would be moved and changes nothing; the merge is a second step with a nonce and the capability; the safe merges only do what
	 * the finder still calls safe; "not doubles" keeps the pairs.
	 *
	 * @return void
	 */
	public function test_preview_then_merge_then_ignore(): void {
		$under = $this->topics->create( 'Italie', 'place', 0, Topics::APPROVED, array() );
		$loose = $this->topics->create( 'Lucques', 'any', 0, Topics::APPROVED, array() );
		$kept  = $this->topics->create( 'Lucques', 'commune', $under, Topics::APPROVED, array() );
		$a     = $this->topics->create( 'Pise', 'any', 0, Topics::APPROVED, array() );
		$b     = $this->topics->create( 'Pise', 'commune', $under, Topics::APPROVED, array() );
		$c     = $this->topics->create( 'Pise', 'admin2', $under, Topics::APPROVED, array() );

		$this->mentions->sync( 1, 'post/link', array( $loose => array() ) );
		$this->mentions->sync( 2, 'post/tag', array( $kept => array() ) );

		$urls = array();

		$this->request[ INPUT_GET ] = array(
			'view' => 'preview',
			'keep' => (string) $kept,
			'go'   => array( (string) $loose ),
		);
		$preview = $this->page( $this->screen( $urls ) );

		$this->assertStringContainsString( 'Preview', $preview );
		$this->assertStringContainsString( '<td>1</td><td>0</td>', $preview, 'One mention moved, none joined.' );
		$this->assertStringContainsString( 'name="op" value="merge"', $preview );
		$this->assertNotNull( $this->topics->get( $loose ), 'A preview changes nothing.' );
		$this->assertStringNotContainsString( 'cannot be one', $preview );

		// The merge: capability and nonce first.
		$this->request[ INPUT_POST ] = array(
			'_wpnonce' => 'good',
			'op'       => 'merge',
			'keep'     => (string) $kept,
			'go'       => array( (string) $loose ),
		);

		foreach ( array( array( false, true ), array( true, false ) ) as $case ) {
			try {
				$this->screen( $urls, $case[0], $case[1] )->handle();
				$this->fail( 'Stopped.' );
			} catch ( RuntimeException $stop ) {
				$this->assertStringContainsString( 'wp_die', $stop->getMessage() );
			}
		}

		$this->assertNotNull( $this->topics->get( $loose ), 'Nothing was merged without the capability or the nonce.' );

		$this->screen( $urls )->handle();

		$this->assertNull( $this->topics->get( $loose ) );
		$this->assertSame( array( 1, 2 ), array_keys( $this->mentions->of_topic( $kept ) ) );
		$this->assertStringContainsString( 'merged=1', $urls[0] );
		$this->assertStringContainsString( 'moved=1', $urls[0] );

		// A merge that cannot be done says why and goes on; kinds that cannot be one need the force box.
		$species = $this->topics->create( 'Pise', 'species', 0, Topics::APPROVED, array() );

		$this->request[ INPUT_POST ] = array(
			'_wpnonce' => 'good',
			'op'       => 'merge',
			'keep'     => (string) $b,
			'go'       => array( (string) $species, '9999' ),
		);
		$this->screen( $urls )->handle();

		$this->assertNotNull( $this->topics->get( $species ), 'Refused without the force.' );
		$this->assertStringContainsString( 'error=', end( $urls ) );

		// "Not doubles" keeps the pairs.
		$this->request[ INPUT_POST ] = array(
			'_wpnonce' => 'good',
			'op'       => 'ignore',
			'ids'      => array( (string) $b, (string) $c ),
		);
		$this->screen( $urls )->handle();

		$this->assertStringContainsString( 'ignored=1', end( $urls ) );
		$this->assertTrue( $this->ignored()->has( $b, $c ) );

		// The safe merges: only what is still safe is done, whatever was posted.
		$loose2 = $this->topics->create( 'Sienne', 'any', 0, Topics::APPROVED, array() );
		$kept2  = $this->topics->create( 'Sienne', 'commune', $under, Topics::APPROVED, array() );

		$this->request[ INPUT_POST ] = array(
			'_wpnonce' => 'good',
			'op'       => 'merge_safe',
			'safe'     => array( $kept2 . ':' . $loose2, $b . ':' . $a ),
		);
		$this->screen( $urls )->handle();

		$this->assertNull( $this->topics->get( $loose2 ), 'The safe one was merged.' );
		$this->assertNotNull( $this->topics->get( $a ), 'A merge that is not a safe one of the finder is not done, whatever was posted.' );
		$this->assertStringContainsString( 'merged=1', end( $urls ) );

		// The view of the safe merges lists none now.
		$this->request[ INPUT_GET ] = array( 'view' => 'safe' );

		$this->assertStringContainsString( 'None.', $this->page( $this->screen( $urls ) ) );
	}

	/**
	 * A parent and its child of the same name (the arrondissement of Alès and the commune of Alès) are shown with the loose topic of the same name, to tell
	 * them apart, and the merge is **not** safe (the loose topic may be either); two such topics alone are not a double; the page shows the kind, the
	 * sources and the keys in columns of their own, not in parentheses that look like a qualifier of the name.
	 *
	 * @return void
	 */
	public function test_a_parent_and_its_child_of_the_same_name_make_the_merge_unsafe(): void {
		Kinds::add( 'admin3', 'Third-level division', 'place' );

		$gard    = $this->topics->create( 'Gard', 'admin2', 0, Topics::APPROVED, array() );
		$arrond  = $this->topics->create( 'Alès', 'admin3', $gard, Topics::APPROVED, array( 'term:category:9' ) );
		$commune = $this->topics->create( 'Alès', 'commune', $arrond, Topics::APPROVED, array() );
		$loose   = $this->topics->create( 'Alès', 'any', 0, Topics::APPROVED, array( 'wikipedia:fr:Alès' ) );
		$pair    = $this->topics->create( 'Nîmes', 'admin3', $gard, Topics::APPROVED, array() );
		$this->topics->create( 'Nîmes', 'commune', $pair, Topics::APPROVED, array() );

		$this->mentions->sync( 1, 'post/link', array( $loose => array() ) );
		$this->mentions->sync( 2, 'image/address', array( $commune => array() ) );

		$groups = $this->finder()->groups();

		$this->assertCount( 1, $groups, 'Nîmes the arrondissement and its commune alone are not a double.' );
		$this->assertSame( array( $arrond, $commune, $loose ), array_column( $groups[0]['members'], 'id' ) );
		$this->assertNull( $groups[0]['safe'], 'Two topics have a place: it is not safe to put the loose one in either.' );

		$urls = array();
		$page = $this->page( $this->screen( $urls ) );

		$this->assertStringContainsString( '<td>Third-level division</td>', $page, 'The kind has a column of its own.' );
		$this->assertStringContainsString( '<td>post/link</td>', $page, 'The sources of the mentions.' );
		$this->assertStringContainsString( '<td>image/address</td>', $page );
		$this->assertStringContainsString( 'wikipedia:fr:Alès', $page, 'The keys show where a topic comes from.' );
		$this->assertStringContainsString( 'term:category:9', $page );
		$this->assertStringNotContainsString( 'Look at the', $page, 'No safe merge.' );
	}

	/**
	 * A loose topic of the same kind or of a more general kind than the placed one is safe; a loose topic with a qualifier in parentheses (it names one of
	 * several namesakes) or of a narrower or unrelated kind is not.
	 *
	 * @return void
	 */
	public function test_the_loose_topic_may_have_a_general_kind_but_not_a_qualifier(): void {
		$gard  = $this->topics->create( 'Gard', 'admin2', 0, Topics::APPROVED, array() );
		$ales  = $this->topics->create( 'Nîmes', 'commune', $gard, Topics::APPROVED, array() );
		$place = $this->topics->create( 'Nîmes', 'place', 0, Topics::APPROVED, array( 'wikipedia:fr:Nîmes' ) );
		$same  = $this->topics->create( 'Nîmes', 'commune', 0, Topics::APPROVED, array( 'label:0/nimes' ) );

		$groups = $this->finder()->groups();

		$this->assertCount( 1, $groups );
		$this->assertSame( $ales, $groups[0]['safe']['keep'] );
		$this->assertEqualsCanonicalizing( array( $place, $same ), $groups[0]['safe']['go'] );
	}
}
