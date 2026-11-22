<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the module of the index and its screen of the kinds (slice 600).
 *
 * @package Otherguise
 */

use Otherguise\Core\DisabledByDefault;
use Otherguise\Index\Admin\TermsScreen;
use Otherguise\Index\Kinds;
use Otherguise\Index\Module;
use Otherguise\Index\Source\Terms;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-memory-topics.php';
require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * No database and no WordPress: stubs and doubles.
 *
 * @covers \Otherguise\Index\Module
 * @covers \Otherguise\Index\Admin\TermsScreen
 * @covers \Otherguise\Index\Kinds
 * @covers \Otherguise\Index\Source\Terms
 */
class IndexScreenAndModuleTest extends TestCase {

	/**
	 * The fields of the request, by type then name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $request = array();

	/**
	 * Starts clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		Otherguise_Test_Memory_Kinds::install();
		$this->request = array();
	}

	/**
	 * The module is `index`, depends on Triples only, and is off by default.
	 *
	 * @return void
	 */
	public function test_identity(): void {
		$module = new Module();

		$this->assertSame( 'index', $module->id() );
		$this->assertSame( array( 'triples' ), $module->dependencies() );
		$this->assertInstanceOf( DisabledByDefault::class, $module );
		$this->assertNull( $module->activate() );
	}

	/**
	 * The hooks of the module with their priority and arguments; outside the administration there is no screen.
	 *
	 * @return void
	 */
	public function test_the_hooks(): void {
		$hooks = array();

		( new Module(
			static function ( $hook, $callback, $priority, $accepted ) use ( &$hooks ) {
				$hooks[ $hook ][] = array( is_array( $callback ) ? $callback[1] : 'fn', $priority, $accepted );
			}
		) )->boot();

		$this->assertSame(
			array(
				'init'                        => array( array( 'run', 5, 0 ), array( 'register_taxonomy', 10, 0 ), array( 'register_taxonomy', 10, 0 ), array( 'seed', 12, 0 ) ),
				'triples_register_predicates' => array( array( 'register_predicates', 10, 1 ) ),
				'wp_after_insert_post'        => array( array( 'queue_post', 20, 1 ), array( 'on_save', 30, 1 ) ),
				'set_object_terms'            => array( array( 'queue_post', 10, 1 ) ),
				'shutdown'                    => array( array( 'flush_queue', 10, 0 ) ),
				'otherguise_index_batch'      => array( array( 'run_batch', 10, 2 ) ),
				'cli_init'                    => array( array( 'register_command', 10, 0 ) ),
				'index_build'                 => array( array( 'build_for_filter', 10, 3 ) ),
				'index_mention_elements'      => array( array( 'mention_elements_for_filter', 10, 2 ) ),
				'otherguise_index_pages'      => array( array( 'tick', 10, 0 ) ),
				'otherguise_index_enrich'     => array( array( 'tick', 10, 0 ) ),
				'otherguise_index_photos'     => array( array( 'tick', 10, 0 ) ),
				'wp_media_helper_places_updated' => array( array( 'tick', 10, 0 ) ),
				'otherguise_index_page'       => array( array( 'single', 10, 1 ) ),
			),
			$hooks
		);
	}

	/**
	 * Uninstall removes the option of the module.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_the_option(): void {
		update_option( Module::OPTION, array( 'tags' ) );

		( new Module() )->uninstall();

		$this->assertFalse( get_option( Module::OPTION ) );
	}

	/**
	 * The built-in kinds exist; the filter of code adds a kind of its own and cannot replace the kinds of the site; a malformed slug is dropped.
	 *
	 * @return void
	 */
	public function test_kinds(): void {
		foreach ( array( 'place', 'waterway', 'activity', 'season', 'other' ) as $kind ) {
			$this->assertTrue( Kinds::is_kind( $kind ) );
		}

		$this->assertFalse( Kinds::is_kind( 'nonsense' ) );

		$GLOBALS['otherguise_test_filters']['index_kinds'] = array(
			'building' => 'Building',
			'Bad Slug' => 'Bad',
			'place'    => 'Not a place',
		);
		Kinds::use_store( new Otherguise_Test_Memory_Kinds() );
		Kinds::seed( true );

		$this->assertTrue( Kinds::is_kind( 'building' ) );
		$this->assertFalse( Kinds::is_kind( 'bad slug' ) );
		$this->assertSame( 'Place', Kinds::label( 'place' ), 'A kind of the site wins over a kind of code.' );
		$this->assertSame( 'code', Kinds::records()['building']['source'] );
	}

