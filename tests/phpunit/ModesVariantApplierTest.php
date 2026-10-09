<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the application of the variants to a template hierarchy and to template part blocks.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Template\VariantApplier;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress and no database: the variants, the theme and the kind of request are given by closures.
 *
 * @covers \Otherguise\Modes\Template\VariantApplier
 */
class ModesVariantApplierTest extends TestCase {

	/**
	 * Number of reads of the variants, by kind.
	 *
	 * @var array<string, int>
	 */
	private $reads = array();

	/**
	 * Builds an applier.
	 *
	 * @param array $templates Variants of the templates, id by id.
	 * @param array $parts     Variants of the template parts.
	 * @param bool  $front     Whether the request is a page of the site.
	 * @return VariantApplier
	 */
	private function applier( array $templates = array(), array $parts = array(), $front = true ) {
		$this->reads = array();

		return new VariantApplier(
			function ( $kind ) use ( $templates, $parts ) {
				$this->reads[ $kind ] = ( $this->reads[ $kind ] ?? 0 ) + 1;

				return 'template' === $kind ? $templates : $parts;
			},
			static fn() => 'twentytwentyfive',
			static fn() => $front
		);
	}

	/**
	 * The slug of the variant goes just before the slug of the template, with the same suffix.
	 *
	 * @return void
	 */
	public function test_the_variant_goes_before_the_template(): void {
		$applier = $this->applier( array( 'twentytwentyfive//single' => 'twentytwentyfive//single-print' ) );

		$this->assertSame(
			array( 'single-post-hello.php', 'single-post.php', 'single-print.php', 'single.php' ),
			$applier->hierarchy( array( 'single-post-hello.php', 'single-post.php', 'single.php' ) )
		);
		$this->assertSame( array( 'single-print.html', 'single.html' ), $applier->hierarchy( array( 'single.html' ) ) );
		$this->assertSame( array( 'single-print', 'single' ), $applier->hierarchy( array( 'single' ) ) );
	}

	/**
	 * A variant is declared for one template: the more specific ones are not replaced, and templates without a variant are untouched.
	 *
	 * @return void
	 */
	public function test_other_templates_are_untouched(): void {
		$applier = $this->applier( array( 'twentytwentyfive//page' => 'twentytwentyfive//page-print' ) );

		$this->assertSame( array( 'single-post.php', 'single.php', 'singular.php' ), $applier->hierarchy( array( 'single-post.php', 'single.php', 'singular.php' ) ) );
		$this->assertSame( array( 'page-print.php', 'page.php', 'index.php' ), $applier->hierarchy( array( 'page.php', 'index.php' ) ) );
	}

	/**
	 * Several templates can have the same variant, and a template can have a variant that is also in the list.
	 *
	 * @return void
	 */
	public function test_a_variant_for_several_templates_and_no_duplicate(): void {
		$applier = $this->applier(
			array(
				'twentytwentyfive//single' => 'twentytwentyfive//print',
				'twentytwentyfive//page'   => 'twentytwentyfive//print',
				'twentytwentyfive//index'  => 'twentytwentyfive//home',
			)
		);

		$this->assertSame( array( 'print.php', 'page.php' ), $applier->hierarchy( array( 'page.php' ) ) );
		$this->assertSame( array( 'home.php', 'index.php' ), $applier->hierarchy( array( 'home.php', 'index.php' ) ), 'The variant is already a candidate: it is not added again.' );
	}

	/**
	 * Relations of another theme do not apply, and neither do malformed ids.
	 *
	 * @return void
	 */
	public function test_only_the_active_theme_counts(): void {
		$applier = $this->applier(
			array(
				'othertheme//single'            => 'othertheme//single-print',
				'twentytwentyfive//page'        => 'othertheme//page-print',
				'twentytwentyfive//archive'     => 'archive-print',
				'not an id'                     => 'twentytwentyfive//x',
				'twentytwentyfive//index'       => 'twentytwentyfive//index-print',
			)
		);

		$this->assertSame( array( 'single.php', 'page.php', 'archive.php', 'index-print.php', 'index.php' ), $applier->hierarchy( array( 'single.php', 'page.php', 'archive.php', 'index.php' ) ) );
	}

