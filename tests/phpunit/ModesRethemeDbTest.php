<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the move of the variants to another theme, and of its WP-CLI command, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Cli\VariantCommand;
use Otherguise\Modes\Variant\Retheme;
use Otherguise\Modes\Variant\Variants;
use Otherguise\Triples\Entity\EntityRef;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';

/**
 * Retheme and the variant command.
 *
 * @covers \Otherguise\Modes\Variant\Retheme
 * @covers \Otherguise\Modes\Cli\VariantCommand
 */
class ModesRethemeDbTest extends Otherguise_Test_Database_Case {

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
	 * Builds a site with the variants of `twentytwentyfive`: two templates, one template part, and for `journee` two modes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->site = new Otherguise_Test_Modes_Site( $this->wpdb );

		foreach ( array( 'journee', 'journee-print', 'publication', 'publication-print' ) as $slug ) {
			$this->site->template( $slug );
		}

		$this->site->template( 'header', 'wp_template_part' );
		$this->site->template( 'header-print', 'wp_template_part' );

		$this->variants = $this->site->modes->variants();

		$this->variants->declare( $this->variants->template( 'journee' ), new EntityRef( 'mode', 'print' ), $this->variants->template( 'journee-print' ) );
		$this->variants->declare( $this->variants->template( 'publication' ), new EntityRef( 'mode', 'print' ), $this->variants->template( 'publication-print' ) );
		$this->variants->declare( $this->variants->part( 'header' ), new EntityRef( 'mode', 'print' ), $this->variants->part( 'header-print' ) );
	}

	/**
	 * Creates the templates of the other theme, as when the theme is active and has its files.
	 *
	 * @param string[] $slugs Slugs of the templates; the parts are always made.
	 * @return void
	 */
	private function other_theme( array $slugs ) {
		$theme                     = $this->site->lookup->theme;
		$this->site->lookup->theme = 'child';

		foreach ( $slugs as $slug ) {
			$this->site->template( $slug );
		}

		$this->site->template( 'header', 'wp_template_part' );
		$this->site->template( 'header-print', 'wp_template_part' );

		$this->site->lookup->theme = $theme;
	}

	/**
	 * Lists the relations as strings.
	 *
	 * @return string[]
	 */
	private function relations() {
		$lines = array();

		foreach ( array( 'template', 'template_part' ) as $kind ) {
			foreach ( $this->variants->relations( $kind ) as $relation ) {
				$lines[] = $relation['source']->id() . ' -> ' . $relation['variant']->id() . ' [' . implode( ',', $relation['modes'] ) . ']';
			}
		}

		sort( $lines );

		return $lines;
	}

	/**
	 * The relations are declared again between the templates of the other theme, with their modes, and withdrawn from the old one.
	 *
	 * @return void
	 */
	public function test_the_relations_move_to_the_other_theme(): void {
		$this->variants->declare( $this->variants->template( 'journee' ), new EntityRef( 'mode', 'web' ), $this->variants->template( 'journee-print' ) );
		$this->other_theme( array( 'journee', 'journee-print', 'publication', 'publication-print' ) );

		$report = ( new Retheme( $this->variants ) )->run( 'twentytwentyfive', 'child' );

		$this->assertSame( 3, $report['moved'] );
		$this->assertSame( array(), $report['skipped'] );
		$this->assertSame(
			array(
				'child//header -> child//header-print [print]',
				'child//journee -> child//journee-print [print,web]',
				'child//publication -> child//publication-print [print]',
			),
			$this->relations(),
			'The old relations are gone, the modes went along.'
		);
	}

	/**
	 * A second run finds nothing to move.
	 *
	 * @return void
	 */
	public function test_it_can_be_run_twice(): void {
		$this->other_theme( array( 'journee', 'journee-print', 'publication', 'publication-print' ) );

		( new Retheme( $this->variants ) )->run( 'twentytwentyfive', 'child' );
		$total  = count( $this->site->statements->match() );
		$report = ( new Retheme( $this->variants ) )->run( 'twentytwentyfive', 'child' );

		$this->assertSame( 0, $report['moved'] );
		$this->assertSame( array(), $report['skipped'] );
		$this->assertSame( $total, count( $this->site->statements->match() ) );
	}

	/**
	 * Nothing is withdrawn when the templates of the other theme do not exist: the relation stays and is reported.
	 *
	 * @return void
	 */
	public function test_a_relation_is_kept_when_the_new_templates_do_not_exist(): void {
		$this->other_theme( array( 'journee', 'journee-print' ) );

		$report = ( new Retheme( $this->variants ) )->run( 'twentytwentyfive', 'child' );

		$this->assertSame( 2, $report['moved'], 'The two relations whose templates exist moved (the template and the part).' );
		$this->assertCount( 1, $report['skipped'] );
		$this->assertStringContainsString( 'publication', $report['skipped'][0] );
		$this->assertContains( 'twentytwentyfive//publication -> twentytwentyfive//publication-print [print]', $this->relations() );
	}

	/**
	 * A dry run writes nothing.
	 *
	 * @return void
	 */
	public function test_a_dry_run_writes_nothing(): void {
		$before = $this->relations();
		$lines  = array();

		$report = ( new Retheme( $this->variants ) )->run(
			'twentytwentyfive',
			'child',
			true,
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			}
		);

		$this->assertSame( 3, $report['moved'] );
		$this->assertSame( $before, $this->relations() );
		$this->assertCount( 3, $lines );
		$this->assertStringContainsString( 'would become child//journee', implode( "\n", $lines ) );
	}

	/**
	 * Relations of another theme are left alone.
	 *
	 * @return void
	 */
	public function test_other_themes_are_left_alone(): void {
		$this->other_theme( array( 'journee', 'journee-print', 'publication', 'publication-print' ) );

		$report = ( new Retheme( $this->variants ) )->run( 'somewhere-else', 'child' );

		$this->assertSame( 0, $report['moved'] );
		$this->assertCount( 3, $this->relations() );
	}

	/**
	 * The command lists, moves, and refuses what it must.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$lines   = array();
		$errors  = array();
		$command = new VariantCommand(
			fn() => $this->variants,
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $message ) use ( &$errors ) {
				$errors[] = $message;
			}
		);

		$command->list( array(), array() );
		$this->assertContains( "template\ttwentytwentyfive//journee\ttwentytwentyfive//journee-print\tprint", $lines );
		$this->assertContains( "template_part\ttwentytwentyfive//header\ttwentytwentyfive//header-print\tprint", $lines );

		$lines = array();
		$command->list( array(), array( 'theme' => 'child' ) );
		$this->assertSame( array(), $lines );

		$this->other_theme( array( 'journee', 'journee-print', 'publication', 'publication-print' ) );

		$lines = array();
		$command->retheme( array( 'twentytwentyfive', 'child' ), array( 'dry-run' => true ) );
		$this->assertContains( 'Dry run, nothing written: 3 relation(s) to move from twentytwentyfive to child, 0 skipped.', $lines );
		$this->assertSame( array(), $errors );

		$lines = array();
		$command->retheme( array( 'twentytwentyfive', 'child' ), array() );
		$this->assertContains( 'Done: 3 relation(s) moved from twentytwentyfive to child, 0 skipped.', $lines );

		$lines = array();
		$command->list( array(), array( 'theme' => 'child' ) );
		$this->assertCount( 3, $lines );

		$command->retheme( array( 'a' ), array() );
		$command->retheme( array( 'a', 'a' ), array() );
		$command->retheme( array( 'bad theme', 'b' ), array() );
		$this->assertCount( 3, $errors, 'One theme, the same theme twice, a malformed theme: three refusals.' );
	}

	/**
	 * Relations that cannot be moved make the command fail after it has said which.
	 *
	 * @return void
	 */
	public function test_the_command_fails_when_something_was_skipped(): void {
		$errors  = array();
		$lines   = array();
		$command = new VariantCommand(
			fn() => $this->variants,
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $message ) use ( &$errors ) {
				$errors[] = $message;
			}
		);

		$command->retheme( array( 'twentytwentyfive', 'missing-theme' ), array() );

		$this->assertNotEmpty( preg_grep( '/^Skipped, /', $lines ) );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'activate the theme first', $errors[0] );
		$this->assertCount( 3, $this->relations() );
	}

	/**
	 * Without the Triples module the command stops.
	 *
	 * @return void
	 */
	public function test_the_command_needs_the_modules(): void {
		$errors  = array();
		$command = new VariantCommand(
			fn() => null,
			static function () {},
			static function ( $message ) use ( &$errors ) {
				$errors[] = $message;
			}
		);

		$command->list( array(), array() );

		$this->assertSame( array( 'The modules Triples and Modes must be enabled.' ), $errors );
	}
}
