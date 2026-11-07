<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the index of the links (slice 506), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Module as WikiModule;
use Otherguise\Wiki\Tree\Citations;
use Otherguise\Wiki\Tree\CitedBy;
use Otherguise\Wiki\Tree\Keys;
use Otherguise\Wiki\Tree\Links;
use Otherguise\Wiki\Tree\Pages;
use Otherguise\Wiki\Tree\Router;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-node-store.php';

/**
 * The statements are real (MariaDB), the posts are an array, the tag processor is the one of WordPress (`OTHERGUISE_WP_CORE`).
 *
 * @covers \Otherguise\Wiki\Tree\Citations
 * @covers \Otherguise\Wiki\Tree\CitedBy
 */
class WikiTreeCitationsDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * The pages.
	 *
	 * @var Pages
	 */
	private $pages;

	/**
	 * The index of the links.
	 *
	 * @var Citations
	 */
	private $citations;

	/**
	 * The router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Ids: bio, par, op.
	 *
	 * @var array<string, int>
	 */
	private $id = array();

	/**
	 * Pages that the reader may not see.
	 *
	 * @var int[]
	 */
	private $hidden = array();

	/**
	 * Builds the tree Biography > Paris; Opinion, and the index.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		otherguise_test_wp_objects( array() );

		$core = getenv( 'OTHERGUISE_WP_CORE' );

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) && is_string( $core ) && is_file( $core . '/html-api/class-wp-html-tag-processor.php' ) ) {
			foreach ( array( 'class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $file ) {
				require_once $core . '/html-api/' . $file . '.php';
			}

			require_once __DIR__ . '/support/function-wp-has-noncharacters.php';
		}

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$this->markTestSkipped( 'WP_HTML_Tag_Processor is not available (set OTHERGUISE_WP_CORE to wp-includes).' );
		}

		$wiki       = new WikiModule();
		$site       = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $wiki ) {
					$wiki->register_predicates( $registry );
				},
			)
		);
		$statements = $site->statements;
		$this->keys = new Keys(
			static function () use ( $statements ) {
				return $statements;
			}
		);

		$this->router    = new Router( $this->keys, fn() => '/', fn() => null, static fn( $path ) => 'https://example.test' . $path );
		$this->citations = new Citations(
			$this->keys,
			new Links( $this->keys, $this->router, static fn() => 'https://example.test' ),
			static function () use ( $statements ) {
				return $statements;
			}
		);
		$this->pages     = new Pages(
			$this->keys,
			new Otherguise_Test_Node_Store(),
			null,
			function ( $id ) {
				$this->citations->claimed( $id );
			}
		);

		$bio      = $this->pages->create( 0, 'Biography', '', 'publish' );
		$par      = $this->pages->create( $bio, 'Paris', '', 'publish' );
		$op       = $this->pages->create( 0, 'Opinion', '', 'publish' );
		$this->id = compact( 'bio', 'par', 'op' );
	}

	/**
	 * A link in a content.
	 *
	 * @param string ...$hrefs Addresses.
	 * @return string
	 */
	private function html( string ...$hrefs ): string {
		return '<p>' . implode( ' ', array_map( static fn( $href ) => '<a href="' . $href . '">x</a>', $hrefs ) ) . '</p>';
	}

	/**
	 * What the links of a content stand for: pages cited, paths wanted, the rest ignored.
	 *
	 * @return void
	 */
	public function test_reading_a_content(): void {
		$read = $this->citations->read( $this->html( '/Biography/Paris', '/Biography/Paris/', '/Opinion', '/Biography/Nobody/Deeper', '/biography/x', '/hello-world/', 'https://other.test/Opinion' ) );

		$this->assertSame( array( $this->id['par'], $this->id['op'] ), $read['cites'] );
		$this->assertSame( array( 'Biography/Nobody/Deeper', 'biography/x' ), $read['wants'] );
		$this->assertSame(
			array(
				'cites' => array(),
				'wants' => array(),
			),
			$this->citations->read( 'no link' )
		);
	}

	/**
	 * Saving: statements added, removed, kept; nothing written when nothing changed; a link to itself is left out.
	 *
	 * @return void
	 */
	public function test_sync(): void {
		$this->assertTrue( $this->citations->sync( $this->id['op'], $this->html( '/Biography/Paris', '/Opinion', '/Biography/Nobody' ) ) );

		$this->assertSame( array( $this->id['op'] ), $this->citations->cited_by( $this->id['par'] ) );
		$this->assertSame( array(), $this->citations->cited_by( $this->id['op'] ) );
		$this->assertSame( array( 'Biography/Nobody' => array( $this->id['op'] ) ), $this->citations->wanted() );

		// Nothing changed.
		$this->assertFalse( $this->citations->sync( $this->id['op'], $this->html( '/Biography/Paris', '/Opinion', '/Biography/Nobody' ) ) );

		// The link is removed from the text.
		$this->assertTrue( $this->citations->sync( $this->id['op'], $this->html( '/Biography' ) ) );
		$this->assertSame( array(), $this->citations->cited_by( $this->id['par'] ) );
		$this->assertSame( array( $this->id['op'] ), $this->citations->cited_by( $this->id['bio'] ) );
		$this->assertSame( array(), $this->citations->wanted() );
	}

	/**
	 * A link survives a rename and a move: it is kept by id, and an alias counts as the page that has it.
	 *
	 * @return void
	 */
	public function test_links_survive_a_move(): void {
		$this->citations->sync( $this->id['op'], $this->html( '/Biography/Paris' ) );
		$this->pages->move( $this->id['par'], $this->id['op'], 'Lutèce' );

		$this->assertSame( array( $this->id['op'] ), $this->citations->cited_by( $this->id['par'] ) );

		// The old address, still in the text, is the same page.
		$this->assertFalse( $this->citations->sync( $this->id['op'], $this->html( '/Biography/Paris' ) ) );
	}

	/**
	 * Creating the page that was wanted turns the wants into cites; wanted by several pages, counted.
	 *
	 * @return void
	 */
	public function test_creating_a_wanted_page(): void {
		$this->citations->sync( $this->id['op'], $this->html( '/Biography/Nobody' ) );
		$this->citations->sync( $this->id['par'], $this->html( '/Biography/Nobody', '/Biography/Other' ) );

		$wanted = $this->citations->wanted();

		$this->assertSame( array( 'Biography/Nobody', 'Biography/Other' ), array_keys( $wanted ) );
		$this->assertEqualsCanonicalizing( array( $this->id['par'], $this->id['op'] ), $wanted['Biography/Nobody'] );

		$new = $this->pages->create( $this->id['bio'], 'Nobody', '', 'publish' );

		$this->assertEqualsCanonicalizing( array( $this->id['op'], $this->id['par'] ), $this->citations->cited_by( $new ) );
		$this->assertSame( array( 'Biography/Other' ), array_keys( $this->citations->wanted() ) );
	}

	/**
	 * Reading every page again: a dry run counts, the real run changes, a second run changes nothing.
	 *
	 * @return void
	 */
	public function test_rebuild(): void {
		$pages = array(
			array(
				'id'      => $this->id['op'],
				'content' => $this->html( '/Biography/Paris', '/Biography/Nobody' ),
			),
			array(
				'id'      => $this->id['bio'],
				'content' => 'no link',
			),
		);

		$this->assertSame(
			array(
				'pages'   => 2,
				'changed' => 1,
			),
			$this->citations->rebuild( $pages, false )
		);
		$this->assertSame( array(), $this->citations->cited_by( $this->id['par'] ) );
		$this->assertSame(
			array(
				'pages'   => 2,
				'changed' => 1,
			),
			$this->citations->rebuild( $pages )
		);
		$this->assertSame(
			array(
				'pages'   => 2,
				'changed' => 0,
			),
			$this->citations->rebuild( $pages )
		);
		$this->assertSame( array( $this->id['op'] ), $this->citations->cited_by( $this->id['par'] ) );
	}

	/**
	 * The block lists the pages that cite the current one that the reader may see; nothing when there is none.
	 *
	 * @return void
	 */
	public function test_the_block(): void {
		$this->citations->sync( $this->id['op'], $this->html( '/Biography/Paris' ) );
		$this->citations->sync( $this->id['bio'], $this->html( '/Biography/Paris' ) );

		$block = new CitedBy( $this->citations, $this->keys, $this->router, fn() => $this->id['par'], fn( $id ) => ! in_array( $id, $this->hidden, true ) );
		$html  = $block->render();

		$this->assertStringContainsString( 'href="https://example.test/Biography"', $html );
		$this->assertStringContainsString( 'href="https://example.test/Opinion"', $html );

		$this->hidden = array( $this->id['op'], $this->id['bio'] );

		$this->assertSame( '', $block->render() );
		$this->assertSame( '', ( new CitedBy( $this->citations, $this->keys, $this->router, fn() => $this->id['op'] ) )->render() );
	}
}
