<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the sources, the visibility and the selection on the mentions (slice 605), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Index\Build\IndexBuilder;
use Otherguise\Index\Candidate;
use Otherguise\Index\Cli\BuildCommand;
use Otherguise\Index\Cli\SourcesCommand;
use Otherguise\Index\Topics;
use Otherguise\Index\Harvest\MentionElements;
use Otherguise\Index\Harvest\PageReader;
use Otherguise\Index\Harvest\Signal;
use Otherguise\Index\Harvest\SignalStore;
use Otherguise\Index\Indexer;
use Otherguise\Index\Mentions;
use Otherguise\Index\Module as IndexModule;
use Otherguise\Index\Resolver;
use Otherguise\Index\Source\Page;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * The statements are real; the topics and the kinds are in memory, the posts are stubs.
 *
 * @covers \Otherguise\Index\Mentions
 * @covers \Otherguise\Index\Indexer
 * @covers \Otherguise\Index\Source\Page
 * @covers \Otherguise\Index\Build\IndexBuilder
 * @covers \Otherguise\Index\Cli\SourcesCommand
 */
class IndexSourcesDbTest extends Otherguise_Test_Database_Case {

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
	 * Ids of the topics by name.
	 *
	 * @var array<string, int>
	 */
	private $id = array();

	/**
	 * Wires the statements and three topics.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Otherguise_Test_Memory_Kinds::install();
		otherguise_test_wp_objects( array( new WP_Post( 1 ), new WP_Post( 2 ), new WP_Post( 3 ) ) );

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

		$this->id['beaufort'] = $this->topics->create( 'Beaufort', 'place', 0, Topics::APPROVED, array() );
		$this->id['merle']    = $this->topics->create( 'Merle noir', 'species', 0, Topics::APPROVED, array() );
		$this->id['doron']    = $this->topics->create( 'Doron', 'waterway', 0, Topics::APPROVED, array() );
	}

	/**
	 * The `via` and the `visible` of a source are its own: two sources on one mention keep theirs, a new sync replaces only the values of its
	 * source, and withdrawing a source takes its values with it.
	 *
	 * @return void
	 */
	public function test_via_and_visibility_belong_to_their_source(): void {
		$e = $this->id['beaufort'];

		$this->mentions->sync(
			1,
			'post/link',
			array(
				$e => array(
					'strength' => 'passing',
					'via'      => array( 'link:fr:Beaufort' ),
					'visible'  => array( 'print' ),
				),
			)
		);
		$this->mentions->sync(
			1,
			'image/address',
			array(
				$e => array(
					'via'     => array( 'image:abc123', 'image:def456' ),
					'visible' => array(),
				),
			)
		);

		$details = $this->mentions->of_post( 1 )[ $e ]['details'];

		$this->assertSame(
			array(
				'image/address' => array(
					'via'     => array( 'image:abc123', 'image:def456' ),
					'visible' => null,
				),
				'post/link'     => array(
					'via'     => array( 'link:fr:Beaufort' ),
					'visible' => array( 'print' ),
				),
			),
			array_map(
				static function ( $detail ) {
					sort( $detail['via'] );

					return $detail;
				},
				$details
			)
		);

		// The link now shows in two modes and its via changed; the image source is untouched.
		$this->mentions->sync(
			1,
			'post/link',
			array(
				$e => array(
					'via'     => array( 'link:fr:Beaufort (Savoie)' ),
					'visible' => array( 'print', 'web' ),
				),
			)
		);

		$details = $this->mentions->of_post( 1 )[ $e ]['details'];

		$this->assertSame( array( 'link:fr:Beaufort (Savoie)' ), $details['post/link']['via'] );
		$this->assertEqualsCanonicalizing( array( 'print', 'web' ), $details['post/link']['visible'] );
		$this->assertCount( 2, $details['image/address']['via'] );

		$count = count( $this->wpdb->get_results( 'SELECT id FROM `' . $this->table() . '`' ) );
		$this->mentions->sync(
			1,
			'post/link',
			array(
				$e => array(
					'via' => array( 'link:fr:Beaufort (Savoie)' ),
					'visible' => array( 'web', 'print' ),
				),
			)
		);
		$this->assertSame( $count, count( $this->wpdb->get_results( 'SELECT id FROM `' . $this->table() . '`' ) ), 'The same sync writes nothing.' );

		// Withdrawing the link takes its values, and only its values.
		$this->mentions->sync( 1, 'post/link', array() );

		$left = $this->mentions->of_post( 1 )[ $e ]['details'];

		$this->assertSame( array( 'image/address' ), array_keys( $left ) );
		$this->assertCount( 2, $left['image/address']['via'] );
	}

