<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the other angles of a subject (slice 507), on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Module as WikiModule;
use Otherguise\Wiki\Tree\Angles;
use Otherguise\Wiki\Tree\AnglesBlock;
use Otherguise\Wiki\Tree\Keys;
use Otherguise\Wiki\Tree\Pages;
use Otherguise\Wiki\Tree\Router;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-node-store.php';

/**
 * The statements are real (MariaDB), the posts are an array.
 *
 * @covers \Otherguise\Wiki\Tree\Angles
 * @covers \Otherguise\Wiki\Tree\AnglesBlock
 */
class WikiTreeAnglesDbTest extends Otherguise_Test_Database_Case {

	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * The angles.
	 *
	 * @var Angles
	 */
	private $angles;

	/**
	 * The router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Ids: bio, op, par_bio, par_op, ville, deep (a Paris at the third level), alone.
	 *
	 * @var array<string, int>
	 */
	private $id = array();

	/**
	 * Pages that the reader may not see.
	 *
	 * @var int[]
	 */
	private $hidden = array();

	/**
	 * Builds Biography > Paris, Opinion > Paris, Opinion > Voyages > Paris, Opinion > Paris (ville), and Opinion > Seul.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		otherguise_test_wp_objects( array() );

		$wiki       = new WikiModule();
		$site       = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $wiki ) {
					$wiki->register_predicates( $registry );
				},
			)
		);
		$statements = $site->statements;
		$this->keys = new Keys(
			static function () use ( $statements ) {
				return $statements;
			}
		);

		$this->router = new Router( $this->keys, fn() => '/', fn() => null, static fn( $path ) => 'https://example.test' . $path );
		$this->angles = new Angles(
			$this->keys,
			static function () use ( $statements ) {
				return $statements;
			}
		);

		$pages   = new Pages( $this->keys, new Otherguise_Test_Node_Store() );
		$bio     = $pages->create( 0, 'Biography', '', 'publish' );
		$op      = $pages->create( 0, 'Opinion', '', 'publish' );
		$voyages = $pages->create( $op, 'Voyages', '', 'publish' );

		$this->id = array(
			'bio'     => $bio,
			'op'      => $op,
			'par_bio' => $pages->create( $bio, 'Paris', '', 'publish' ),
			'par_op'  => $pages->create( $op, 'Paris', '', 'publish' ),
			'deep'    => $pages->create( $voyages, 'Paris', '', 'publish' ),
			'ville'   => $pages->create( $op, 'Paris (ville)', '', 'publish' ),
			'alone'   => $pages->create( $op, 'Seul', '', 'publish' ),
		);
	}

	/**
	 * The pages of the same title at any level, by address, the page itself never.
	 *
	 * @return void
	 */
	public function test_the_same_title_elsewhere(): void {
		$this->assertSame( array( $this->id['par_op'], $this->id['deep'] ), $this->angles->of( $this->id['par_bio'] ) );
		$this->assertSame( array( $this->id['par_bio'], $this->id['deep'] ), $this->angles->of( $this->id['par_op'] ) );
		$this->assertSame( array(), $this->angles->of( $this->id['alone'] ) );
		$this->assertSame( array(), $this->angles->of( 999 ) );
	}

	/**
	 * Pages declared as the same subject: symmetric, said twice, listed after the same title and once only; a page without key refused.
	 *
	 * @return void
	 */
	public function test_the_same_subject_under_another_title(): void {
		$this->assertTrue( $this->angles->relate( $this->id['par_bio'], $this->id['ville'] ) );
		$this->assertTrue( $this->angles->relate( $this->id['ville'], $this->id['par_bio'] ) );

		$this->assertSame( array( $this->id['ville'] ), $this->angles->related( $this->id['par_bio'] ) );
		$this->assertSame( array( $this->id['par_bio'] ), $this->angles->related( $this->id['ville'] ) );
		$this->assertSame( array( $this->id['par_op'], $this->id['deep'], $this->id['ville'] ), $this->angles->of( $this->id['par_bio'] ) );
		$this->assertSame( array( $this->id['par_bio'] ), $this->angles->of( $this->id['ville'] ) );

		// A page declared and with the same title is listed once.
		$this->angles->relate( $this->id['par_bio'], $this->id['par_op'] );

		$this->assertSame( array( $this->id['par_op'], $this->id['deep'], $this->id['ville'] ), $this->angles->of( $this->id['par_bio'] ) );

		$this->assertFalse( $this->angles->relate( $this->id['par_bio'], $this->id['par_bio'] ) );
		$this->assertFalse( $this->angles->relate( $this->id['par_bio'], 999 ) );

		$this->assertTrue( $this->angles->unrelate( $this->id['ville'], $this->id['par_bio'] ) );
		$this->assertFalse( $this->angles->unrelate( $this->id['ville'], $this->id['par_bio'] ) );
		$this->assertSame( array(), $this->angles->related( $this->id['ville'] ) );
	}

	/**
	 * A declaration that involves a page that no longer exists goes.
	 *
	 * @return void
	 */
	public function test_purge(): void {
		$this->angles->relate( $this->id['par_bio'], $this->id['ville'] );
		$this->angles->relate( $this->id['par_op'], $this->id['alone'] );

		$this->assertSame( 1, $this->angles->purge( array( $this->id['par_bio'], $this->id['ville'], $this->id['par_op'] ) ) );
		$this->assertSame( array( $this->id['ville'] ), $this->angles->related( $this->id['par_bio'] ) );
		$this->assertSame( array(), $this->angles->related( $this->id['par_op'] ) );
	}

	/**
	 * The block lists the pages the reader may see; nothing when there are none.
	 *
	 * @return void
	 */
	public function test_the_block(): void {
		$block = new AnglesBlock( $this->angles, $this->keys, $this->router, fn() => $this->id['par_bio'], fn( $id ) => ! in_array( $id, $this->hidden, true ) );
		$html  = $block->render();

		$this->assertStringContainsString( 'Other angles:', $html );
		$this->assertStringContainsString( '<a href="https://example.test/Opinion/Paris">/Opinion/Paris</a>', $html );
		$this->assertStringContainsString( 'https://example.test/Opinion/Voyages/Paris', $html );
		$this->assertLessThan( strpos( $html, 'Voyages' ), strpos( $html, '/Opinion/Paris' ) );

		$this->hidden = array( $this->id['par_op'], $this->id['deep'] );

		$this->assertSame( '', $block->render() );
		$this->assertSame( '', ( new AnglesBlock( $this->angles, $this->keys, $this->router, fn() => $this->id['alone'] ) )->render() );
	}
}
