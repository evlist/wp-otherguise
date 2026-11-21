<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the rules of a branch (slice 500).
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Editor\RulesPanel;
use Otherguise\Wiki\Rest\RulesRoutes;
use Otherguise\Wiki\Tree\Branch;
use Otherguise\Wiki\Tree\Rules;
use Otherguise\Wiki\Tree\RulesMeta;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: stubs and doubles.
 *
 * @covers \Otherguise\Wiki\Tree\Rules
 * @covers \Otherguise\Wiki\Tree\Branch
 * @covers \Otherguise\Wiki\Tree\RulesMeta
 * @covers \Otherguise\Wiki\Rest\RulesRoutes
 * @covers \Otherguise\Wiki\Editor\RulesPanel
 */
class WikiRulesTest extends TestCase {

	/**
	 * What is stored on the pages: Biography (1) with 1958-1968 (2) with Chapitre (3); Opinion (4) with Paris (5).
	 *
	 * @var array<int, mixed>
	 */
	private $stored = array();

	/**
	 * The parents: page => parent.
	 *
	 * @var array<int, int>
	 */
	private $parents = array(
		1 => 0,
		2 => 1,
		3 => 2,
		4 => 0,
		5 => 4,
	);

	/**
	 * Starts with rules on Biography and on 1958-1968.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();

		$this->stored = array(
			1 => array(
				'template' => 'page-bio',
				'class' => 'space-bio',
				'status' => 'Fact, source, interpretation',
			),
			2 => array(
				'class' => 'book',
				'status' => 'A book',
			),
		);
	}

	/**
	 * The rules, over the pages of the test.
	 *
	 * @return Rules
	 */
	private function rules(): Rules {
		return new Rules( fn( $id ) => $this->stored[ $id ] ?? '', fn( $id ) => $this->parents[ $id ] ?? 0 );
	}

	/**
	 * A value that was typed or sent is cleaned: the known keys, each cleaned, what is empty left out.
	 *
	 * @return void
	 */
	public function test_cleaning(): void {
		$this->assertSame(
			array(
				'template' => 'page-wide/x',
				'class' => 'a b-c d_e script',
				'status' => 'A line',
			),
			Rules::clean(
				array(
					'template' => ' Page-Wide/X! ',
					'class' => " a  b-c\td_e <script> ",
					'status' => ' A line ',
					'other' => 'dropped',
				)
			),
			'Slug in lower case, classes without signs, a text trimmed (the markup is stripped by WordPress itself: tried on a real site).'
		);
		$this->assertSame(
			array(),
			Rules::clean(
				array(
					'template' => '',
					'class' => '   ',
					'status' => '',
				)
			)
		);
		$this->assertSame( array(), Rules::clean( 'not an array' ) );
		$this->assertSame(
			array( 'class' => 'scriptalert1script' ),
			Rules::clean(
				array(
					'class' => '<script>alert(1)</script>',
					'template' => 12,
				)
			),
			'A value that is not a string is left out.'
		);
	}

	/**
	 * What a page sets itself.
	 *
	 * @return void
	 */
	public function test_own_rules(): void {
		$this->assertSame(
			array(
				'class' => 'book',
				'status' => 'A book',
			),
			$this->rules()->own( 2 )
		);
		$this->assertSame( array(), $this->rules()->own( 3 ), 'Nothing stored.' );
		$this->assertSame( array(), $this->rules()->own( 99 ) );
	}

	/**
	 * The effective rule of a page, key by key, is the one of the nearest page, itself included, with the page that sets it.
	 *
	 * @return void
	 */
	public function test_the_nearest_page_wins_key_by_key(): void {
		$this->assertSame(
			array(
				'class'    => array(
					'value' => 'book',
					'from' => 2,
				),
				'status'   => array(
					'value' => 'A book',
					'from' => 2,
				),
				'template' => array(
					'value' => 'page-bio',
					'from' => 1,
				),
			),
			$this->rules()->effective( 3 ),
			'The chapter sets nothing: the book sets the class and the status, Biography the template.'
		);
		$this->assertSame( 'space-bio', $this->rules()->value( 1, 'class' ) );
		$this->assertSame( 'page-bio', $this->rules()->value( 2, 'template' ) );
		$this->assertSame( 'book', $this->rules()->value( 2, 'class' ), 'A page overrides its parent.' );
		$this->assertSame( '', $this->rules()->value( 5, 'template' ), 'Another branch.' );
		$this->assertSame( array(), $this->rules()->effective( 5 ) );
		$this->assertSame( '', $this->rules()->value( 3, 'unknown' ) );
	}

