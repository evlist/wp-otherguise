<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the variants of templates and template parts, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Mode\ModeDefinition;
use Otherguise\Modes\Variant\VariantException;
use Otherguise\Modes\Variant\Variants;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Storage\Statement;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';

/**
 * Declare, withdraw, remove and read the variants.
 *
 * @covers \Otherguise\Modes\Variant\Variants
 * @covers \Otherguise\Modes\Variant\VariantException
 * @covers \Otherguise\Modes\Module
 */
class ModesVariantsDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Modules wired together.
	 *
	 * @var Otherguise_Test_Modes_Site
	 */
	private $site;

	/**
	 * Variants.
	 *
	 * @var Variants
	 */
	private $variants;

	/**
	 * Builds the site, with templates `single`, `page`, `single-print`, `page-print`, parts `header` and `header-print`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->site = new Otherguise_Test_Modes_Site( $this->wpdb );

		foreach ( array( 'single', 'page', 'single-print', 'page-print', 'single-book' ) as $slug ) {
			$this->site->template( $slug );
		}

		$this->site->template( 'header', 'wp_template_part' );
		$this->site->template( 'header-print', 'wp_template_part' );

		$this->variants = $this->site->modes->variants();
	}

	/**
	 * Returns a mode.
	 *
	 * @param string $slug Slug.
	 * @return \Otherguise\Modes\Mode\ModeDefinition
	 */
	private function mode( $slug ) {
		return $this->site->modes->modes()->get( $slug );
	}

	/**
	 * Returns the code of the refusal of a call.
	 *
	 * @param callable $call Call.
	 * @return string|null
	 */
	private function refused( $call ) {
		try {
			$call();
		} catch ( VariantException $problem ) {
			return $problem->error_code();
		} catch ( \Otherguise\Triples\Service\InvalidStatementException $problem ) {
			return 'triples:' . $problem->error_code();
		}

		return null;
	}

	/**
	 * Declaring stores the relation and what says in which mode, from objects or references, and is idempotent.
	 *
	 * @return void
	 */
	public function test_declare(): void {
		$link = $this->variants->declare( $this->variants->template( 'single' ), $this->mode( 'print' ), $this->variants->template( 'single-print' ) );

		$this->assertSame( 'template:twentytwentyfive//single', (string) $link->subject() );
		$this->assertSame( 'modes/has-variant', $link->predicate() );
		$this->assertSame( 'template:twentytwentyfive//single-print', (string) $link->object() );

		$modes = $this->site->statements->match( $link, 'modes/mode' );

		$this->assertSame( array( 'mode:print' ), array_map( static fn( Statement $statement ) => (string) $statement->object(), $modes ) );

		$again = $this->variants->declare( $this->site->lookup->templates['wp_template|twentytwentyfive//single'], new EntityRef( 'mode', 'print' ), $this->site->lookup->templates['wp_template|twentytwentyfive//single-print'] );

		$this->assertSame( $link->id(), $again->id() );
		$this->assertCount( 2, $this->site->statements->match() );
	}

	/**
	 * The same relation can serve several modes; a variant can serve several templates; a template has several variants in several modes.
	 *
	 * @return void
	 */
	public function test_several_modes_and_templates(): void {
		$single = $this->variants->template( 'single' );
		$page   = $this->variants->template( 'page' );
		$print  = $this->variants->template( 'single-print' );

		$link = $this->variants->declare( $single, $this->mode( 'print' ), $print );
		$this->assertSame( $link->id(), $this->variants->declare( $single, $this->mode( 'web' ), $print )->id(), 'One relation, two modes.' );
		$this->variants->declare( $page, $this->mode( 'print' ), $print );
		$this->site->modes->modes()->register( new ModeDefinition( 'book', 'Book' ) );
		$this->variants->declare( $single, $this->mode( 'book' ), $this->variants->template( 'single-book' ) );

		$this->assertSame( 'twentytwentyfive//single-print', $this->variants->variant_of( $single, $this->mode( 'print' ) )->id() );
		$this->assertSame( 'twentytwentyfive//single-book', $this->variants->variant_of( $single, $this->mode( 'book' ) )->id() );
		$this->assertSame( 'twentytwentyfive//single-print', $this->variants->variant_of( $page, $this->mode( 'print' ) )->id() );
		$this->assertNull( $this->variants->variant_of( $page, $this->mode( 'web' ) ) );
	}

	/**
	 * The map has every variant of a mode in two reads.
	 *
	 * @return void
	 */
	public function test_the_map_of_a_mode(): void {
		$this->variants->declare( $this->variants->template( 'single' ), $this->mode( 'print' ), $this->variants->template( 'single-print' ) );
		$this->variants->declare( $this->variants->template( 'page' ), $this->mode( 'print' ), $this->variants->template( 'page-print' ) );
		$this->variants->declare( $this->variants->template( 'page' ), $this->mode( 'web' ), $this->variants->template( 'single-print' ) );
		$this->variants->declare( $this->variants->part( 'header' ), $this->mode( 'print' ), $this->variants->part( 'header-print' ) );

		$before = count( $this->wpdb->queries );
		$map    = $this->variants->map( $this->mode( 'print' ) );
		$reads  = count( $this->wpdb->queries ) - $before;

		$this->assertSame(
			array(
				'twentytwentyfive//single' => 'twentytwentyfive//single-print',
				'twentytwentyfive//page'   => 'twentytwentyfive//page-print',
			),
			$map
		);
		$this->assertLessThanOrEqual( 2, $reads );
		$this->assertSame( array( 'twentytwentyfive//header' => 'twentytwentyfive//header-print' ), $this->variants->map( $this->mode( 'print' ), 'template_part' ) );
		$this->assertSame( array( 'twentytwentyfive//page' => 'twentytwentyfive//single-print' ), $this->variants->map( $this->mode( 'web' ) ) );
		$this->assertSame( array(), $this->variants->map( new EntityRef( 'mode', 'web' ), 'template_part' ) );
	}

	/**
	 * Template parts have their own predicate.
	 *
	 * @return void
	 */
	public function test_template_parts(): void {
		$link = $this->variants->declare( $this->variants->part( 'header' ), $this->mode( 'print' ), $this->variants->part( 'header-print' ) );

		$this->assertSame( 'modes/has-part-variant', $link->predicate() );
		$this->assertSame( 'twentytwentyfive//header-print', $this->variants->variant_of( $this->variants->part( 'header' ), $this->mode( 'print' ) )->id() );
	}

	/**
	 * The rules of the variants.
	 *
	 * @return void
	 */
	public function test_the_rules(): void {
		$single = $this->variants->template( 'single' );
		$print  = $this->variants->template( 'single-print' );

		$this->assertSame( 'same_template', $this->refused( fn() => $this->variants->declare( $single, $this->mode( 'print' ), $single ) ) );
		$this->assertSame( 'kind_mismatch', $this->refused( fn() => $this->variants->declare( $single, $this->mode( 'print' ), $this->variants->part( 'header' ) ) ) );
		$this->assertSame( 'not_a_template', $this->refused( fn() => $this->variants->declare( new EntityRef( 'post', '12' ), $this->mode( 'print' ), $print ) ) );
		$this->assertSame( 'not_a_mode', $this->refused( fn() => $this->variants->declare( $single, $single, $print ) ) );
		$this->assertSame( 'theme_mismatch', $this->refused( fn() => $this->variants->declare( $single, $this->mode( 'print' ), new EntityRef( 'template', 'othertheme//single-print' ) ) ), 'The variant must be of the same theme; a template that does not exist is refused later.' );
		$this->assertSame( 'triples:unknown_entity', $this->refused( fn() => $this->variants->declare( 'single', $this->mode( 'print' ), $print ) ) );
		$this->assertSame( 'triples:object_missing', $this->refused( fn() => $this->variants->declare( $single, $this->mode( 'print' ), $this->variants->template( 'ghost' ) ) ), 'A variant that does not exist.' );
		$this->assertSame( 'triples:subject_missing', $this->refused( fn() => $this->variants->declare( $this->variants->template( 'ghost' ), $this->mode( 'print' ), $print ) ) );
		$this->assertSame( 'triples:object_missing', $this->refused( fn() => $this->variants->declare( $single, new EntityRef( 'mode', 'ghost' ), $print ) ) );
		$this->assertCount( 0, $this->site->statements->match() );

		$this->variants->declare( $single, $this->mode( 'print' ), $print );

		$this->assertSame( 'mode_already_served', $this->refused( fn() => $this->variants->declare( $single, $this->mode( 'print' ), $this->variants->template( 'page-print' ) ) ) );
		$this->assertCount( 2, $this->site->statements->match(), 'Nothing was stored by the refusals.' );
	}

	/**
	 * Withdrawing a variant from a mode keeps the relation while it serves another mode, and deletes it with its last mode.
	 *
	 * @return void
	 */
	public function test_withdraw(): void {
		$single = $this->variants->template( 'single' );
		$print  = $this->variants->template( 'single-print' );
		$this->variants->declare( $single, $this->mode( 'print' ), $print );
		$this->variants->declare( $single, $this->mode( 'web' ), $print );

		$this->assertSame( 1, $this->variants->withdraw( $single, $this->mode( 'web' ), $print ) );
		$this->assertNull( $this->variants->variant_of( $single, $this->mode( 'web' ) ) );
		$this->assertNotNull( $this->variants->variant_of( $single, $this->mode( 'print' ) ) );
		$this->assertCount( 2, $this->site->statements->match() );

		$this->assertSame( 2, $this->variants->withdraw( $single, $this->mode( 'print' ), $print ), 'The mode statement and the relation that no longer serves a mode.' );
		$this->assertCount( 0, $this->site->statements->match() );
		$this->assertSame( 0, $this->variants->withdraw( $single, $this->mode( 'print' ), $print ) );
		$this->assertSame( 0, $this->variants->withdraw( $single, $this->mode( 'print' ), $this->variants->template( 'page-print' ) ) );
	}

	/**
	 * Replacing a variant: withdraw the old one, declare the new one.
	 *
	 * @return void
	 */
	public function test_replacing_a_variant(): void {
		$single = $this->variants->template( 'single' );
		$this->variants->declare( $single, $this->mode( 'print' ), $this->variants->template( 'single-print' ) );
		$this->variants->withdraw( $single, $this->mode( 'print' ), $this->variants->template( 'single-print' ) );
		$this->variants->declare( $single, $this->mode( 'print' ), $this->variants->template( 'page-print' ) );

		$this->assertSame( 'twentytwentyfive//page-print', $this->variants->variant_of( $single, $this->mode( 'print' ) )->id() );
	}

	/**
	 * Removing a relation deletes it in every mode.
	 *
	 * @return void
	 */
	public function test_remove(): void {
		$single = $this->variants->template( 'single' );
		$print  = $this->variants->template( 'single-print' );
		$this->variants->declare( $single, $this->mode( 'print' ), $print );
		$this->variants->declare( $single, $this->mode( 'web' ), $print );

		$this->assertSame( 3, $this->variants->remove( $single, $print ) );
		$this->assertCount( 0, $this->site->statements->match() );
		$this->assertSame( 0, $this->variants->remove( $single, $print ) );
	}

	/**
	 * The relations, with their modes, for the screens.
	 *
	 * @return void
	 */
	public function test_relations(): void {
		$this->assertSame( array(), $this->variants->relations() );

		$this->variants->declare( $this->variants->template( 'single' ), $this->mode( 'print' ), $this->variants->template( 'single-print' ) );
		$this->variants->declare( $this->variants->template( 'single' ), $this->mode( 'web' ), $this->variants->template( 'single-print' ) );
		$this->variants->declare( $this->variants->template( 'page' ), $this->mode( 'print' ), $this->variants->template( 'page-print' ) );
		$this->variants->declare( $this->variants->part( 'header' ), $this->mode( 'print' ), $this->variants->part( 'header-print' ) );

		$relations = $this->variants->relations();

		$this->assertCount( 2, $relations );
		$this->assertSame( 'template:twentytwentyfive//single', (string) $relations[0]['source'] );
		$this->assertSame( 'template:twentytwentyfive//single-print', (string) $relations[0]['variant'] );
		$this->assertSame( array( 'print', 'web' ), $relations[0]['modes'] );
		$this->assertSame( array( 'print' ), $relations[1]['modes'] );
		$this->assertCount( 1, $this->variants->relations( 'template_part' ) );
	}

	/**
	 * Without the Triples module the variants are not available.
	 *
	 * @return void
	 */
	public function test_variants_need_triples(): void {
		$module = new \Otherguise\Modes\Module( static function () {}, static function () {}, static fn() => array(), null, static fn() => null );

		$this->expectException( LogicException::class );
		$module->variants();
	}
}
