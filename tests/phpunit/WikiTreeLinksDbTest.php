<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the links by name (slice 505), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Module as WikiModule;
use Otherguise\Wiki\Tree\Keys;
use Otherguise\Wiki\Tree\Links;
use Otherguise\Wiki\Tree\Pages;
use Otherguise\Wiki\Tree\Router;
use Otherguise\Wiki\Tree\SearchTitles;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-node-store.php';

/**
 * The statements are real (MariaDB), the posts are an array, the tag processor is the one of WordPress (from the core files when they are
 * at hand: the variable `OTHERGUISE_WP_CORE` names the directory `wp-includes`).
 *
 * @covers \Otherguise\Wiki\Tree\Links
 * @covers \Otherguise\Wiki\Tree\SearchTitles
 * @covers \Otherguise\Wiki\Tree\Pages::move
 */
class WikiTreeLinksDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The index.
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
	 * The ids: bio, par, rue, op, opp.
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
	 * Builds the tree: Biography > Paris > Rue; Opinion > Paris.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		otherguise_test_wp_objects( array() );

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
		$this->pages = new Pages( $this->keys, new Otherguise_Test_Node_Store() );

		$bio      = $this->pages->create( 0, 'Biography', '', 'publish' );
		$par      = $this->pages->create( $bio, 'Paris', '', 'publish' );
		$rue      = $this->pages->create( $par, 'Rue', '', 'publish' );
		$op       = $this->pages->create( 0, 'Opinion', '', 'publish' );
		$opp      = $this->pages->create( $op, 'Paris', '', 'publish' );
		$this->id = compact( 'bio', 'par', 'rue', 'op', 'opp' );
	}

	/**
	 * The service, with the address of the test site and the visibility of the test.
	 *
	 * @param string $home The address of the site.
	 * @return Links
	 */
	private function links( $home = 'https://example.test' ): Links {
		return new Links(
			$this->keys,
			new Router( $this->keys, fn() => '/', fn() => null, static fn( $path ) => $home . $path ),
			static fn() => $home,
			fn( $id ) => ! in_array( $id, $this->hidden, true )
		);
	}

	/**
	 * What an address stands for: relative or absolute, the host of the site only, a prefix, a fragment, an encoded accent.
	 *
	 * @return void
	 */
	public function test_titles_of_an_address(): void {
		$links = $this->links();

		$this->assertSame( array( 'Biography', 'Paris' ), $links->titles_of( '/Biography/Paris' ) );
		$this->assertSame( array( 'Biography', 'Paris' ), $links->titles_of( 'https://example.test/Biography/Paris/#x' ) );
		$this->assertSame( array( 'Biography', 'Ans' . "\u{F2}" ), $links->titles_of( '/Biography/Ans%C3%B2?a=1' ) );
		$this->assertNull( $links->titles_of( 'https://other.test/Biography/Paris' ) );
		$this->assertNull( $links->titles_of( 'mailto:a@b.test' ) );
		$this->assertNull( $links->titles_of( '#top' ) );
		$this->assertNull( $links->titles_of( '/' ) );
		$this->assertNull( $links->titles_of( 'Biography/Paris' ) );
		$this->assertNull( $links->titles_of( '/?page_id=3' ) );

		$prefixed = $this->links( 'https://example.test/blog' );

		$this->assertSame( array( 'Biography' ), $prefixed->titles_of( '/blog/Biography' ) );
		$this->assertNull( $prefixed->titles_of( '/Biography' ) );
	}

	/**
	 * The state of a path: a page, an alias after a move, missing when the path enters the tree, other when it does not.
	 *
	 * @return void
	 */
	public function test_the_state_of_a_path(): void {
		$this->assertSame( 'page', $this->links()->state( array( 'Biography', 'Paris' ) )['state'] );
		$this->assertSame( 'page', $this->links()->state( array( 'Biography', 'Paris', 'Rue' ) )['state'] );
		$this->assertSame( 'missing', $this->links()->state( array( 'Biography', 'Nobody' ) )['state'] );
		$this->assertSame( 'missing', $this->links()->state( array( 'biography', 'paris' ) )['state'] );
		$this->assertSame( 'other', $this->links()->state( array( 'hello-world' ) )['state'] );
		$this->assertSame( 'other', $this->links()->state( array( 'Nowhere', 'Paris' ) )['state'] );

		$this->pages->move( $this->id['par'], $this->id['op'], 'Lutèce' );

		$alias = $this->links()->state( array( 'Biography', 'Paris', 'Rue' ) );

		$this->assertSame( 'alias', $alias['state'] );
		$this->assertSame( 'https://example.test/Opinion/Lut%C3%A8ce/Rue', $alias['url'] );

		$this->hidden = array( $this->id['rue'] );

		$this->assertSame( 'missing', $this->links()->state( array( 'Opinion', 'Lutèce', 'Rue' ) )['state'] );
	}

	/**
	 * The render marks the links, rewrites an alias, keeps the query and the fragment, and leaves the rest alone.
	 *
	 * @return void
	 */
	public function test_the_render(): void {
		$this->load_tag_processor();

		$this->pages->move( $this->id['par'], $this->id['op'], 'Lutèce' );

		$html = '<p><a href="/Opinion/Lut%C3%A8ce">ok</a> <a href="/Biography/Paris?x=1#n">old</a> <a href="/Biography/Nobody" class="c">red</a>'
			. ' <a href="https://other.test/Biography/Paris">out</a> <a href="/hello-world/">post</a> <a>none</a></p>';
		$out  = $this->links()->filter( $html );

		$this->assertStringContainsString( '<a class="wiki-link" href="/Opinion/Lut%C3%A8ce">ok</a>', $out );
		$this->assertStringContainsString( 'class="wiki-link" href="/Opinion/Lut%C3%A8ce?x=1#n"', $out, 'An alias is rewritten, as a relative address.' );
		$this->assertStringContainsString( 'class="c wiki-link wiki-missing"', $out );
		$this->assertStringContainsString( 'title="This page does not exist"', $out );
		$this->assertStringContainsString( '<a href="https://other.test/Biography/Paris">out</a>', $out );
		$this->assertStringContainsString( '<a href="/hello-world/">post</a> <a>none</a>', $out );
		$this->assertSame( 'no link', $this->links()->filter( 'no link' ) );
		$this->assertSame( 5, $this->links()->filter( 5 ) );
	}

	/**
	 * A link is written in the canonical form: `%20` or a space for an underscore, a lower-case encoding, a trailing slash and another
	 * scheme are rewritten, a relative address stays relative and an absolute one absolute, the query string and the fragment are kept; the
	 * canonical form is left as it is.
	 *
	 * @return void
	 */
	public function test_a_link_is_written_in_the_canonical_form(): void {
		$this->load_tag_processor();

		$this->pages->create( $this->id['par'], 'Rue de la Paix (Paris): 1', '', 'publish' );

		$cases = array(
			'/Biography/Paris/Rue%20de%20la%20Paix%20(Paris):%201'          => '/Biography/Paris/Rue_de_la_Paix_(Paris):_1',
			'/Biography/Paris/Rue de la Paix (Paris): 1'                    => '/Biography/Paris/Rue_de_la_Paix_(Paris):_1',
			'/Biography/Paris/Rue_de_la_Paix_(Paris):_1/'                   => '/Biography/Paris/Rue_de_la_Paix_(Paris):_1',
			'/Biography/Paris/Rue%20de%20la%20Paix%20(Paris):%201?a=1#part' => '/Biography/Paris/Rue_de_la_Paix_(Paris):_1?a=1#part',
			'http://example.test/Biography/Paris'                          => 'https://example.test/Biography/Paris',
			'https://example.test/Biography/Paris/'                        => 'https://example.test/Biography/Paris',
			'/Biography/Paris'                                             => null,
			'https://example.test/Biography/Paris'                          => null,
			'/Biography/Paris#section'                                     => null,
		);

		foreach ( $cases as $href => $expected ) {
			$out = $this->links()->filter( '<a href="' . $href . '">x</a>' );

			$this->assertStringContainsString( 'class="wiki-link"', $out, $href );
			$this->assertStringContainsString( 'href="' . ( $expected ?? $href ) . '"', $out, $href );
		}
	}

	/**
	 * Loads the tag processor of WordPress when the tests run without it.
	 *
	 * @return void
	 */
	private function load_tag_processor(): void {
		$core = getenv( 'OTHERGUISE_WP_CORE' );

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) && is_string( $core ) && is_file( $core . '/html-api/class-wp-html-tag-processor.php' ) ) {
			foreach ( array( 'class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $file ) {
				require_once $core . '/html-api/' . $file . '.php';
			}
		}

		if ( class_exists( 'WP_HTML_Tag_Processor' ) && ! function_exists( 'wp_has_noncharacters' ) ) {
			require_once __DIR__ . '/support/function-wp-has-noncharacters.php';
		}

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$this->markTestSkipped( 'WP_HTML_Tag_Processor is not available (set OTHERGUISE_WP_CORE to wp-includes).' );
		}
	}

	/**
	 * A move changes only the key of the page: the old address is an alias, the descendants follow, and the refusals write nothing.
	 *
	 * @return void
	 */
	public function test_move(): void {
		$this->pages->move( $this->id['par'], $this->id['op'], 'Lutèce' );

		$this->assertSame( array( 'Opinion', 'Lutèce', 'Rue' ), $this->keys->path_of( $this->id['rue'] ) );
		$this->assertSame( array( 'Biography', 'Paris' ), array( 'Biography', 'Paris' ) );
		$this->assertSame( $this->id['par'], $this->keys->resolve( $this->id['bio'], 'Paris' )['post'] );
		$this->assertFalse( $this->keys->resolve( $this->id['bio'], 'Paris' )['current'] );

		// Run twice: nothing to do.
		$this->assertNull( $this->pages->move( $this->id['par'], $this->id['op'], 'Lutèce' ) );

		// The title is taken under the new parent.
		$this->assertSame( \Otherguise\Wiki\NameException::TAKEN, $this->refusal( $this->id['rue'], $this->id['op'], 'Lutèce' ) );
		// Below itself, below a descendant, an unknown parent.
		$this->assertSame( \Otherguise\Wiki\NameException::BAD_PARENT, $this->refusal( $this->id['par'], $this->id['par'] ) );
		$this->assertSame( \Otherguise\Wiki\NameException::BAD_PARENT, $this->refusal( $this->id['par'], $this->id['rue'] ) );
		$this->assertSame( \Otherguise\Wiki\NameException::UNKNOWN_PARENT, $this->refusal( $this->id['par'], 999 ) );

		// A dry run writes nothing.
		$this->assertNull( $this->pages->move( $this->id['rue'], 0, 'Racine', false ) );
		$this->assertSame( array( 'Opinion', 'Lutèce', 'Rue' ), $this->keys->path_of( $this->id['rue'] ) );

		$this->pages->move( $this->id['rue'], 0, 'Racine' );
		$this->assertSame( array( 'Racine' ), $this->keys->path_of( $this->id['rue'] ) );
	}

	/**
	 * The code of the refusal of a move, or an empty string.
	 *
	 * @param int        $id     The page.
	 * @param int        $parent The new parent.
	 * @param mixed|null $title  The new title.
	 * @return string
	 */
	private function refusal( $id, $parent, $title = null ): string {
		try {
			$this->pages->move( $id, $parent, $title );
		} catch ( \Otherguise\Wiki\NameException $refusal ) {
			return $refusal->error_code();
		}

		return '';
	}

	/**
	 * While the search of the REST API runs, a page of the tree is titled with its ancestors; otherwise nothing changes.
	 *
	 * @return void
	 */
	public function test_the_titles_of_the_search(): void {
		$search = new SearchTitles( $this->keys );

		$this->assertSame( 'Paris', $search->title( 'Paris', $this->id['opp'] ) );

		$search->begin( array() );

		$this->assertSame( 'Paris — Opinion', $search->title( 'Paris', $this->id['opp'] ) );
		$this->assertSame( 'Rue — Paris / Biography', $search->title( 'Rue', $this->id['rue'] ) );
		$this->assertSame( 'Biography', $search->title( 'Biography', $this->id['bio'] ) );
		$this->assertSame( 'Hello', $search->title( 'Hello', 999 ) );
		$this->assertSame( array( 'a' ), $search->begin( array( 'a' ) ) );

		$this->assertSame( 'r', $search->end( 'r' ) );
		$this->assertSame( 'Paris', $search->title( 'Paris', $this->id['opp'] ) );
	}
}
