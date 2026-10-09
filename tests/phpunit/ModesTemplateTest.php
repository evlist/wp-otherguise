<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the identifiers of templates and of the entity types `template` and `template_part`.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Template\TemplateRef;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-forbidden-wpdb.php';

/**
 * No database: the modules are wired on an object that fails when the store is reached.
 *
 * @covers \Otherguise\Modes\Integration\TemplateIntegration
 * @covers \Otherguise\Modes\Template\TemplateRef
 */
class ModesTemplateTest extends TestCase {

	/**
	 * Modules wired together.
	 *
	 * @var Otherguise_Test_Modes_Site
	 */
	private $site;

	/**
	 * Builds the site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->site = new Otherguise_Test_Modes_Site( new Otherguise_Test_Forbidden_Wpdb() );
	}

	/**
	 * Cleans the stubs.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		otherguise_test_reset();
	}

	/**
	 * Returns an entity type.
	 *
	 * @param string $slug Slug.
	 * @return \Otherguise\Triples\Entity\EntityType
	 */
	private function type( $slug ) {
		return $this->site->triples->entity_types()->get( $slug );
	}

	/**
	 * Ids: a theme, two slashes, a slug.
	 *
	 * @return void
	 */
	public function test_ids(): void {
		foreach ( array( 'twentytwentyfive//single', 'my_theme-2//page-print', 'child.theme//a.b' ) as $id ) {
			$this->assertTrue( TemplateRef::is_valid_id( $id ), $id );
		}

		foreach ( array( 'single', '//single', 'theme//', 'a//b//c', 'theme/single', 'th eme//x', "theme//x\n", 'theme//é', 12, null ) as $id ) {
			$this->assertFalse( TemplateRef::is_valid_id( $id ), var_export( $id, true ) );
		}

		$this->assertSame( 'twentytwentyfive', TemplateRef::theme( 'twentytwentyfive//single-post' ) );
		$this->assertSame( 'single-post', TemplateRef::slug( 'twentytwentyfive//single-post' ) );
	}

	/**
	 * The references are built from a slug and a theme; a bad slug is refused.
	 *
	 * @return void
	 */
	public function test_references(): void {
		$this->assertSame( 'template:twentytwentyfive//single', (string) TemplateRef::template( 'single', 'twentytwentyfive' ) );
		$this->assertSame( 'template_part:twentytwentyfive//header', (string) TemplateRef::part( 'header', 'twentytwentyfive' ) );
		$this->expectException( InvalidArgumentException::class );
		TemplateRef::template( 'a b', 'twentytwentyfive' );
	}

	/**
	 * Both types are registered, with the same shape of id.
	 *
	 * @return void
	 */
	public function test_the_types_are_registered(): void {
		$this->assertTrue( $this->type( 'template' )->is_valid_id( 'twentytwentyfive//single' ) );
		$this->assertFalse( $this->type( 'template' )->is_valid_id( 'single' ) );
		$this->assertTrue( $this->type( 'template_part' )->is_valid_id( 'twentytwentyfive//header' ) );
		$this->assertSame( 'Template part', $this->type( 'template_part' )->label() );
	}

	/**
	 * A block template object is recognized by the type that matches its kind, and only by it.
	 *
	 * @return void
	 */
	public function test_block_template_objects_are_recognized_by_one_type(): void {
		$template = $this->site->template( 'single' );
		$part     = $this->site->template( 'header', 'wp_template_part' );

		$this->assertSame( 'twentytwentyfive//single', $this->type( 'template' )->identify( $template ) );
		$this->assertNull( $this->type( 'template_part' )->identify( $template ) );
		$this->assertSame( 'twentytwentyfive//header', $this->type( 'template_part' )->identify( $part ) );
		$this->assertNull( $this->type( 'template' )->identify( $part ) );
		$this->assertNull( $this->type( 'template' )->identify( 'twentytwentyfive//single' ) );
		$this->assertSame( 'template:twentytwentyfive//single', (string) $this->site->statements->entity( $template ) );
		$this->assertSame( 'template_part:twentytwentyfive//header', (string) $this->site->statements->entity( $part ) );
	}

	/**
	 * A template exists when WordPress finds a block template of the right kind, or when the active theme has the PHP file.
	 *
	 * @return void
	 */
	public function test_existence(): void {
		$this->site->template( 'single' );
		$this->site->template( 'header', 'wp_template_part' );
		$this->site->lookup->php[] = 'index';

		$this->assertTrue( $this->type( 'template' )->exists( 'twentytwentyfive//single' ) );
		$this->assertFalse( $this->type( 'template' )->exists( 'twentytwentyfive//page' ) );
		$this->assertFalse( $this->type( 'template' )->exists( 'twentytwentyfive//header' ), 'A part is not a template.' );
		$this->assertTrue( $this->type( 'template_part' )->exists( 'twentytwentyfive//header' ) );
		$this->assertFalse( $this->type( 'template_part' )->exists( 'twentytwentyfive//single' ) );
		$this->assertTrue( $this->type( 'template' )->exists( 'twentytwentyfive//index' ), 'The PHP file of a classic theme.' );
		$this->assertFalse( $this->type( 'template' )->exists( 'othertheme//index' ), 'The PHP file of another theme is not this one.' );
		$this->assertFalse( $this->type( 'template_part' )->exists( 'twentytwentyfive//index' ), 'Parts have no PHP fallback.' );
	}

	/**
	 * What the screens show: the title and the link of the editor, or the slug when WordPress has no block template.
	 *
	 * @return void
	 */
	public function test_descriptions(): void {
		$this->site->template( 'single', 'wp_template', 'Single Posts' );
		$this->site->template( 'page' );

		$this->assertSame(
			array(
				'label' => 'Single Posts',
				'url'   => 'editor?wp_template=twentytwentyfive//single',
			),
			$this->type( 'template' )->describe( 'twentytwentyfive//single' )
		);
		$this->assertSame( 'page', $this->type( 'template' )->describe( 'twentytwentyfive//page' )['label'], 'No title: the slug.' );
		$this->assertSame(
			array(
				'label' => 'index',
				'url'   => null,
			),
			$this->type( 'template' )->describe( 'twentytwentyfive//index' )
		);
		$this->assertNull( $this->type( 'template' )->describe( 'not an id' ) );
	}

	/**
	 * The loader gives the object back.
	 *
	 * @return void
	 */
	public function test_loading(): void {
		$template = $this->site->template( 'single' );

		$this->assertSame( $template, $this->type( 'template' )->load( 'twentytwentyfive//single' ) );
		$this->assertNull( $this->type( 'template' )->load( 'twentytwentyfive//missing' ) );
	}

	/**
	 * The predicates are registered with the mode as qualifier, for templates and for parts only.
	 *
	 * @return void
	 */
	public function test_the_predicates(): void {
		$predicates = $this->site->triples->predicates();

		$this->assertSame( array( 'template' ), $predicates->get( 'modes/has-variant' )->subject_types() );
		$this->assertSame( array( 'template' ), $predicates->get( 'modes/has-variant' )->object_types() );
		$this->assertSame( array( 'template_part' ), $predicates->get( 'modes/has-part-variant' )->subject_types() );
		$this->assertTrue( $predicates->can_qualify( 'modes/mode', 'modes/has-variant' ) );
		$this->assertTrue( $predicates->can_qualify( 'modes/mode', 'modes/has-part-variant' ) );
	}
}