	/**
	 * A tag whose parent has no kind is a flat candidate; a kind that is not a kind is ignored.
	 *
	 * @return void
	 */
	public function test_the_source_of_terms(): void {
		$terms = array(
			1 => array(
				'id'     => 1,
				'name'   => 'Parent',
				'parent' => 0,
			),
			2 => array(
				'id'     => 2,
				'name'   => 'Child',
				'parent' => 1,
			),
			3 => array(
				'id'     => 3,
				'name'   => 'Odd',
				'parent' => 0,
			),
		);
		$kinds = array(
			2 => 'place',
			3 => 'nonsense',
		);
		$post  = array( $terms[2], $terms[3] );

		$source     = new Terms( 'category', 'categories', fn() => $post, fn( $id ) => $terms[ $id ] ?? null, fn( $id ) => $kinds[ $id ] ?? '' );
		$candidates = $source->candidates( 5 );

		$this->assertCount( 1, $candidates );
		$this->assertSame( 'Child', $candidates[0]->label );
		$this->assertNull( $candidates[0]->parent );
		$this->assertSame( array( 'term:category:2' ), $candidates[0]->keys );
		$this->assertSame( 'categories', $source->id() );
		$this->assertTrue( $source->is_sure() );
	}

	/**
	 * Builds the screen with doubles.
	 *
	 * @param bool  $allowed Whether the user may.
	 * @param bool  $nonce   Whether the nonce is good.
	 * @param array $applied Receives what is applied.
	 * @param array $urls    Receives the redirections.
	 * @return TermsScreen
	 */
	private function screen( $allowed, $nonce, &$applied, &$urls ): TermsScreen {
		return new TermsScreen(
			static function () {
				return array(
					'rows'  => array(
						array(
							'id'    => 4,
							'name'  => 'Étroubles',
							'count' => 3,
							'kind'  => 'place',
						),
						array(
							'id'    => 5,
							'name'  => 'Repos',
							'count' => 90,
							'kind'  => '',
						),
					),
					'total' => 120,
				);
			},
			static function ( $taxonomy, array $kinds ) use ( &$applied ) {
				$applied = array( $taxonomy, $kinds );

				return count( $kinds );
			},
			static fn() => $allowed,
			fn( $type, $name ) => $this->request[ $type ][ $name ] ?? null,
			static fn() => $nonce,
			static function ( $url ) use ( &$urls ) {
				$urls[] = $url;
			}
		);
	}

	/**
	 * Sets the posted fields (with a nonce).
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @return void
	 */
	private function post( array $fields ): void {
		$this->request[ INPUT_POST ] = $fields + array( '_wpnonce' => 'good' );
	}

	/**
	 * Only the rows that changed are applied, `none` takes the kind off, and the bulk kind goes to the checked rows.
	 *
	 * @return void
	 */
	public function test_saving_the_kinds(): void {
		$applied = null;
		$urls    = array();
		$this->post(
			array(
				'taxonomy'  => 'post_tag',
				'was'       => array(
					'4' => 'place',
					'5' => 'none',
					'6' => 'place',
					'7' => 'none',
				),
				'kind'      => array(
					'4' => 'place',
					'5' => 'none',
					'6' => 'none',
					'7' => 'bogus',
				),
				'checked'   => array( '5', '8' ),
				'bulk_kind' => 'species',
			)
		);

		$this->screen( true, true, $applied, $urls )->handle();

		$this->assertSame(
			array(
				'post_tag',
				array(
					6 => '',
					5 => 'species',
					8 => 'species',
				),
			),
			$applied
		);
		$this->assertCount( 1, $urls );
		$this->assertStringContainsString( 'changed=3', $urls[0] );
	}

	/**
	 * Nothing is applied without the capability or without the nonce; an unknown taxonomy falls back to the categories.
	 *
	 * @return void
	 */
	public function test_capability_and_nonce(): void {
		$applied = null;
		$urls    = array();
		$this->post(
			array(
				'taxonomy'  => 'post_tag',
				'checked'   => array( '5' ),
				'bulk_kind' => 'place',
			)
		);

		try {
			$this->screen( false, true, $applied, $urls )->handle();
			$this->fail( 'A user who may not must be stopped.' );
		} catch ( RuntimeException $stop ) {
			$this->assertStringContainsString( 'wp_die', $stop->getMessage() );
		}

		try {
			$this->screen( true, false, $applied, $urls )->handle();
			$this->fail( 'A bad nonce must stop the request.' );
		} catch ( RuntimeException $stop ) {
			$this->assertStringContainsString( 'expired', $stop->getMessage() );
		}

		$this->assertNull( $applied );
		$this->assertSame( array(), $urls );

		$this->post(
			array(
				'taxonomy' => 'nav_menu',
				'checked' => array( '5' ),
				'bulk_kind' => 'place',
			)
		);
		$this->screen( true, true, $applied, $urls )->handle();

		$this->assertSame( 'category', $applied[0] );
	}

	/**
	 * The screen prints a nonce, a selector per row, the number of posts and nothing for a user who may not.
	 *
	 * @return void
	 */
	public function test_the_screen_prints(): void {
		$applied = null;
		$urls    = array();

		ob_start();
		$this->screen( false, true, $applied, $urls )->render();
		$this->assertSame( '', ob_get_clean() );

		ob_start();
		$this->screen( true, true, $applied, $urls )->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="kind[4]"', $html );
		$this->assertStringContainsString( 'name="kind[5]"', $html );
		$this->assertStringContainsString( 'name="bulk_kind"', $html );
		$this->assertStringContainsString( 'Étroubles', $html );
		$this->assertStringContainsString( 'otherguise_index_set_kinds', $html );
	}
}
