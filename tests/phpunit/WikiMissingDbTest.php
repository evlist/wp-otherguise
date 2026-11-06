<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the page that does not exist (slice 504), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Missing\CreateAction;
use Otherguise\Wiki\Missing\MissingPage;
use Otherguise\Wiki\Missing\Presentation;
use Otherguise\Wiki\Module as WikiModule;
use Otherguise\Wiki\Names;
use Otherguise\Wiki\Page\Pages;
use Otherguise\Wiki\Space\Spaces;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-page-store.php';

/**
 * The statements are real (MariaDB); WordPress is a double.
 *
 * @covers \Otherguise\Wiki\Missing\MissingPage
 * @covers \Otherguise\Wiki\Missing\CreateAction
 * @covers \Otherguise\Wiki\Missing\Presentation
 */
class WikiMissingDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The names.
	 *
	 * @var Names
	 */
	private $names;

	/**
	 * The posts.
	 *
	 * @var Otherguise_Test_Page_Store
	 */
	private $store;

	/**
	 * The pages.
	 *
	 * @var Pages
	 */
	private $pages;

	/**
	 * The query variables of the main query.
	 *
	 * @var array<string, string>
	 */
	private $vars = array();

	/**
	 * Whether the request is a 404.
	 *
	 * @var bool
	 */
	private $is_404 = true;

	/**
	 * Whether the user may create a page.
	 *
	 * @var bool
	 */
	private $may = true;

	/**
	 * The ids of the pages that the user may not see.
	 *
	 * @var int[]
	 */
	private $hidden = array();

	/**
	 * What the form handler did: `redirect` or `stop`, with the details.
	 *
	 * @var array<int, array>
	 */
	private $done = array();

	/**
	 * What is posted.
	 *
	 * @var array<string, string>
	 */
	private $posted = array();

	/**
	 * Whether the nonce is valid.
	 *
	 * @var bool
	 */
	private $valid = true;

	/**
	 * The nonce action the form was checked against.
	 *
	 * @var string
	 */
	private $checked = '';

	/**
	 * Builds a site with three spaces and four pages: Biography:Paris, Opinion:Paris, Opinion:Rome, Biography:Élisabeth.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		otherguise_test_wp_objects( array() );
		update_option(
			Spaces::OPTION,
			array(
				array( 'name' => 'Biography' ),
				array( 'name' => 'Encyclopédie' ),
				array( 'name' => 'Opinion' ),
			)
		);

		$wiki        = new WikiModule();
		$site        = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $wiki ) {
					$wiki->register_predicates( $registry );
				},
			)
		);
		$statements  = $site->statements;
		$this->names = new Names(
			static function () use ( $statements ) {
				return $statements;
			}
		);
		$this->store = new Otherguise_Test_Page_Store();
		$this->pages = new Pages( $this->names, $this->store );

		foreach ( array( array( 'Biography', 'Paris' ), array( 'Opinion', 'Paris' ), array( 'Opinion', 'Rome' ), array( 'Biography', 'Élisabeth' ) ) as $page ) {
			$this->pages->create( $page[0], $page[1], '', 'publish' );
		}
	}

	/**
	 * The service, with the request and the user of the test.
	 *
	 * @return MissingPage
	 */
	private function missing(): MissingPage {
		return new MissingPage(
			$this->names,
			fn( $name ) => $this->vars[ $name ] ?? '',
			fn() => $this->is_404,
			fn() => $this->may,
			fn( $id ) => ! in_array( $id, $this->hidden, true ),
			static fn( $id ) => 'https://example.test/?p=' . $id,
			static fn( $action ) => 'nonce[' . $action . ']',
			static fn() => 'https://example.test/wp-admin/admin-post.php'
		);
	}

	/**
	 * The form handler, with the request and the redirects of the test.
	 *
	 * @return CreateAction
	 */
	private function action(): CreateAction {
		return new CreateAction(
			$this->pages,
			$this->missing(),
			fn( $name ) => $this->posted[ $name ] ?? null,
			function ( $nonce, $action ) {
				$this->checked = $action;

				return $this->valid && 'good' === $nonce;
			},
			function ( $url ) {
				$this->done[] = array( 'redirect', $url );
			},
			function ( $message, $status ) {
				$this->done[] = array( 'stop', $message, $status );
			},
			static fn( $id ) => 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit',
			static fn( $id ) => 'https://example.test/?p=' . $id
		);
	}

	/**
	 * It is the page of a wiki address only when the request is a 404 that keeps a space and a title.
	 *
	 * @return void
	 */
	public function test_what_was_asked(): void {
		$this->vars = array(
			'wiki_space' => 'Biography',
			'wiki_title' => 'Nobody',
		);

		$this->assertSame(
			array(
				'space' => 'Biography',
				'title' => 'Nobody',
			),
			$this->missing()->asked()
		);

		$this->is_404 = false;
		$this->assertNull( $this->missing()->asked(), 'Not a 404.' );

		$this->is_404 = true;
		$this->vars   = array( 'wiki_space' => 'Biography' );
		$this->assertNull( $this->missing()->asked(), 'No title.' );

		$this->vars = array();
		$this->assertNull( $this->missing()->asked(), 'An ordinary 404.' );
		$this->assertSame( '', $this->missing()->render(), 'Nothing is shown.' );
	}

	/**
	 * The data: the name as the rules make it, the angles, the similar pages, the right to create.
	 *
	 * @return void
	 */
	public function test_the_view(): void {
		$view = $this->missing()->view(
			array(
				'space' => 'Encyclopédie',
				'title' => 'Paris',
			)
		);

		$this->assertSame( 'Encyclopédie:Paris', $view['name'] );
		$this->assertTrue( $view['may_create'] );
		$this->assertSame(
			array(
				array(
					'name' => 'Biography:Paris',
					'url' => 'https://example.test/?p=100',
				),
				array(
					'name' => 'Opinion:Paris',
					'url' => 'https://example.test/?p=101',
				),
			),
			$view['angles'],
			'The same title in the other spaces, by space.'
		);
		$this->assertSame( array(), $view['similar'] );

		$view = $this->missing()->view(
			array(
				'space' => 'Biography',
				'title' => 'elisabeth',
			)
		);

		$this->assertSame( 'Biography:Elisabeth', $view['name'] );
		$this->assertSame( array( 'Biography:Élisabeth' ), array_column( $view['similar'], 'name' ), 'An address typed without the accent.' );
		$this->assertSame( array(), $view['angles'] );

		$this->hidden = array( 100 );
		$this->assertSame(
			array( 'Opinion:Paris' ),
			array_column(
				$this->missing()->view(
					array(
						'space' => 'Encyclopédie',
						'title' => 'Paris',
					)
				)['angles'],
				'name'
			),
			'A page that the user cannot see is not listed.'
		);

		$this->may = false;
		$this->assertFalse(
			$this->missing()->view(
				array(
					'space' => 'Encyclopédie',
					'title' => 'Paris',
				)
			)['may_create']
		);
	}

	/**
	 * A title that the rules refuse is a 404 with the reason and no offer.
	 *
	 * @return void
	 */
	public function test_a_refused_title_has_no_offer(): void {
		$view = $this->missing()->view(
			array(
				'space' => 'Biography',
				'title' => 'a|b',
			)
		);

		$this->assertSame( 'A title holds none of the characters # ? % < > [ ] { } |.', htmlspecialchars_decode( $view['refusal'] ) );
		$this->assertFalse( $view['may_create'] );

		$html = $this->missing()->render(
			array(
				'space' => 'Biography',
				'title' => 'a|b',
			)
		);

		$this->assertStringContainsString( 'wiki-missing__refusal', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/**
	 * The markup: the name, the form for a user who may create (tied to the pair), the lists; nothing that writes for the others.
	 *
	 * @return void
	 */
	public function test_the_markup(): void {
		$asked = array(
			'space' => 'Biography',
			'title' => 'rome',
		);
		$html  = $this->missing()->render( $asked );

		$this->assertStringContainsString( '<strong class="wiki-missing__name">Biography:Rome</strong>', $html );
		$this->assertStringContainsString( '<form class="wiki-missing__create" method="post" action="https://example.test/wp-admin/admin-post.php">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="action" value="wiki_create" />', $html );
		$this->assertStringContainsString( '<input type="hidden" name="wiki_space" value="Biography" />', $html );
		$this->assertStringContainsString( '<input type="hidden" name="wiki_title" value="Rome" />', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce[wiki_create:Biography:Rome]"', $html, 'Tied to the pair.' );
		$this->assertStringContainsString( 'Create this page', $html );
		$this->assertStringContainsString( '<div class="wiki-angles"><p>The same title in other spaces:</p><ul><li><a href="https://example.test/?p=102">Opinion:Rome</a></li></ul></div>', $html );
		$this->assertStringNotContainsString( 'wiki-similar', $html );

		$this->may = false;
		$html      = $this->missing()->render( $asked );

		$this->assertStringNotContainsString( '<form', $html, 'Nobody who may not create is offered a form.' );
		$this->assertStringContainsString( 'wiki-angles', $html, 'But the angles are shown.' );

		$this->vars = array(
			'wiki_space' => 'Biography',
			'wiki_title' => 'Rome',
		);
		$this->assertSame( $html, $this->missing()->render(), 'The same from the request.' );
	}

	/**
	 * Nothing is output that `wp_kses()` would strip: every tag and attribute of the markup is allowed.
	 *
	 * @return void
	 */
	public function test_the_markup_is_allowed_html(): void {
		$allowed = MissingPage::allowed_html();
		$html    = $this->missing()->render(
			array(
				'space' => 'Biography',
				'title' => 'rome',
			)
		) . $this->missing()->render(
			array(
				'space' => 'Biography',
				'title' => 'elisabeth',
			)
		);

		preg_match_all( '/<([a-z0-9]+)((?:\s+[a-z-]+="[^"]*")*)\s*\/?>/', $html, $tags, PREG_SET_ORDER );

		$this->assertNotEmpty( $tags );

		foreach ( $tags as $tag ) {
			$this->assertArrayHasKey( $tag[1], $allowed, 'Tag ' . $tag[1] );

			preg_match_all( '/\s([a-z-]+)=/', $tag[2], $attributes );

			foreach ( $attributes[1] as $attribute ) {
				$this->assertArrayHasKey( $attribute, $allowed[ $tag[1] ], $tag[1] . ' ' . $attribute );
			}
		}
	}

	/**
	 * The form creates a draft with the title of the address and lands in its editor.
	 *
	 * @return void
	 */
	public function test_creating_the_page(): void {
		$this->posted = array(
			'wiki_space' => 'biography',
			'wiki_title' => 'Sant_Llorenç',
			'_wpnonce' => 'good',
		);

		$this->action()->handle();

		$id = $this->names->resolve( 'Biography', 'Sant Llorenç' )['post'];

		$this->assertSame( array( array( 'redirect', 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit' ) ), $this->done );
		$this->assertSame( 'draft', $this->store->pages[ $id ]['status'] );
		$this->assertSame( 'Sant Llorenç', $this->store->pages[ $id ]['title'] );
		$this->assertSame( 'wiki_create:biography:Sant_Llorenç', $this->checked, 'The nonce is checked against the pair as posted.' );
	}

	/**
	 * What stops the creation: a nonce that is not valid or tied to another title, a user who may not create; nothing is made.
	 *
	 * @return void
	 */
	public function test_what_stops_the_creation(): void {
		$count = count( $this->store->pages );

		$this->posted = array(
			'wiki_space' => 'Biography',
			'wiki_title' => 'Nobody',
			'_wpnonce' => 'bad',
		);
		$this->action()->handle();

		$this->posted = array(
			'wiki_space' => 'Biography',
			'wiki_title' => 'Nobody',
		);
		$this->action()->handle();

		$this->posted = array(
			'wiki_space' => 'Biography',
			'wiki_title' => 'Nobody',
			'_wpnonce' => 'good',
		);
		$this->may    = false;
		$this->action()->handle();

		$this->assertSame(
			array(
				array( 'stop', 'This link has expired: ask for the page again.', 403 ),
				array( 'stop', 'This link has expired: ask for the page again.', 403 ),
				array( 'stop', 'You are not allowed to create this page.', 403 ),
			),
			$this->done
		);
		$this->assertCount( $count, $this->store->pages, 'No page was made.' );
	}

	/**
	 * A name taken in the meantime sends the user to that page; a title that the rules refuse, or an unknown space, stops with a message.
	 *
	 * @return void
	 */
	public function test_a_name_taken_in_the_meantime_and_the_refusals(): void {
		$this->posted = array(
			'wiki_space' => 'Biography',
			'wiki_title' => 'paris',
			'_wpnonce' => 'good',
		);
		$this->action()->handle();

		$this->posted = array(
			'wiki_space' => 'Biography',
			'wiki_title' => 'a|b',
			'_wpnonce' => 'good',
		);
		$this->action()->handle();

		$this->posted = array(
			'wiki_space' => 'Nowhere',
			'wiki_title' => 'Paris',
			'_wpnonce' => 'good',
		);
		$this->action()->handle();

		$this->assertSame( array( 'redirect', 'https://example.test/?p=100' ), $this->done[0], 'The page that has the name.' );
		$this->assertSame( 'stop', $this->done[1][0] );
		$this->assertSame( 400, $this->done[1][2] );
		$this->assertSame( array( 'stop', 'There is no such space.', 400 ), $this->done[2] );
	}

	/**
	 * The block, the template of the block themes and the template file of the classic themes.
	 *
	 * @return void
	 */
	public function test_the_presentation(): void {
		$vars   = array(
			'wiki_space' => 'Biography',
			'wiki_title' => 'Nobody',
		);
		$theme  = true;
		$make   = function () use ( &$theme ) {
			return new Presentation(
				$this->missing(),
				'/plugin/templates/missing-page.php',
				static function () use ( &$theme ) {
					return $theme;
				}
			);
		};

		$make()->register_block();
		$make()->register_template();

		$block    = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_block_type' === $call[0] ) )[0];
		$template = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_block_template' === $call[0] ) )[0];

		$this->assertSame( 'wiki/missing-page', $block[1] );
		$this->assertTrue( $block[2]['supports']['autoRegister'] );
		$this->assertSame( 'otherguise//wiki-missing', $template[1] );
		$this->assertStringContainsString( '<!-- wp:wiki/missing-page /-->', $template[2]['content'] );
		$this->assertStringContainsString( '"slug":"header"', $template[2]['content'] );
		$this->assertStringContainsString( '"slug":"footer"', $template[2]['content'] );

		$this->vars = array();
		$this->assertSame( array( '404', 'index' ), $make()->hierarchy( array( '404', 'index' ) ), 'An ordinary 404 is left alone.' );
		$this->assertSame( 'plain', $make()->template( 'plain' ) );
		$this->assertSame( '', $make()->render_block() );

		$this->vars = $vars;
		$this->assertSame( array( 'wiki-missing', '404', 'index' ), $make()->hierarchy( array( '404', 'index' ) ), 'A block theme: the template of the plugin first.' );
		$this->assertSame( 'plain', $make()->template( 'plain' ), 'A block theme is not given a file.' );
		$this->assertStringContainsString( 'This page does not exist:', $make()->render_block() );
		$this->assertSame( 'x', $make()->hierarchy( 'x' ) );

		$theme = false;
		$this->assertSame( array( '404', 'index' ), $make()->hierarchy( array( '404', 'index' ) ), 'A classic theme has no block template.' );
		$this->assertSame( '/plugin/templates/missing-page.php', $make()->template( 'plain' ), 'A classic theme: the file of the module.' );
	}
}
