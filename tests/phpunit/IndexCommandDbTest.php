<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the command `wp otherguise index` (slice 600), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Index\Candidate;
use Otherguise\Index\Cli\IndexCommand;
use Otherguise\Index\Indexer;
use Otherguise\Index\Mentions;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Resolver;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * The statements are real; the topics and the posts are not.
 *
 * @covers \Otherguise\Index\Cli\IndexCommand
 */
class IndexCommandDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The command: kind, rebuild with a dry run, topics, link and show.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		Otherguise_Test_Memory_Kinds::install();
		otherguise_test_wp_objects( array( new WP_Post( 12 ), new WP_Post( 13 ) ) );

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
		$mentions   = new Mentions(
			static function () use ( $statements ) {
				return $statements;
			}
		);
		$topics    = new Otherguise_Test_Memory_Topics();
		$log        = array();
		$fail       = array();
		$set        = array();

		$source = new class() implements \Otherguise\Index\Source {
			/**
			 * Slug.
			 *
			 * @return string
			 */
			public function id() {
				return 'categories';
			}

			/**
			 * The source of its mentions.
			 *
			 * @return string[]
			 */
			public function mention_sources() {
				return array( 'post/category' );
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
			 * One candidate.
			 *
			 * @param int $post_id Post.
			 * @return Candidate[]
			 */
			public function candidates( $post_id ) {
				unset( $post_id );

				return array( new Candidate( 'Beaufort', 'place', array( 'term:category:2' ), new Candidate( 'France', 'place', array( 'term:category:1' ) ) ) );
			}
		};

		$command = new IndexCommand(
			$topics,
			new Indexer( new Resolver( $topics ), $mentions, array( $source ), fn() => true ),
			$mentions,
			static fn( $offset ) => 0 === $offset ? array( 12, 13 ) : array(),
			static function ( $taxonomy, $term, $kind ) use ( &$set ) {
				$set[] = array( $taxonomy, $term, $kind );

				return 'missing' === $term ? 'No such term: missing.' : true;
			},
			static function ( $line ) use ( &$log ) {
				$log[] = $line;
			},
			static function ( $line ) use ( &$log ) {
				$log[] = 'OK ' . $line;
			},
			static function ( $line ) use ( &$fail ) {
				$fail[] = $line;
			}
		);

		$command->rebuild( array(), array( 'dry-run' => true ) );
		$this->assertSame( array(), $topics->topics );
		$this->assertContains( '  created: 4', $log, 'Two posts, each reporting Beaufort and France as new (nothing is written).' );

		$command->rebuild( array(), array() );
		$this->assertCount( 2, $topics->topics );
		$this->assertContains( '  added: 2', $log );

		$log = array();
		$command->rebuild( array(), array() );
		$this->assertContains( '  added: 0', $log );
		$this->assertContains( '  created: 0', $log );

		$command->rebuild( array(), array( 'source' => 'nope' ) );
		$this->assertStringContainsString( 'Unknown source', $fail[0] );

		$command->kind( array( 'post_tag', 'beaufort', 'place' ), array() );
		$command->kind( array( 'post_tag', 'beaufort', 'none' ), array() );
		$command->kind( array( 'post_tag', 'beaufort', 'bogus' ), array() );
		$command->kind( array( 'post_tag', 'missing', 'place' ), array() );

		$this->assertSame( array( array( 'post_tag', 'beaufort', 'place' ), array( 'post_tag', 'beaufort', '' ), array( 'post_tag', 'missing', 'place' ) ), $set );
		$this->assertStringContainsString( 'Unknown kind', $fail[1] );
		$this->assertSame( 'No such term: missing.', $fail[2] );

		$log = array();
		$command->topics( array(), array( 'status' => 'approved' ) );
		$this->assertCount( 2, $log );

		$ambiguous = $topics->create( 'Beaufort?', 'place', 0, 'candidate', array( 'ambiguous:wikidata:Q9' ) );
		$beaufort  = array_key_first( $mentions->of_post( 12 ) );
		$command->link( array( 'wikidata:Q9', (string) $beaufort ), array() );
		$this->assertNull( $topics->get( $ambiguous ) );
		$this->assertContains( 'wikidata:Q9', $topics->get( $beaufort )['keys'] );

		$command->link( array( 'wikidata:Q9', '999' ), array() );
		$this->assertStringContainsString( 'existing topic', $fail[3] );

		$log = array();
		$command->show( array( '12' ), array() );
		$this->assertSame( array( $beaufort . "\tBeaufort\tpassing\tpost/category" ), $log );
	}
}
