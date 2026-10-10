<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the attachment method of the media module, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Core\ModuleLoader;
use Otherguise\Core\Modules;
use Otherguise\Media\Link\Links;
use Otherguise\Media\Method\MediaHelperMethod;
use Otherguise\Media\Module as MediaModule;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-media-helper-stub.php';
require_once __DIR__ . '/support/class-otherguise-test-post-parents.php';

/**
 * The method, the links and the module.
 *
 * @covers \Otherguise\Media\Method\MediaHelperMethod
 * @covers \Otherguise\Media\Link\Links
 * @covers \Otherguise\Media\Module
 */
class MediaMethodDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Modules wired together.
	 *
	 * @var Otherguise_Test_Modes_Site
	 */
	private $site;

	/**
	 * Parents of the attachments.
	 *
	 * @var Otherguise_Test_Post_Parents
	 */
	private $parents;

	/**
	 * Whether the user may change attachments.
	 *
	 * @var bool
	 */
	private $allowed = true;

	/**
	 * The method.
	 *
	 * @var MediaHelperMethod
	 */
	private $method;

	/**
	 * Builds the site with three posts and three media items, and the method.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		otherguise_test_wp_objects(
			array(
				new WP_Post( 12 ),
				new WP_Post( 13 ),
				new WP_Post( 14 ),
				new WP_Post( 88, 'attachment' ),
				new WP_Post( 90, 'attachment' ),
				new WP_Post( 91, 'attachment' ),
			)
		);

		$media         = new MediaModule();
		$this->site    = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $media ) {
					$media->register_predicate( $registry );
				},
			)
		);
		$this->parents = new Otherguise_Test_Post_Parents();
		$mimes         = array(
			88 => 'image/jpeg',
			90 => 'application/gpx+xml',
			91 => 'image/png',
		);
		$this->method  = new MediaHelperMethod(
			new Links( $this->site->statements, static fn( $id ) => $mimes[ $id ] ?? '' ),
			$this->site->modes->modes(),
			$this->parents,
			fn() => $this->allowed
		);
	}

	/**
	 * Counts the statements.
	 *
	 * @return int
	 */
	private function total() {
		return count( $this->site->statements->match() );
	}

	/**
	 * Gives the code of a refusal, or true.
	 *
	 * @param mixed $result Result of a method.
	 * @return string|true
	 */
	private function code( $result ) {
		return $result instanceof WP_Error ? $result->get_error_code() : $result;
	}

	/**
	 * Several posts, two fields: the modes (every mode, written out, by default) and the rank.
	 *
	 * @return void
	 */
	public function test_capabilities(): void {
		$capabilities = $this->method->capabilities();

		$this->assertSame( 'otherguise', $this->method->id() );
		$this->assertTrue( $capabilities['multiple_posts'] );
		$this->assertSame( array( 'modes', 'position' ), array_column( $capabilities['fields'], 'key' ) );
		$this->assertSame( 'multiselect', $capabilities['fields'][0]['type'] );
		$this->assertSame( array( 'web', 'print' ), array_column( $capabilities['fields'][0]['options'], 'value' ) );
		$this->assertSame( array( 'web', 'print' ), $capabilities['fields'][0]['default'] );
		$this->assertSame( 'integer', $capabilities['fields'][1]['type'] );
		$this->assertNull( $capabilities['fields'][1]['default'] );
	}

	/**
	 * Attaching with no data shows the item in every mode, makes the post the primary parent, and is idempotent.
	 *
	 * @return void
	 */
	public function test_attach_with_the_defaults(): void {
		$this->assertTrue( $this->method->attach( 88, 12, array() ) );

		$described = $this->method->describe( array( 88 ), 12 )[88];
		$this->assertTrue( $described['attached_here'] );
		$this->assertSame( array(), $described['elsewhere'] );
		$this->assertNull( $described['protected'] );
		$this->assertSame(
			array(
				'modes'    => array( 'web', 'print' ),
				'position' => null,
			),
			$described['data']
		);
		$this->assertSame( 12, $this->parents->get( 88 ) );

		$total = $this->total();

		$this->assertTrue( $this->method->attach( 88, 12, array() ) );
		$this->assertSame( $total, $this->total() );
	}

	/**
	 * The same item in a second post: its own link and modes; the primary parent stays the first post.
	 *
	 * @return void
	 */
	public function test_several_posts_and_the_primary_parent(): void {
		$this->method->attach( 88, 12, array( 'modes' => array( 'web' ) ) );
		$this->method->attach(
			88,
			13,
			array(
				'modes' => array( 'print' ),
				'position' => '3',
			)
		);

		$this->assertSame( 12, $this->parents->get( 88 ) );
		$this->assertSame( array( 88 => array( 12, 13 ) ), $this->method->posts_of( array( 88 ) ) );

		$in_12 = $this->method->describe( array( 88 ), 12 )[88];
		$in_13 = $this->method->describe( array( 88 ), 13 )[88];

		$this->assertSame( array( 13 ), $in_12['elsewhere'] );
		$this->assertSame(
			array(
				'modes' => array( 'web' ),
				'position' => null,
			),
			$in_12['data']
		);
		$this->assertSame( array( 12 ), $in_13['elsewhere'] );
		$this->assertSame(
			array(
				'modes' => array( 'print' ),
				'position' => 3,
			),
			$in_13['data']
		);
	}

	/**
	 * An attachment that is attached nowhere is described, with nothing to say; a post that is not the one asked for is "elsewhere".
	 *
	 * @return void
	 */
	public function test_describe_an_unattached_item(): void {
		$described = $this->method->describe( array( 90, 91 ), 12 );

		$this->assertSame( array( 90, 91 ), array_keys( $described ) );
		$this->assertFalse( $described[90]['attached_here'] );
		$this->assertSame( array(), $described[90]['data'] );
		$this->assertSame(
			array(
				90 => array(),
				91 => array(),
			),
			$this->method->posts_of( array( 90, 91 ) )
		);

		$this->method->attach( 90, 13, array() );
		$this->assertSame( array( 13 ), $this->method->describe( array( 90 ), 12 )[90]['elsewhere'] );
		$this->assertFalse( $this->method->describe( array( 90 ), 0 )[90]['attached_here'], 'No post, no link here.' );
	}

	/**
	 * What is not valid is refused, and nothing is stored.
	 *
	 * @return void
	 */
	public function test_refusals_store_nothing(): void {
		$before = $this->total();

		foreach ( array(
			'unknown mode'   => array( 'modes' => array( 'book' ) ),
			'not a list'     => array( 'modes' => 'web' ),
			'a duplicate'    => array( 'modes' => array( 'web', 'web' ) ),
			'a non string'   => array( 'modes' => array( 5 ) ),
			'a bad position' => array( 'position' => 'first' ),
			'a float'        => array( 'position' => 1.5 ),
		) as $name => $data ) {
			$this->assertSame( 'invalid_data', $this->code( $this->method->attach( 88, 12, $data ) ), $name );
		}

		$this->assertSame( 'attach_failed', $this->code( $this->method->attach( 999, 12, array() ) ), 'An attachment that does not exist.' );
		$this->assertSame( 'attach_failed', $this->code( $this->method->attach( 88, 999, array() ) ), 'A post that does not exist.' );
		$this->assertSame( $before, $this->total() );
		$this->assertSame( array(), $this->parents->parents );
	}

	/**
	 * Without the right to change the attachment, nothing is done.
	 *
	 * @return void
	 */
	public function test_not_allowed(): void {
		$this->method->attach( 88, 12, array() );

		$this->allowed = false;
		$before        = $this->total();

		$this->assertSame( 'not_allowed', $this->code( $this->method->attach( 90, 12, array() ) ) );
		$this->assertSame( 'not_allowed', $this->code( $this->method->detach( 88, 12 ) ) );
		$this->assertSame( 'not_allowed', $this->code( $this->method->update( 88, 12, array( 'position' => 2 ) ) ) );
		$this->assertSame( $before, $this->total() );
		$this->assertTrue( $this->method->detach( 90, 12 ), 'Detaching what is not attached needs no right.' );
	}

	/**
	 * Update changes only the keys given; a rank can be taken away; an unattached item cannot be updated.
	 *
	 * @return void
	 */
	public function test_update(): void {
		$this->method->attach(
			88,
			12,
			array(
				'modes' => array( 'web', 'print' ),
				'position' => 4,
			)
		);

		$this->assertTrue( $this->method->update( 88, 12, array( 'modes' => array( 'print' ) ) ) );
		$this->assertSame(
			array(
				'modes' => array( 'print' ),
				'position' => 4,
			),
			$this->method->describe( array( 88 ), 12 )[88]['data']
		);

		$this->assertTrue( $this->method->update( 88, 12, array( 'position' => 1 ) ) );
		$this->assertSame(
			array(
				'modes' => array( 'print' ),
				'position' => 1,
			),
			$this->method->describe( array( 88 ), 12 )[88]['data']
		);

		$this->assertTrue( $this->method->update( 88, 12, array( 'position' => null ) ) );
		$this->assertNull( $this->method->describe( array( 88 ), 12 )[88]['data']['position'] );

		$this->assertTrue( $this->method->update( 88, 12, array( 'modes' => array() ) ) );
		$this->assertSame( array(), $this->method->describe( array( 88 ), 12 )[88]['data']['modes'], 'No mode: shown in none, still linked.' );
		$this->assertTrue( $this->method->describe( array( 88 ), 12 )[88]['attached_here'] );

		$this->assertSame( 'not_attached', $this->code( $this->method->update( 90, 12, array( 'position' => 1 ) ) ) );
		$this->assertSame( 'invalid_data', $this->code( $this->method->update( 88, 12, array( 'modes' => array( 'book' ) ) ) ) );
	}

	/**
	 * Detaching removes the link, its modes and its rank; the primary parent moves to the oldest link that remains, then to none.
	 *
	 * @return void
	 */
	public function test_detach_and_the_primary_parent(): void {
		$this->method->attach( 88, 12, array( 'position' => 2 ) );
		$this->method->attach( 88, 13, array() );
		$this->method->attach( 88, 14, array() );

		$this->assertTrue( $this->method->detach( 88, 13 ) );
		$this->assertSame( 12, $this->parents->get( 88 ), 'Not the primary parent: untouched.' );

		$this->assertTrue( $this->method->detach( 88, 12 ) );
		$this->assertSame( 14, $this->parents->get( 88 ), 'The oldest link that remains.' );
		$this->assertSame( array( 88 => array( 14 ) ), $this->method->posts_of( array( 88 ) ) );
		$this->assertSame( array(), $this->site->statements->match( null, 'triples/position' ), 'The rank went with the link.' );

		$this->assertTrue( $this->method->detach( 88, 14 ) );
		$this->assertSame( 0, $this->parents->get( 88 ), 'None remains: cleared.' );
		$this->assertSame( array(), $this->site->statements->match( null, 'media/illustrated-by' ) );
		$this->assertSame( array(), $this->site->statements->match( null, 'modes/mode' ), 'The modes went with the links.' );
		$this->assertTrue( $this->method->detach( 88, 14 ), 'Idempotent.' );
	}

	/**
	 * The attachments of a post: by mode (the reading rule), pinned ranks first, with a MIME filter, unfiltered without a mode.
	 *
	 * @return void
	 */
	public function test_attached(): void {
		$this->method->attach( 88, 12, array( 'modes' => array( 'web', 'print' ) ) );
		$this->method->attach( 90, 12, array( 'modes' => array( 'web' ) ) );
		$this->method->attach( 91, 12, array( 'modes' => array( 'print' ) ) );
		$this->method->attach( 91, 13, array( 'modes' => array( 'web' ) ) );

		$this->assertSame( array( 88, 90 ), $this->method->attached( 12, array( 'context' => 'web' ) ) );
		$this->assertSame( array( 88, 91 ), $this->method->attached( 12, array( 'context' => 'print' ) ) );
		$this->assertSame( array( 88, 90, 91 ), $this->method->attached( 12, array() ), 'No context: nothing is filtered.' );
		$this->assertSame( array( 88, 90, 91 ), $this->method->attached( 12, array( 'context' => 'book' ) ), 'A context that is no mode: nothing is filtered.' );
		$this->assertSame( array( 91 ), $this->method->attached( 13, array( 'context' => 'web' ) ) );
		$this->assertSame( array(), $this->method->attached( 13, array( 'context' => 'print' ) ) );
		$this->assertSame( array(), $this->method->attached( 14, array() ) );

		$this->assertSame( array( 88, 91 ), $this->method->attached( 12, array( 'mime_type' => 'image' ) ), 'A family.' );
		$this->assertSame( array( 90 ), $this->method->attached( 12, array( 'mime_type' => 'application/gpx+xml' ) ), 'A whole type.' );
		$this->assertSame(
			array( 88 ),
			$this->method->attached(
				12,
				array(
					'mime_type' => 'image',
					'context' => 'web',
				)
			)
		);

		$this->method->update( 91, 12, array( 'position' => 1 ) );
		$this->assertSame( array( 91, 88 ), $this->method->attached( 12, array( 'context' => 'print' ) ), 'The pinned rank comes first.' );
	}

	/**
	 * Remove from the library is refused while the item is linked; the trash is accepted.
	 *
	 * @return void
	 */
	public function test_may_remove(): void {
		$this->assertTrue( $this->method->may_remove( 88 ) );

		$this->method->attach( 88, 12, array() );

		$this->assertSame( 'attached', $this->code( $this->method->may_remove( 88 ) ) );
		$this->assertSame( 'attached', $this->code( $this->method->may_remove( 88, 'remove' ) ) );
		$this->assertTrue( $this->method->may_remove( 88, 'trash' ) );

		$this->method->detach( 88, 12 );
		$this->assertTrue( $this->method->may_remove( 88 ) );
	}

	/**
	 * The module declares the predicate and, only when Media Helper offers contract 1, adds the method.
	 *
	 * @return void
	 */
	public function test_the_module(): void {
		$hooks  = array();
		$module = new MediaModule(
			static function ( $hook, $callback, $priority = 10, $accepted = 1 ) use ( &$hooks ) {
				$hooks[ $hook ][] = array( $callback[1], $priority, $accepted );
			},
			static fn() => 1
		);

		$this->assertSame( 'media', $module->id() );
		$this->assertSame( array( 'triples', 'modes' ), $module->dependencies() );

		$module->activate();
		$module->uninstall();
		$module->boot();

		$this->assertSame(
			array(
				'triples_register_predicates'       => array( array( 'register_predicate', 10, 1 ) ),
				'wp_media_helper_attachment_methods' => array( array( 'add_method', 10, 1 ) ),
			),
			$hooks
		);

		Modules::set( new ModuleLoader( array( $this->site->triples, $this->site->modes ), array( 'triples', 'modes' ) ) );

		$methods = $module->add_method( array( 'native' => 'the native one' ) );

		$this->assertSame( array( 'native', 'otherguise' ), array_keys( $methods ) );
		$this->assertInstanceOf( MediaHelperMethod::class, $methods['otherguise'] );

		foreach ( array( 0, 2 ) as $version ) {
			$other = new MediaModule( static function () {}, static fn() => $version );

			$this->assertSame( array( 'native' => 'x' ), $other->add_method( array( 'native' => 'x' ) ), 'Contract ' . $version . ': nothing added.' );
		}

		$this->assertSame( 'not an array', $module->add_method( 'not an array' ) );

		Modules::set( null );
		$this->assertSame( array( 'native' => 'x' ), $module->add_method( array( 'native' => 'x' ) ), 'Without the modules: nothing added.' );
	}
}
