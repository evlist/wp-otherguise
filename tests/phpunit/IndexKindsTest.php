<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the kinds as data, with a hierarchy (slice 603).
 *
 * @package Otherguise
 */

use Otherguise\Index\Admin\KindsScreen;
use Otherguise\Index\Cli\KindsCommand;
use Otherguise\Index\Kinds;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * No database and no WordPress: an in-memory store of the kinds.
 *
 * @covers \Otherguise\Index\Kinds
 * @covers \Otherguise\Index\Cli\KindsCommand
 * @covers \Otherguise\Index\Admin\KindsScreen
 */
class IndexKindsTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var Otherguise_Test_Memory_Kinds
	 */
	private $store;

	/**
	 * Starts with the built-in kinds.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();

		$this->store = Otherguise_Test_Memory_Kinds::install();
	}

	/**
	 * The built-in kinds are created once, with `waterway` under `place`, all protected, and a second seed creates nothing and does not undo a
	 * rename.
	 *
	 * @return void
	 */
	public function test_the_built_in_kinds(): void {
		$this->assertCount( 18, $this->store->kinds );
		$this->assertSame( 'place', $this->store->kinds['waterway']['parent'] );

		foreach ( $this->store->kinds as $kind ) {
			$this->assertTrue( $kind['builtin'] );
		}

		Kinds::edit( 'place', 'Lieu' );

		$this->assertSame( 0, Kinds::seed( true ) );
		$this->assertSame( 'Lieu', Kinds::label( 'place' ) );
		$this->assertSame( 0, Kinds::seed(), 'Once is enough: the option says so.' );
	}

	/**
	 * The tree lists a parent before its children, and the selector indents a sub-kind.
	 *
	 * @return void
	 */
	public function test_the_tree(): void {
		$all = array_keys( Kinds::all() );

		$this->assertGreaterThan( array_search( 'place', $all, true ), array_search( 'waterway', $all, true ), 'A parent comes before its children.' );
		$this->assertSame( '— Waterway', Kinds::all()['waterway'] );

		$rows = array_column( Kinds::tree(), 'depth', 'slug' );

		$this->assertSame( 0, $rows['place'] );
		$this->assertSame( 1, $rows['waterway'] );
	}

	/**
	 * `is_a`, the ancestors, the descendants and the compatibility of two kinds.
	 *
	 * @return void
	 */
	public function test_the_hierarchy(): void {
		$this->assertTrue( Kinds::add( 'river', 'River', 'waterway' ) );

		$this->assertSame( array( 'waterway', 'place' ), Kinds::ancestors( 'river' ) );
		$this->assertTrue( Kinds::is_a( 'river', 'place' ) );
		$this->assertTrue( Kinds::is_a( 'place', 'place' ) );
		$this->assertFalse( Kinds::is_a( 'place', 'river' ) );
		$below = Kinds::descendants( 'place' );

		$this->assertSame( 'place', $below[0] );

		sort( $below );

		$this->assertSame( array( 'admin1', 'admin2', 'admin3', 'commune', 'country', 'place', 'river', 'site', 'waterway' ), $below, 'The levels of a place (slice 614) are below place.' );

		$this->assertTrue( Kinds::compatible( 'river', 'place' ) );
		$this->assertTrue( Kinds::compatible( 'place', 'river' ) );
		$this->assertFalse( Kinds::compatible( 'river', 'species' ) );
		$this->assertTrue( Kinds::compatible( 'other', 'species' ), '`other` conflicts with nothing.' );
		$this->assertTrue( Kinds::compatible( '', 'species' ) );
		$this->assertTrue( Kinds::compatible( 'unknown', 'species' ) );
	}

	/**
	 * A topic carries the classes of its kind and of its ancestors, the most specific first; the class can be changed.
	 *
	 * @return void
	 */
	public function test_the_classes(): void {
		$this->assertSame( array( 'index-kind-waterway', 'index-kind-place' ), Kinds::classes( 'waterway' ) );
		$this->assertSame( array(), Kinds::classes( 'unknown' ) );

		Kinds::edit( 'waterway', 'Cours d’eau', 'Eau Vive!' );

		$this->assertSame( array( 'index-kind-eauvive', 'index-kind-place' ), Kinds::classes( 'waterway' ) );

		// The whole class typed in the field (the screen shows the prefix next to it): the prefix is dropped, also from what is already stored.
		Kinds::edit( 'waterway', 'Cours d’eau', 'index-kind-eau' );
		$this->assertSame( 'eau', $this->store->kinds['waterway']['css'] );

		$this->store->kinds['place']['css'] = 'index-kind-index-kind-lieu';
		Kinds::use_store( $this->store );
		$this->assertSame( array( 'index-kind-eau', 'index-kind-lieu' ), Kinds::classes( 'waterway' ) );
	}

	/**
	 * Adding a kind: the slug, the label, the parent and the depth are checked.
	 *
	 * @return void
	 */
	public function test_adding_a_kind(): void {
		$this->assertTrue( Kinds::add( 'hamlet', 'Hamlet', 'place', 'lieu-dit' ) );
		$this->assertSame( 'lieu-dit', $this->store->kinds['hamlet']['css'] );
		$this->assertFalse( $this->store->kinds['hamlet']['builtin'] );

		$this->assertStringContainsString( 'exists', Kinds::add( 'hamlet', 'Again' ) );
		$this->assertStringContainsString( 'lower-case', Kinds::add( 'Bad Slug', 'x' ) );
		$this->assertStringContainsString( 'label', Kinds::add( 'x1', ' ' ) );
		$this->assertStringContainsString( 'not a kind of the site', Kinds::add( 'x2', 'X', 'nowhere' ) );

		$this->assertTrue( Kinds::add( 'a1', 'A1', 'place' ) );
		$this->assertTrue( Kinds::add( 'a2', 'A2', 'a1' ) );
		$this->assertTrue( Kinds::add( 'a3', 'A3', 'a2' ), 'Four levels are allowed.' );
		$this->assertStringContainsString( '4 levels', Kinds::add( 'a4', 'A4', 'a3' ) );
	}

	/**
	 * A kind of code cannot be a parent, and cannot be edited, moved or deleted.
	 *
	 * @return void
	 */
	public function test_kinds_of_code_are_read_only(): void {
		$GLOBALS['otherguise_test_filters']['index_kinds'] = array( 'building' => 'Building' );
		Kinds::use_store( $this->store );

		$this->assertStringContainsString( 'not a kind of the site', Kinds::add( 'tower', 'Tower', 'building' ) );
		$this->assertStringContainsString( 'read-only', Kinds::edit( 'building', 'X' ) );
		$this->assertStringContainsString( 'read-only', Kinds::move( 'building', 'place' ) );
		$this->assertStringContainsString( 'read-only', Kinds::delete( 'building' ) );
	}

	/**
	 * A built-in kind keeps its place and its life; its label can change; a kind of the site can move, but not under itself, and not too deep.
	 *
	 * @return void
	 */
	public function test_protection_and_moving(): void {
		$this->assertStringContainsString( 'built-in kind', Kinds::move( 'waterway', '' ) );
		$this->assertStringContainsString( 'built-in kind', Kinds::delete( 'waterway' ) );
		$this->assertStringContainsString( 'built-in kind', Kinds::delete( 'other' ) );
		$this->assertTrue( Kinds::edit( 'waterway', 'Cours d’eau' ) );
		$this->assertSame( 'waterway', $this->store->kinds['waterway']['slug'] );

		Kinds::add( 'a', 'A', 'place' );
		Kinds::add( 'b', 'B', 'a' );

		$this->assertStringContainsString( 'under itself', Kinds::move( 'a', 'b' ) );
		$this->assertStringContainsString( 'under itself', Kinds::move( 'a', 'a' ) );
		$this->assertTrue( Kinds::move( 'b', '' ) );
		$this->assertSame( '', $this->store->kinds['b']['parent'] );

		Kinds::add( 'c', 'C', 'waterway' );
		Kinds::add( 'd', 'D', 'c' );
		Kinds::add( 'e', 'E' );

		$this->assertStringContainsString( '4 levels', Kinds::move( 'e', 'd' ) );
	}

	/**
	 * A kind that has sub-kinds is refused; a kind that is used needs another to take over, and the terms and topics go there.
	 *
	 * @return void
	 */
	public function test_deleting_a_kind(): void {
		Kinds::add( 'hamlet', 'Hamlet', 'place' );
		Kinds::add( 'farm', 'Farm', 'hamlet' );

		$this->assertStringContainsString( 'sub-kinds', Kinds::delete( 'hamlet' ) );
		$this->assertTrue( Kinds::delete( 'farm' ) );

		$this->store->used['hamlet'] = array( 3, 2 );

		$refused = Kinds::delete( 'hamlet' );

		$this->assertStringContainsString( '3 term(s) and 2 topics', $refused );
		$this->assertStringContainsString( 'take them over', $refused );
		$this->assertStringContainsString( 'take them over', Kinds::delete( 'hamlet', 'hamlet' ) );
		$this->assertStringContainsString( 'take them over', Kinds::delete( 'hamlet', 'nowhere' ) );
		$this->assertArrayHasKey( 'hamlet', $this->store->kinds );

		$this->assertTrue( Kinds::delete( 'hamlet', 'place' ) );
		$this->assertSame( array( array( 'hamlet', 'place' ) ), $this->store->reassigned );
		$this->assertArrayNotHasKey( 'hamlet', $this->store->kinds );
	}

	/**
	 * The command: list, add, rename, css, move, delete, and the reasons of the refusals.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$log  = array();
		$fail = array();
		$cmd  = new KindsCommand(
			static function ( $line ) use ( &$log ) {
				$log[] = $line;
			},
			static function ( $line ) use ( &$log ) {
				$log[] = 'OK ' . $line;
			},
			static function ( $line ) use ( &$fail ) {
				$fail[] = $line;
			}
		);

		$cmd->add(
			array( 'hamlet' ),
			array(
				'label' => 'Hamlet',
				'parent' => 'place',
			)
		);
		$cmd->rename( array( 'hamlet', 'Lieu-dit' ), array() );
		$cmd->css( array( 'hamlet', 'ld' ), array() );
		$cmd->move( array( 'hamlet' ), array() );
		$cmd->list( array(), array() );

		$this->assertSame( array(), $fail );
		$this->assertContains( 'OK Kind hamlet added.', $log );
		$this->assertContains( 'OK The class of hamlet is now index-kind-ld.', $log );
		$this->assertContains( "hamlet\tLieu-dit\tindex-kind-ld\t0 term(s), 0 topic(s)\t", $log );
		$this->assertContains( "  waterway\tWaterway\tindex-kind-waterway\t0 term(s), 0 topic(s)\tbuilt-in", $log );

		$cmd->delete( array( 'waterway' ), array() );
		$cmd->delete( array( 'nowhere' ), array() );
		$cmd->css( array( 'nowhere', 'x' ), array() );

		$this->assertStringContainsString( 'built-in kind', $fail[0] );
		$this->assertStringContainsString( 'No such kind', $fail[1] );
		$this->assertStringContainsString( 'No such kind', $fail[2] );

		$cmd->delete( array( 'hamlet' ), array() );
		$this->assertArrayNotHasKey( 'hamlet', $this->store->kinds );
	}

	/**
	 * The fields posted to the screen.
	 *
	 * @var array<string, string>
	 */
	private $posted = array();

	/**
	 * Builds the screen on doubles.
	 *
	 * @param bool  $allowed Whether the user may.
	 * @param bool  $nonce   Whether the nonce is good.
	 * @param array $urls    Receives the redirections.
	 * @return KindsScreen
	 */
	private function screen( $allowed, $nonce, array &$urls ): KindsScreen {
		return new KindsScreen(
			static fn() => $allowed,
			fn( $type, $name ) => INPUT_POST === $type ? ( ( $this->posted + array( '_wpnonce' => 'good' ) )[ $name ] ?? null ) : ( $this->posted[ 'get_' . $name ] ?? null ),
			static fn() => $nonce,
			static function ( $url ) use ( &$urls ) {
				$urls[] = $url;
			}
		);
	}

	/**
	 * The screen adds, saves, moves and deletes, and reports a refusal; nothing happens without the capability or the nonce.
	 *
	 * @return void
	 */
	public function test_the_screen(): void {
		$urls = array();

		$this->posted = array(
			'slug'   => 'hamlet',
			'label'  => 'Hamlet',
			'parent' => 'place',
			'css'    => '',
		);
		$this->screen( true, true, $urls )->add();

		$this->assertSame( 'place', $this->store->kinds['hamlet']['parent'] );
		$this->assertStringContainsString( 'done=otherguise_index_kind_add', $urls[0] );

		$this->posted = array(
			'slug'   => 'hamlet',
			'label'  => 'Lieu-dit',
			'parent' => 'waterway',
			'css'    => 'ld',
		);
		$this->screen( true, true, $urls )->save();

		$this->assertSame( 'Lieu-dit', $this->store->kinds['hamlet']['label'] );
		$this->assertSame( 'waterway', $this->store->kinds['hamlet']['parent'] );

		$this->posted = array(
			'slug'   => 'waterway',
			'label'  => 'Cours d’eau',
			'parent' => '',
			'css'    => 'waterway',
		);
		$this->screen( true, true, $urls )->save();

		$this->assertSame( 'place', $this->store->kinds['waterway']['parent'], 'A built-in kind is not moved by a form.' );
		$this->assertSame( 'Cours d’eau', $this->store->kinds['waterway']['label'] );

		$this->posted = array( 'slug' => 'waterway' );
		$this->screen( true, true, $urls )->delete();

		$this->assertArrayHasKey( 'waterway', $this->store->kinds );
		$this->assertStringContainsString( 'failed=', end( $urls ) );

		$this->posted = array( 'slug' => 'hamlet' );
		$this->screen( true, true, $urls )->delete();
		$this->assertArrayNotHasKey( 'hamlet', $this->store->kinds );

		$count = count( $urls );

		foreach ( array( array( false, true ), array( true, false ) ) as $case ) {
			try {
				$this->screen( $case[0], $case[1], $urls )->delete();
				$this->fail( 'The request must stop.' );
			} catch ( RuntimeException $stop ) {
				$this->assertStringContainsString( 'wp_die', $stop->getMessage() );
			}
		}

		$this->assertCount( $count, $urls );
	}

	/**
	 * The screen prints the tree with a form per kind that can change, the mark of the built-in kinds, and nothing for a user who may not.
	 *
	 * @return void
	 */
	public function test_the_screen_prints(): void {
		$urls = array();

		Kinds::add( 'hamlet', 'Hamlet', 'place' );
		$this->store->used['hamlet'] = array( 1, 0 );

		ob_start();
		$this->screen( false, true, $urls )->render();
		$this->assertSame( '', ob_get_clean() );

		ob_start();
		$this->screen( true, true, $urls )->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="label" value="Hamlet"', $html );
		$this->assertStringContainsString( 'built-in', $html );
		$this->assertStringContainsString( 'otherguise_index_kind_delete', $html );
		$this->assertStringContainsString( 'name="reassign"', $html, 'A kind that is used offers where its terms go.' );
		$this->assertStringContainsString( 'nonce-stub', $html );
	}

	/**
	 * `any` is the root of every kind without being the stored parent of any (slice 615): everything is below it, nothing has to be moved, it adds no CSS
	 * class, it conflicts with nothing, and `other` is a kind of its own, not the root.
	 *
	 * @return void
	 */
	public function test_any_is_the_root_of_every_kind(): void {
		$this->assertTrue( Kinds::is_kind( 'any' ) );
		$this->assertTrue( Kinds::is_a( 'species', 'any' ) );
		$this->assertTrue( Kinds::is_a( 'commune', 'any' ) );
		$this->assertTrue( Kinds::is_a( 'other', 'any' ) );
		$this->assertTrue( Kinds::is_a( 'any', 'any' ) );
		$this->assertFalse( Kinds::is_a( 'any', 'species' ), 'The root is not a sort of anything.' );
		$this->assertFalse( Kinds::is_a( 'species', 'other' ), 'Other is a kind, not a root.' );

		$this->assertSame( '', Kinds::records()['any']['parent'], 'Nothing is stored under it.' );
		$this->assertContains( 'species', Kinds::descendants( 'any' ) );
		$this->assertContains( 'any', Kinds::descendants( 'any' ) );
		$this->assertSame( array( 'index-kind-waterway', 'index-kind-place' ), Kinds::classes( 'waterway' ), 'No class for the root.' );
		$this->assertSame( array(), Kinds::classes( 'any' ) );

		$this->assertTrue( Kinds::compatible( 'any', 'species' ) );
		$this->assertTrue( Kinds::compatible( 'commune', 'any' ) );
	}
}