	/**
	 * A value longer than the column keeps its beginning and a hash of the whole, so that two long values stay two.
	 *
	 * @return void
	 */
	public function test_a_long_via_is_shortened_without_losing_its_identity(): void {
		$a = Mentions::pack( 'post/link', str_repeat( 'x', 300 ) . 'A' );
		$b = Mentions::pack( 'post/link', str_repeat( 'x', 300 ) . 'B' );

		$this->assertLessThanOrEqual( 191, strlen( $a ) );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 'post/link|short', Mentions::pack( 'post/link', 'short' ) );
	}

	/**
	 * Renaming a source gives its mentions to the new one, drops the old one when the new name is empty (a mention with no source left is deleted),
	 * and finds nothing to do the second time.
	 *
	 * @return void
	 */
	public function test_renaming_and_dropping_a_source(): void {
		$this->mentions->sync( 1, 'categories', array( $this->id['beaufort'] => 'passing' ) );
		$this->mentions->sync( 1, 'tags', array( $this->id['merle'] => 'passing' ) );
		$this->mentions->sync(
			1,
			'page',
			array(
				$this->id['doron'] => 'passing',
				$this->id['merle'] => 'passing',
			)
		);

		$this->assertSame( 1, $this->mentions->rename_source( 'categories', 'post/category' ) );
		$this->assertSame( 1, $this->mentions->rename_source( 'tags', 'post/tag' ) );
		$this->assertSame( 0, $this->mentions->rename_source( 'categories', 'post/category' ), 'Run twice, nothing more to do.' );
		$this->assertSame( 2, $this->mentions->rename_source( 'page', '' ) );

		$found = $this->mentions->of_post( 1 );

		$this->assertSame( array( 'post/category' ), $found[ $this->id['beaufort'] ]['sources'] );
		$this->assertSame( array( 'post/tag' ), $found[ $this->id['merle'] ]['sources'], 'The mention that has another source stays.' );
		$this->assertArrayNotHasKey( $this->id['doron'], $found, 'A mention with no source left is deleted.' );
	}

	/**
	 * The source `page` gives its candidates their own sources: a link to Wikipedia is `post/link` with its `via`, a mark is `post/mark`, with
	 * the modes of `data-index-modes`; each source is brought in line by itself, so a link that disappears leaves the mark.
	 *
	 * @return void
	 */
	public function test_the_page_source_makes_links_and_marks(): void {
		$meta  = array();
		$store = new SignalStore(
			static function ( $id, $key ) use ( &$meta ) {
				return $meta[ $id ][ $key ] ?? '';
			},
			static function ( $id, $key, $value ) use ( &$meta ) {
				$meta[ $id ][ $key ] = is_string( $value ) ? stripslashes( $value ) : $value;
			},
			static fn() => 1700000000
		);

		$store->save(
			1,
			array(
				new Signal( Signal::WIKIPEDIA_LINK, 'Beaufort', 'content', 'href', array( 'language' => 'fr' ) ),
				new Signal(
					Signal::TOPIC,
					'wikidata:Q9',
					'page',
					'data-index',
					array(
						'path'       => array( 'wikidata:Q9' ),
						'identifier' => true,
						'label'      => 'Merle noir',
						'kind'       => 'species',
						'modes'      => array( 'web' ),
					),
					'obs1'
				),
			)
		);

		$indexer = new Indexer( new Resolver( $this->topics ), $this->mentions, array( new Page( $store ) ), static fn() => true );
		$report  = $indexer->index_post( 1 );

		$this->assertSame( 2, $report['added'] );

		$found = $this->mentions->of_post( 1 );
		$link  = $this->topics->with_key( 'wikipedia:fr:Beaufort' )[0];
		$mark  = $this->topics->with_key( 'wikidata:Q9' )[0];

		$this->assertSame( array( 'post/link' ), $found[ $link ]['sources'] );
		$this->assertSame( array( 'link:fr:Beaufort' ), $found[ $link ]['details']['post/link']['via'] );
		$this->assertNull( $found[ $link ]['details']['post/link']['visible'], 'Every mode by default.' );

		$this->assertSame( array( 'post/mark' ), $found[ $mark ]['sources'] );
		$this->assertSame( array( 'mark:#obs1' ), $found[ $mark ]['details']['post/mark']['via'] );
		$this->assertSame( array( 'web' ), $found[ $mark ]['details']['post/mark']['visible'] );

		// The link disappears from the page: its mention goes, the mark stays.
		$store->save(
			1,
			array(
				new Signal(
					Signal::TOPIC,
					'wikidata:Q9',
					'page',
					'data-index',
					array(
						'path' => array( 'wikidata:Q9' ),
						'identifier' => true,
						'label' => 'Merle noir',
						'kind' => 'species',
						'modes' => array( 'web' ),
					),
					'obs1'
				),
			)
		);
		$again = $indexer->index_post( 1 );

		$this->assertSame( 1, $again['removed'] );
		$this->assertSame( array( $mark ), array_keys( $this->mentions->of_post( 1 ) ) );
	}

	/**
	 * Two candidates for one topic from one source merge: the strongest strength, both `via`, and every mode as soon as one is shown everywhere.
	 *
	 * @return void
	 */
	public function test_candidates_for_one_topic_merge(): void {
		$source = new class() implements \Otherguise\Index\Source {
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
			 * Three candidates for one topic.
			 *
			 * @param int $post_id Post.
			 * @return Candidate[]
			 */
			public function candidates( $post_id ) {
				unset( $post_id );

				return array(
					( new Candidate( 'Beaufort', 'place', array( 'k:1' ), null, Candidate::PASSING ) )->from( 'post/link', array( 'link:a' ), array( 'print' ) ),
					( new Candidate( 'Beaufort', 'place', array( 'k:1' ), null, Candidate::MAIN ) )->from( 'post/link', array( 'link:b' ), array( 'web' ) ),
					( new Candidate( 'Beaufort', 'place', array( 'k:1' ), null, Candidate::PASSING ) )->from( 'post/link', array( 'link:c' ) ),
				);
			}
		};

		( new Indexer( new Resolver( $this->topics ), $this->mentions, array( $source ), static fn() => true ) )->index_post( 1 );

		$mention = array_values( $this->mentions->of_post( 1 ) )[0];
		$via     = $mention['details']['post/link']['via'];

		sort( $via );

		$this->assertSame( 'main', $mention['strength'] );
		$this->assertSame( array( 'link:a', 'link:b', 'link:c' ), $via );
		$this->assertNull( $mention['details']['post/link']['visible'], 'One candidate is shown everywhere, so the mention is.' );
	}

	/**
	 * Builds a small book of three posts with mentions of several sources and visibilities.
	 *
	 * @return IndexBuilder
	 */
	private function book(): IndexBuilder {
		$say = fn( $post, $topic, $source, $visible = array(), $strength = 'passing' ) => $this->mentions->sync(
			$post,
			$source,
			array(
				$this->id[ $topic ] => array(
					'strength' => $strength,
					'visible'  => $visible,
					'via'      => array( 'v:' . $post . $topic ),
				),
			)
		);

		$say( 1, 'beaufort', 'post/tag' );
		$say( 1, 'merle', 'post/mark', array( 'web' ) );
		$say( 2, 'beaufort', 'image/address', array( 'print' ) );
		$say( 2, 'doron', 'post/link', array( 'print', 'web' ) );
		$say( 3, 'merle', 'post/link', array( 'print' ) );
		$say( 3, 'doron', 'post/category', array(), 'main' );

		return new IndexBuilder( $this->topics, $this->mentions );
	}

	/**
	 * The labels of the lines, in order.
	 *
	 * @param IndexBuilder         $builder The builder.
	 * @param array<string, mixed> $options Options.
	 * @return string[]
	 */
	private function labels( IndexBuilder $builder, array $options ): array {
		return array_column( $builder->build( array( 1, 2, 3 ), $options )->lines(), 'label' );
	}

	/**
	 * Eric's three indexes of one book: what is visible in the book itself, the general index, and the photographs.
	 *
	 * @return void
	 */
	public function test_the_three_indexes_of_a_book(): void {
		$builder = $this->book();

		$this->assertSame( array( 'Beaufort', 'Doron', 'Merle noir' ), $this->labels( $builder, array() ), 'The general index: no filter.' );

		$printed = $builder->build( array( 1, 2, 3 ), array( 'include' => 'visibility:print' ) )->lines();

		$this->assertSame( array( 'Beaufort', 'Doron', 'Merle noir' ), array_column( $printed, 'label' ), 'Visible in print: the tag is shown in every mode, the image and the links in print; the mark of the web is not.' );

		$by = array_column( $printed, null, 'label' );

		$this->assertSame( array( 1, 2 ), array_column( $by['Beaufort']['locators'], 'post' ), 'A tag with no restriction is visible in print too.' );
		$this->assertSame( array( 3 ), array_column( $by['Merle noir']['locators'], 'post' ), 'The mention of the web mark of post 1 is dropped, the print link of post 3 stays.' );

		$photos = $builder->build( array( 1, 2, 3 ), array( 'include' => 'source:image' ) )->lines();

		$this->assertSame( array( 'Beaufort' ), array_column( $photos, 'label' ) );
		$this->assertSame( array( 2 ), array_column( $photos[0]['locators'], 'post' ), 'The photograph of post 2, not the tag of post 1.' );
		$this->assertSame( array( array( 'image/address' ) ), array_column( $photos[0]['locators'], 'sources' ) );
	}

	/**
	 * The selection on the three dimensions together, exclusion, and the old way of giving kinds.
	 *
	 * @return void
	 */
	public function test_the_selection_in_the_builder(): void {
		$builder = $this->book();

		$this->assertSame( array( 'Beaufort', 'Doron' ), $this->labels( $builder, array( 'include' => 'kind:place' ) ), 'A waterway is a place.' );
		$this->assertSame(
			array( 'Beaufort' ),
			$this->labels(
				$builder,
				array(
					'include' => 'place',
					'exclude' => 'waterway',
				)
			),
			'A bare word is a kind (slice 610).'
		);
		$this->assertSame( array( 'Beaufort', 'Doron' ), $this->labels( $builder, array( 'exclude' => 'species' ) ) );
		$this->assertSame(
			array( 'Doron' ),
			$this->labels(
				$builder,
				array(
					'include' => 'kind:place source:post visibility:print',
					'exclude' => 'source:post/tag',
				)
			),
			'Beaufort has only a tag and an image, Doron a link.'
		);
		$this->assertSame( array(), $this->labels( $builder, array( 'include' => 'source:trace' ) ) );
	}

	/**
	 * The command reads the tokens and refuses what it does not know, with the reason.
	 *
	 * @return void
	 */
	public function test_the_build_command_takes_tokens(): void {
		$builder = $this->book();
		$out     = array();
		$fail    = array();
		$cmd     = new BuildCommand(
			$builder,
			static fn() => array( 1, 2, 3 ),
			static fn( $post ) => (string) $post,
			static function ( $line ) use ( &$out ) {
				$out[] = $line;
			},
			static function ( $line ) use ( &$fail ) {
				$fail[] = $line;
			}
		);

		$cmd(
			array(),
			array(
				'section' => '9',
				'format' => 'csv',
				'include' => 'source:image',
			)
		);

		$this->assertSame( "letter,label,kind,posts,main\nB,Beaufort,place,2,", $out[0] );

		$cmd(
			array(),
			array(
				'section' => '9',
				'include' => 'colour:red source:nowhere',
			)
		);

		$this->assertStringContainsString( 'Unknown selection "colour:red"', $fail[0] );
		$this->assertStringContainsString( 'Unknown source "nowhere"', $fail[0] );
		$this->assertCount( 1, $out );
	}

	/**
	 * The command of the sources: the tree, the migration (run twice) and the check of what the mentions hold.
	 *
	 * @return void
	 */
	public function test_the_sources_command(): void {
		$this->mentions->sync( 1, 'categories', array( $this->id['beaufort'] => 'passing' ) );
		$this->mentions->sync( 1, 'post/link', array( $this->id['merle'] => array( 'visible' => array( 'print', 'gone' ) ) ) );
		$this->mentions->sync( 2, 'plugin/removed', array( $this->id['doron'] => 'passing' ) );

		$log = array();
		$cmd = new SourcesCommand(
			$this->mentions,
			static fn() => array( 'print', 'web' ),
			static function ( $line ) use ( &$log ) {
				$log[] = $line;
			},
			static function ( $line ) use ( &$log ) {
				$log[] = 'OK ' . $line;
			}
		);

		$cmd->list( array(), array() );
		$this->assertContains( "  post/tag\tA tag of the post", $log );
		$this->assertContains( "post\tA post", $log );

		$log = array();
		$cmd->check( array(), array() );

		$this->assertContains( "categories\t1 mention(s)\tNOT IN THE REGISTRY (an old name: wp otherguise index sources migrate)", $log );
		$this->assertContains( "plugin/removed\t1 mention(s)\tNOT IN THE REGISTRY", $log );
		$this->assertContains( "mode gone\t1 value(s)\tUNKNOWN MODE", $log );
		$this->assertContains( "mode print\t1 value(s)", $log );
		$this->assertContains( 'OK 3 thing(s) to correct.', $log );

		$log = array();
		$cmd->migrate( array(), array() );

		$this->assertContains( 'categories => post/category: 1', $log );
		$this->assertContains( 'page => (dropped): 0', $log );

		$log = array();
		$cmd->migrate( array(), array() );
		$this->assertContains( 'OK 0 qualification(s) changed.', $log, 'Run twice: nothing more.' );
		$this->assertSame( array( 'post/category' ), $this->mentions->of_post( 1 )[ $this->id['beaufort'] ]['sources'] );
	}

	/**
	 * The elements that stand for a stored mention are marked with what produced it (slice 612): a link to Wikipedia and a mark, not a link that
	 * made no mention, and the builder gives that `via` to the locators so that the pages of the elements can be found.
	 *
	 * @return void
	 */
	public function test_the_elements_of_the_mentions_are_marked(): void {
		if ( ! Otherguise_Test_Html_Api::load() ) {
			$this->markTestSkipped( 'The tag processor is not available.' );
		}

		$html  = '<main><div class="wp-block-post-content"><p>Voir <a href="https://fr.wikipedia.org/wiki/Beaufort">Beaufort</a>, <span id="obs1" data-index="wikidata:Q9" data-index-label="Merle noir" data-index-kind="species">le merle</span> et <a href="https://example.org/x">un lien</a> et <a href="https://fr.wikipedia.org/wiki/Autre">Autre</a>.</p></div></main>';
		$meta  = array();
		$store = new SignalStore(
			static function ( $id, $key ) use ( &$meta ) {
				return $meta[ $id ][ $key ] ?? '';
			},
			static function ( $id, $key, $value ) use ( &$meta ) {
				$meta[ $id ][ $key ] = is_string( $value ) ? stripslashes( $value ) : $value;
			},
			static fn() => 1700000000
		);
		$page   = new Page( $store );
		$reader = new PageReader();

		// The job stored the signals of the page; the link to « Autre » is then taken out of the stored mentions by hand: it made none.
		$store->save( 1, array_values( array_filter( $reader->signals( $html, 1 ), static fn( $signal ) => 'Autre' !== $signal->value ) ) );
		( new Indexer( new Resolver( $this->topics ), $this->mentions, array( $page ), static fn() => true ) )->index_post( 1 );

		$marked = ( new MentionElements( $reader, $page, $this->mentions ) )->mark( $html, 1 );

		$this->assertStringContainsString( '<a data-index-mention="link:fr:Beaufort"', $marked );
		$this->assertStringContainsString( 'data-index-mention="mark:#obs1"', $marked );
		$this->assertSame( 2, substr_count( $marked, 'data-index-mention' ), 'The link to another site and the link that made no mention are left alone.' );

		$line = ( new IndexBuilder( $this->topics, $this->mentions ) )->build( array( 1 ) )->groups();
		$vias = array();

		foreach ( $line as $lines ) {
			foreach ( $lines as $one ) {
				foreach ( $one['locators'] as $locator ) {
					$vias = array_merge( $vias, $locator['via'] );
				}
			}
		}

		sort( $vias );

		$this->assertSame( array( 'link:fr:Beaufort', 'mark:#obs1' ), $vias );
		$this->assertSame( '<p>Rien</p>', ( new MentionElements( $reader, $page, $this->mentions ) )->mark( '<p>Rien</p>', 2 ), 'A post with no mention is left as it is.' );
	}
}