	/**
	 * A loop of parents stops; the answer is kept for the request until it is forgotten.
	 *
	 * @return void
	 */
	public function test_a_loop_stops_and_the_answer_is_kept(): void {
		$this->parents = array(
			1 => 2,
			2 => 1,
		);
		$this->stored  = array( 1 => array( 'status' => 'Loop' ) );

		$this->assertSame( 'Loop', $this->rules()->value( 2, 'status' ) );

		$this->parents = array(
			1 => 0,
			2 => 1,
			3 => 2,
		);
		$rules         = $this->rules();

		$this->assertSame( 'Loop', $rules->value( 3, 'status' ) );

		$this->stored = array();
		$this->assertSame( 'Loop', $rules->value( 3, 'status' ), 'Kept for the request.' );

		$rules->forget();
		$this->assertSame( '', $rules->value( 3, 'status' ) );
	}

	/**
	 * The classes of the branch go to the body of a page; nothing for what is not a page.
	 *
	 * @return void
	 */
	public function test_the_body_classes(): void {
		$page = new Branch( $this->rules(), static fn() => 3 );

		$this->assertSame( array( 'page', 'book' ), $page->body_class( array( 'page' ) ) );
		$this->assertSame( array( 'page' ), ( new Branch( $this->rules(), static fn() => 5 ) )->body_class( array( 'page' ) ), 'A branch with no class.' );
		$this->assertSame( array( 'home' ), ( new Branch( $this->rules(), static fn() => 0 ) )->body_class( array( 'home' ) ) );
		$this->assertSame( 'x', $page->body_class( 'x' ) );
	}

	/**
	 * The template of the branch comes first in the hierarchy, unless the page chose its own.
	 *
	 * @return void
	 */
	public function test_the_template_hierarchy(): void {
		$own   = '';
		$page  = static fn() => 3;
		$make  = function () use ( &$own, $page ) {
			return new Branch(
				$this->rules(),
				$page,
				static function () use ( &$own ) {
					return $own;
				}
			);
		};
		$hier  = array( 'page-chapitre.php', 'page.php', 'singular.php' );

		$this->assertSame( array_merge( array( 'page-bio.php' ), $hier ), $make()->templates( $hier ) );

		$own = 'default';
		$this->assertSame( array_merge( array( 'page-bio.php' ), $hier ), $make()->templates( $hier ), 'The default template is no choice.' );

		$own = 'page-wide';
		$this->assertSame( $hier, $make()->templates( $hier ), 'The page chose its own.' );

		$own = '';
		$this->assertSame( $hier, ( new Branch( $this->rules(), static fn() => 5 ) )->templates( $hier ), 'A branch with no template.' );
		$this->assertSame( $hier, ( new Branch( $this->rules(), static fn() => 0 ) )->templates( $hier ), 'Not a page.' );
		$this->assertSame( 'x', $make()->templates( 'x' ) );
	}

	/**
	 * The status line of the block: the effective one, nothing when there is none.
	 *
	 * @return void
	 */
	public function test_the_status_block(): void {
		$this->assertSame( '<p class="wiki-branch-status">A book</p>', ( new Branch( $this->rules(), static fn() => 3 ) )->render_status() );
		$this->assertSame( '', ( new Branch( $this->rules(), static fn() => 5 ) )->render_status() );
		$this->assertSame( '', ( new Branch( $this->rules(), static fn() => 0 ) )->render_status() );

		$this->stored[1]['status'] = 'a & c';
		$this->assertSame( '<p class="wiki-branch-status">a &amp; c</p>', ( new Branch( $this->rules(), static fn() => 1 ) )->render_status(), 'Escaped.' );

		( new Branch( $this->rules() ) )->register_block();

		$block = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_block_type' === $call[0] ) )[0];

		$this->assertSame( 'wiki/branch-status', $block[1] );
		$this->assertTrue( $block[2]['supports']['autoRegister'] );
	}

