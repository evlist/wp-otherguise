<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the template that new posts start with.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Admin\DefaultTemplatesScreen;
use Otherguise\Modes\Settings\DefaultTemplates;
use Otherguise\Modes\Template\NewPostTemplate;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-template-lookup.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-environment.php';

/**
 * The setting, the hook and the section of the screen. No database: the options are the stubs of the tests, the post meta are arrays.
 *
 * @covers \Otherguise\Modes\Settings\DefaultTemplates
 * @covers \Otherguise\Modes\Template\NewPostTemplate
 * @covers \Otherguise\Modes\Admin\DefaultTemplatesScreen
 */
class ModesDefaultTemplatesTest extends TestCase {

	/**
	 * Lookup of templates.
	 *
	 * @var Otherguise_Test_Template_Lookup
	 */
	private $lookup;

	/**
	 * Post meta written by the hook, by post id.
	 *
	 * @var array<int, string>
	 */
	private $meta = array();

	/**
	 * Starts without options, with two templates in the theme.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();

		$this->meta   = array();
		$this->lookup = new Otherguise_Test_Template_Lookup();

		foreach ( array(
			'publication-randonnee' => 'Publication randonnée',
			'single' => '',
		) as $slug => $title ) {
			$this->lookup->templates[ 'wp_template|twentytwentyfive//' . $slug ] = new WP_Block_Template( 'twentytwentyfive//' . $slug, 'wp_template', $title );
		}
	}

	/**
	 * Cleans the options.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		otherguise_test_reset();
	}

	/**
	 * Builds the hook on the array of the post meta.
	 *
	 * @return NewPostTemplate
	 */
	private function hook() {
		return new NewPostTemplate(
			new DefaultTemplates( $this->lookup ),
			fn( $id ) => $this->meta[ $id ] ?? '',
			function ( $id, $key, $value ) {
				$this->meta[ $id ] = $value;
			}
		);
	}

	/**
	 * Builds a post.
	 *
	 * @param string $status Status.
	 * @param string $type   Type.
	 * @return object
	 */
	private function post( $status = 'auto-draft', $type = 'post' ) {
		return (object) array(
			'post_status' => $status,
			'post_type'   => $type,
		);
	}

	/**
	 * Nothing is chosen at first, whatever the option holds.
	 *
	 * @return void
	 */
	public function test_nothing_by_default(): void {
		$settings = new DefaultTemplates( $this->lookup );

		$this->assertSame( array(), $settings->all() );
		$this->assertSame( '', $settings->for_type( 'post' ) );

		update_option( DefaultTemplates::OPTION, 'garbage' );
		$this->assertSame( array(), $settings->all() );

		update_option(
			DefaultTemplates::OPTION,
			array(
				'post' => 'a/b',
				'page' => 7,
				3 => 'single',
				'book' => 'single',
			)
		);
		$this->assertSame( array( 'book' => 'single' ), $settings->all(), 'Only well-formed entries; the types are checked when it is saved.' );
	}

	/**
	 * The slug must be the one of a template of the active theme: a block template, or a file of a classic theme.
	 *
	 * @return void
	 */
	public function test_exists(): void {
		$settings = new DefaultTemplates( $this->lookup );

		$this->assertTrue( $settings->exists( 'publication-randonnee' ) );
		$this->assertFalse( $settings->exists( 'gone' ) );
		$this->assertFalse( $settings->exists( '../single' ) );
		$this->assertFalse( $settings->exists( '' ) );

		$this->lookup->php = array( 'single-classic' );
		$this->assertTrue( $settings->exists( 'single-classic' ) );
	}

	/**
	 * Saving keeps the known types with an existing template and drops everything else, the empty choice included.
	 *
	 * @return void
	 */
	public function test_sanitize(): void {
		$settings = new DefaultTemplates( $this->lookup );

		$this->assertSame(
			array( 'post' => 'publication-randonnee' ),
			$settings->sanitize(
				array(
					'post'    => 'publication-randonnee',
					'page'    => '',
					'book'    => 'single',
					'attachment' => 'single',
					'post2'   => 'gone',
					0         => 'single',
				)
			)
		);
		$this->assertSame(
			array(),
			$settings->sanitize(
				array(
					'post' => 'gone',
					'page' => array( 'single' ),
				)
			)
		);
		$this->assertSame( array(), $settings->sanitize( 'nonsense' ) );
	}

	/**
	 * An auto-draft of a type that has a template gets it.
	 *
	 * @return void
	 */
	public function test_an_auto_draft_gets_the_template(): void {
		update_option( DefaultTemplates::OPTION, array( 'post' => 'publication-randonnee' ) );

		$this->hook()->apply( 12, $this->post(), false );

		$this->assertSame( array( 12 => 'publication-randonnee' ), $this->meta );
	}

	/**
	 * Updates, other statuses, other types, a post that has a template, a template that is gone, no setting: nothing.
	 *
	 * @return void
	 */
	public function test_nothing_otherwise(): void {
		update_option( DefaultTemplates::OPTION, array( 'post' => 'publication-randonnee' ) );

		$this->hook()->apply( 1, $this->post(), true );
		$this->hook()->apply( 2, $this->post( 'draft' ), false );
		$this->hook()->apply( 3, $this->post( 'publish' ), false );
		$this->hook()->apply( 4, $this->post( 'auto-draft', 'page' ), false );
		$this->hook()->apply( 5, 'not a post', false );
		$this->assertSame( array(), $this->meta );

		$this->meta[6] = 'single';
		$this->hook()->apply( 6, $this->post(), false );
		$this->assertSame( 'single', $this->meta[6], 'A template that is already there is kept.' );

		update_option( DefaultTemplates::OPTION, array( 'post' => 'gone' ) );
		$this->hook()->apply( 7, $this->post(), false );
		$this->assertArrayNotHasKey( 7, $this->meta, 'A template that is gone is ignored.' );

		otherguise_test_reset();
		$this->hook()->apply( 8, $this->post(), false );
		$this->assertArrayNotHasKey( 8, $this->meta, 'No setting, nothing.' );
	}

	/**
	 * The section: one list per type with the default template first, the chosen one selected, the Settings API fields, escaping.
	 *
	 * @return void
	 */
	public function test_the_section(): void {
		$this->lookup->templates['wp_template|twentytwentyfive//evil'] = new WP_Block_Template( 'twentytwentyfive//evil', 'wp_template', '<script>x</script>' );
		update_option( DefaultTemplates::OPTION, array( 'post' => 'publication-randonnee' ) );

		$screen = new DefaultTemplatesScreen( new Otherguise_Test_Modes_Environment(), new DefaultTemplates( $this->lookup ), $this->lookup );

		ob_start();
		$screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h2>Template of new posts</h2>', $html );
		$this->assertStringContainsString( 'name="option_page" value="modes_default_templates_group"', $html );
		$this->assertStringContainsString( 'name="modes_default_templates[post]"', $html );
		$this->assertStringContainsString( 'name="modes_default_templates[page]"', $html );
		$this->assertSame( 2, substr_count( $html, '<option value="">— Default template —</option>' ) );
		$this->assertStringContainsString( '<option value="publication-randonnee" selected="selected">Publication randonnée (publication-randonnee)</option>', $html );
		$this->assertSame( 1, substr_count( $html, 'selected="selected"' ) );
		$this->assertStringContainsString( '&lt;script&gt;x&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'does not depend on the modes', $html );
		$this->assertSame( 'edit_theme_options', $screen->capability() );
	}
}