	/**
	 * The variants are read once per request and kind, whatever the number of hierarchies and blocks.
	 *
	 * @return void
	 */
	public function test_the_variants_are_read_once(): void {
		$applier = $this->applier( array( 'twentytwentyfive//single' => 'twentytwentyfive//single-print' ), array( 'twentytwentyfive//header' => 'twentytwentyfive//header-print' ) );

		for ( $i = 0; $i < 5; $i++ ) {
			$applier->hierarchy( array( 'single.php' ) );
			$applier->block_data(
				array(
					'blockName' => 'core/template-part',
					'attrs' => array( 'slug' => 'header' ),
				)
			);
		}

		$this->assertSame(
			array(
				'template' => 1,
				'template_part' => 1,
			),
			$this->reads
		);
	}

	/**
	 * In the administration or in REST, nothing is replaced and nothing is read.
	 *
	 * @return void
	 */
	public function test_nothing_happens_outside_the_front(): void {
		$applier = $this->applier( array( 'twentytwentyfive//single' => 'twentytwentyfive//single-print' ), array( 'twentytwentyfive//header' => 'twentytwentyfive//header-print' ), false );
		$block   = array(
			'blockName' => 'core/template-part',
			'attrs' => array( 'slug' => 'header' ),
		);

		$this->assertSame( array( 'single.php' ), $applier->hierarchy( array( 'single.php' ) ) );
		$this->assertSame( $block, $applier->block_data( $block ) );
		$this->assertSame( array(), $this->reads );
	}

	/**
	 * Something that is not a list of candidates is returned as it is.
	 *
	 * @return void
	 */
	public function test_garbage_is_returned_as_it_is(): void {
		$applier = $this->applier( array( 'twentytwentyfive//single' => 'twentytwentyfive//single-print' ) );

		$this->assertSame( 'x', $applier->hierarchy( 'x' ) );
		$this->assertSame( array( 12, null ), $applier->hierarchy( array( 12, null ) ) );
		$this->assertSame( 'y', $applier->block_data( 'y' ) );
	}

	/**
	 * The slug of a template part block is replaced; other blocks, other themes and blocks without a slug are not.
	 *
	 * @return void
	 */
	public function test_template_part_blocks(): void {
		$applier = $this->applier( array(), array( 'twentytwentyfive//header' => 'twentytwentyfive//header-print' ) );

		$this->assertSame(
			array(
				'blockName' => 'core/template-part',
				'attrs' => array(
					'slug' => 'header-print',
					'tagName' => 'header',
				),
			),
			$applier->block_data(
				array(
					'blockName' => 'core/template-part',
					'attrs' => array(
						'slug' => 'header',
						'tagName' => 'header',
					),
				)
			)
		);
		$this->assertSame(
			'header-print',
			$applier->block_data(
				array(
					'blockName' => 'core/template-part',
					'attrs' => array(
						'slug' => 'header',
						'theme' => 'twentytwentyfive',
					),
				)
			)['attrs']['slug']
		);

		foreach ( array(
			array(
				'blockName' => 'core/template-part',
				'attrs' => array(
					'slug' => 'header',
					'theme' => 'othertheme',
				),
			),
			array(
				'blockName' => 'core/template-part',
				'attrs' => array( 'slug' => 'footer' ),
			),
			array(
				'blockName' => 'core/template-part',
				'attrs' => array(),
			),
			array(
				'blockName' => 'core/template-part',
				'attrs' => array( 'slug' => array( 'header' ) ),
			),
			array(
				'blockName' => 'core/paragraph',
				'attrs' => array( 'slug' => 'header' ),
			),
			array(
				'blockName' => null,
				'attrs' => array( 'slug' => 'header' ),
			),
			array(),
		) as $block ) {
			$this->assertSame( $block, $applier->block_data( $block ), json_encode( $block ) );
		}
	}

	/**
	 * The filters are added for every type, at the priority decided in slice 201, and for the blocks; invalid type names are skipped.
	 *
	 * @return void
	 */
	public function test_registration(): void {
		$added   = array();
		$applier = $this->applier();

		$applier->register(
			static function ( $hook, $callback, $priority, $accepted ) use ( &$added ) {
				$added[ $hook ] = array( $callback[1], $priority, $accepted );
			}
		);

		$this->assertCount( 19, $added );
		$this->assertSame( array( 'hierarchy', 90, 1 ), $added['single_template_hierarchy'] );
		$this->assertSame( array( 'hierarchy', 90, 1 ), $added['404_template_hierarchy'] );
		$this->assertSame( array( 'block_data', 10, 1 ), $added['render_block_data'] );

		$added = array();

		$applier->register(
			static function ( $hook ) use ( &$added ) {
				$added[] = $hook;
			},
			array( 'custom', 'Bad Type', 'a/b', 12, 'ok2' )
		);

		$this->assertSame( array( 'custom_template_hierarchy', 'ok2_template_hierarchy', 'render_block_data' ), $added );
	}
}