	/**
	 * The meta is registered with a schema, the sanitizing and a capability; the capability can be changed.
	 *
	 * @return void
	 */
	public function test_the_meta(): void {
		( new RulesMeta() )->register();

		$meta = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_post_meta' === $call[0] ) )[0];

		$this->assertSame( array( 'page', 'wiki_rules' ), array( $meta[1], $meta[2] ) );
		$this->assertSame( 'object', $meta[3]['type'] );
		$this->assertSame( array( 'template', 'class', 'status' ), array_keys( $meta[3]['show_in_rest']['schema']['properties'] ) );
		$this->assertFalse( $meta[3]['show_in_rest']['schema']['additionalProperties'] );
		$this->assertSame( array( Rules::class, 'clean' ), $meta[3]['sanitize_callback'] );
		$this->assertTrue( $meta[3]['auth_callback']( false, 'wiki_rules', 3 ), 'The stub lets the user.' );

		$this->assertSame( 'edit_others_pages', RulesMeta::capability() );

		$GLOBALS['otherguise_test_filters']['wiki_rules_capability'] = 'manage_options';
		$this->assertSame( 'manage_options', RulesMeta::capability() );

		$GLOBALS['otherguise_test_filters']['wiki_rules_capability'] = '';
		$this->assertSame( 'edit_others_pages', RulesMeta::capability() );
	}

	/**
	 * The route: the rules of a page and its effective rules with the page that sets each, for someone who may edit the page.
	 *
	 * @return void
	 */
	public function test_the_route(): void {
		$routes = new RulesRoutes( $this->rules(), static fn( $id ) => 3 === $id || 1 === $id, static fn( $id ) => 'Title ' . $id );

		$this->assertTrue( $routes->can( array( 'id' => '3' ) ) );
		$this->assertFalse( $routes->can( array( 'id' => '4' ) ) );

		$this->assertSame(
			array(
				'id'        => 3,
				'own'       => array(),
				'effective' => array(
					'class'    => array(
						'value' => 'book',
						'from' => 2,
						'title' => 'Title 2',
						'own' => false,
					),
					'status'   => array(
						'value' => 'A book',
						'from' => 2,
						'title' => 'Title 2',
						'own' => false,
					),
					'template' => array(
						'value' => 'page-bio',
						'from' => 1,
						'title' => 'Title 1',
						'own' => false,
					),
				),
			),
			$routes->state( array( 'id' => '3' ) )
		);
		$this->assertTrue( $routes->state( array( 'id' => 1 ) )['effective']['template']['own'], 'Set on this page.' );

		$routes->register();

		$route = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_rest_route' === $call[0] ) )[0];

		$this->assertSame( array( 'wiki/v1', '/rules/(?P<id>[0-9]+)' ), array( $route[1], $route[2] ) );
		$this->assertSame( 'GET', $route[3]['methods'] );
	}

	/**
	 * The script and the settings are loaded for a page that the user may edit; read only for someone who may not change the rules.
	 *
	 * @return void
	 */
	public function test_the_panel_loader(): void {
		$panel = static function ( $type, $id, $edit, $rules ) {
			return new RulesPanel(
				static fn( $file ) => 'https://example.test/rules/' . $file,
				static fn() => array(
					'id' => $id,
					'type' => $type,
				),
				static fn( $capability, $post = 0 ) => 'edit_post' === $capability ? $edit && 12 === $post : $rules
			);
		};

		$panel( 'post', 12, true, true )->enqueue();
		$panel( 'page', 0, true, true )->enqueue();
		$panel( 'page', 12, false, true )->enqueue();

		$this->assertSame( array(), array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_enqueue_script' === $call[0] ) );

		$panel( 'page', 12, true, false )->enqueue();

		$scripts = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_enqueue_script' === $call[0] ) );
		$inline  = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_add_inline_script' === $call[0] ) );

		$this->assertSame( 'https://example.test/rules/rules.js', $scripts[0][2] );
		$this->assertContains( 'wp-api-fetch', $scripts[0][3] );
		$this->assertSame( 'window.wikiRules = {"postType":"page","path":"\/wiki\/v1\/rules","keys":["template","class","status"],"canEdit":false};', $inline[0][2] );

		unset( $GLOBALS['post'] );
		( new RulesPanel( static fn( $file ) => $file ) )->enqueue();
		$GLOBALS['post'] = new WP_Post( 31, 'page' );
		( new RulesPanel( static fn( $file ) => $file ) )->enqueue();
		unset( $GLOBALS['post'] );

		$this->assertCount( 2, array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_enqueue_script' === $call[0] ), 'The default reader finds the global post.' );
	}
}
