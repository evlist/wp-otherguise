<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the selection of the statements to forget and of the registration of the WordPress callbacks.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Module;
use Otherguise\Triples\Service\EventQueue;
use Otherguise\Triples\Service\StatementEraser;
use Otherguise\Triples\Service\WordPressCleanup;
use Otherguise\Triples\Storage\Database;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-forbidden-wpdb.php';
require_once __DIR__ . '/support/class-otherguise-test-wpdb.php';

/**
 * No database: the module is built on an object that fails when the store is reached.
 *
 * @covers \Otherguise\Triples\Service\StatementEraser
 * @covers \Otherguise\Triples\Service\WordPressCleanup
 * @covers \Otherguise\Triples\Module
 */
class TriplesCleanupTest extends TestCase {

	/**
	 * Cleans the stubs.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		otherguise_test_reset();
	}

	/**
	 * Builds an eraser on the registry of the fixtures.
	 *
	 * @return StatementEraser
	 */
	private function eraser() {
		$module   = Otherguise_Test_Fixtures::module( new Otherguise_Test_Forbidden_Wpdb() );
		$database = new Database( new Otherguise_Test_Forbidden_Wpdb() );

		return new StatementEraser( $module->store(), $database, new EventQueue( $database, static function () {} ), $module->predicates() );
	}

	/**
	 * Returns the predicates removed with a post.
	 *
	 * @param EntityRef $entity Entity.
	 * @return string[]
	 */
	private function removed_with( EntityRef $entity ) {
		$slugs = $this->eraser()->removable( $entity );

		sort( $slugs );

		return $slugs;
	}

	/**
	 * A post goes with the predicates that can have a post at one end and that do not keep their statements.
	 *
	 * @return void
	 */
	public function test_a_post_is_forgotten_with_the_predicates_that_remove(): void {
		$slugs = $this->removed_with( Ref::post( 12 ) );

		$this->assertContains( 'media/illustrated-by', $slugs );
		$this->assertContains( 'books/contains', $slugs );
		$this->assertContains( 'test/owner', $slugs );
		$this->assertContains( 'test/anything', $slugs, 'An empty list of types accepts any entity.' );
		$this->assertNotContains( 'test/log', $slugs, 'on_delete keep.' );
		$this->assertNotContains( 'modes/mode', $slugs, 'Only statements and modes.' );
		$this->assertNotContains( 'test/friend', $slugs, 'Users only.' );
	}

	/**
	 * Each type gets its own predicates.
	 *
	 * @return void
	 */
	public function test_each_type_gets_its_predicates(): void {
		$this->assertContains( 'media/illustrated-by', $this->removed_with( Ref::attachment( 88 ) ) );
		$this->assertNotContains( 'books/contains', $this->removed_with( Ref::attachment( 88 ) ) );
		$this->assertContains( 'test/friend', $this->removed_with( Ref::user( 3 ) ) );
		$this->assertContains( 'test/owner', $this->removed_with( Ref::user( 3 ) ) );
		$this->assertSame( array( 'test/anything' ), $this->removed_with( Ref::term( 7 ) ) );
		$this->assertContains( 'modes/mode', $this->removed_with( new EntityRef( 'mode', 'print' ) ) );
	}

	/**
	 * Booting the module registers the three callbacks at priority 10.
	 *
	 * @return void
	 */
	public function test_boot_registers_the_deletion_callbacks(): void {
		$recorded = array();
		$wpdb     = new Otherguise_Test_Wpdb( 'wp_' );
		$module   = new Module(
			static function () {},
			$wpdb,
			static function ( $hook, $callback, $priority, $accepted ) use ( &$recorded ) {
				$recorded[ $hook ] = array( get_class( $callback[0] ), $callback[1], $priority, $accepted );
			}
		);

		$module->boot();

		$this->assertSame(
			array(
				'deleted_post' => array( WordPressCleanup::class, 'post', 10, 1 ),
				'deleted_term' => array( WordPressCleanup::class, 'term', 10, 1 ),
				'deleted_user' => array( WordPressCleanup::class, 'user', 10, 1 ),
			),
			$recorded
		);
	}
}
