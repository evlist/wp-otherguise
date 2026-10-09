<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the application of the declared variants in the mode of the request, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Module;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';

/**
 * The variants stored through the service are what the applier puts in the hierarchy and in the blocks, for the mode of the request.
 *
 * @covers \Otherguise\Modes\Module
 * @covers \Otherguise\Modes\Template\VariantApplier
 */
class ModesApplyDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Builds a site whose request has a query string, with the templates and relations of the examples.
	 *
	 * @param array $query Query string.
	 * @return Otherguise_Test_Modes_Site
	 */
	private function site( array $query ) {
		$site = new Otherguise_Test_Modes_Site( $this->wpdb, $query );

		foreach ( array( 'single', 'single-print', 'page', 'page-web' ) as $slug ) {
			$site->template( $slug );
		}

		foreach ( array( 'header', 'header-print' ) as $slug ) {
			$site->template( $slug, 'wp_template_part' );
		}

		$variants = $site->modes->variants();
		$print    = $site->modes->modes()->get( 'print' );
		$web      = $site->modes->modes()->get( 'web' );

		$variants->declare( $variants->template( 'single' ), $print, $variants->template( 'single-print' ) );
		$variants->declare( $variants->template( 'page' ), $web, $variants->template( 'page-web' ) );
		$variants->declare( $variants->part( 'header' ), $print, $variants->part( 'header-print' ) );

		return $site;
	}

	/**
	 * Builds the block of a template part.
	 *
	 * @param string $slug Slug.
	 * @return array
	 */
	private function part( $slug ) {
		return array(
			'blockName' => 'core/template-part',
			'attrs'     => array( 'slug' => $slug ),
		);
	}

	/**
	 * In print mode, the print variants are used.
	 *
	 * @return void
	 */
	public function test_print_mode(): void {
		$applier = $this->site( array( 'print' => '' ) )->modes->applier();

		$this->assertSame( array( 'single-print.php', 'single.php' ), $applier->hierarchy( array( 'single.php' ) ) );
		$this->assertSame( array( 'page.php' ), $applier->hierarchy( array( 'page.php' ) ), 'The variant of page is for the web mode.' );
		$this->assertSame( 'header-print', $applier->block_data( $this->part( 'header' ) )['attrs']['slug'] );
	}

	/**
	 * Without a mode the request is in the default mode, and its variants apply: the web ones.
	 *
	 * @return void
	 */
	public function test_default_mode(): void {
		$applier = $this->site( array() )->modes->applier();

		$this->assertSame( array( 'single.php' ), $applier->hierarchy( array( 'single.php' ) ) );
		$this->assertSame( array( 'page-web.php', 'page.php' ), $applier->hierarchy( array( 'page.php' ) ) );
		$this->assertSame( 'header', $applier->block_data( $this->part( 'header' ) )['attrs']['slug'] );
	}

	/**
	 * Withdrawing a variant takes effect for the next request.
	 *
	 * @return void
	 */
	public function test_a_withdrawn_variant_no_longer_applies(): void {
		$site     = $this->site( array() );
		$variants = $site->modes->variants();
		$variants->withdraw( $variants->template( 'page' ), $site->modes->modes()->get( 'web' ), $variants->template( 'page-web' ) );

		$fresh = new Module( static function () {}, static function () {}, static fn() => array(), null, fn() => $site->statements, $site->lookup, static fn() => true );

		$this->assertSame( array( 'page.php' ), $fresh->applier()->hierarchy( array( 'page.php' ) ) );
	}

	/**
	 * Without the Triples module nothing happens.
	 *
	 * @return void
	 */
	public function test_without_triples_nothing_happens(): void {
		$module = new Module( static function () {}, static function () {}, static fn() => array( 'print' => '' ), null, static fn() => null, new Otherguise_Test_Template_Lookup(), static fn() => true );

		$this->assertSame( array( 'single.php' ), $module->applier()->hierarchy( array( 'single.php' ) ) );
		$this->assertSame( array(), $module->variants_of_the_request( 'template' ) );
	}

	/**
	 * Registering the filters adds the types of core and those of the filter `modes_template_types`.
	 *
	 * @return void
	 */
	public function test_registration_of_the_filters(): void {
		$hooks  = array();
		$module = new Module(
			static function () {},
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
			},
			static fn() => array(),
			static fn( $hook, $value ) => 'modes_template_types' === $hook ? array_merge( $value, array( 'custom' ) ) : $value
		);

		$module->register_variant_filters();

		$this->assertContains( 'single_template_hierarchy', $hooks );
		$this->assertContains( 'custom_template_hierarchy', $hooks );
		$this->assertContains( 'render_block_data', $hooks );
		$this->assertCount( 20, $hooks );
	}

	/**
	 * The variants are read with two queries per kind, and once per request.
	 *
	 * @return void
	 */
	public function test_the_cost_of_a_request(): void {
		$site   = $this->site( array( 'print' => '' ) );
		$before = count( $this->wpdb->queries );

		for ( $i = 0; $i < 10; $i++ ) {
			$site->modes->applier()->hierarchy( array( 'single.php' ) );
			$site->modes->applier()->block_data( $this->part( 'header' ) );
		}

		$this->assertLessThanOrEqual( 4, count( $this->wpdb->queries ) - $before );
	}
}
