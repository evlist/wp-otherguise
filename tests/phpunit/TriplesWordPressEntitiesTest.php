<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the recognition, loading and existence checks of the entity types, and of the shorthand references.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityType;
use Otherguise\Triples\Entity\EntityTypeRegistry;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Entity\WordPressEntities;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress functions are stubs: nothing here ran on a real WordPress site.
 *
 * @covers \Otherguise\Triples\Entity\EntityType
 * @covers \Otherguise\Triples\Entity\EntityTypeRegistry
 * @covers \Otherguise\Triples\Entity\Ref
 * @covers \Otherguise\Triples\Entity\WordPressEntities
 */
class TriplesWordPressEntitiesTest extends TestCase {

	/**
	 * Registry of the built-in types with the WordPress behaviors.
	 *
	 * @var EntityTypeRegistry
	 */
	private $types;

	/**
	 * Prepares the stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_wp_objects( array( new WP_Post( 12 ), new WP_Post( 88, 'attachment' ), new WP_Term( 5 ), new WP_User( 3 ) ) );

		$this->types = EntityTypeRegistry::with_builtins( null, WordPressEntities::behaviors() );
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
	 * Returns the slugs of the types that recognize a value.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private function recognizers( $value ) {
		$slugs = array();

		foreach ( $this->types->all() as $slug => $type ) {
			if ( null !== $type->identify( $value ) ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Each WordPress object is recognized by exactly one type.
	 *
	 * @return void
	 */
	public function test_each_object_is_recognized_by_one_type_only(): void {
		$this->assertSame( array( 'post' ), $this->recognizers( new WP_Post( 12 ) ) );
		$this->assertSame( array( 'attachment' ), $this->recognizers( new WP_Post( 88, 'attachment' ) ) );
		$this->assertSame( array( 'term' ), $this->recognizers( new WP_Term( 5 ) ) );
		$this->assertSame( array( 'user' ), $this->recognizers( new WP_User( 3 ) ) );
		$this->assertSame( '88', $this->types->get( 'attachment' )->identify( new WP_Post( 88, 'attachment' ) ) );
	}

	/**
	 * Anything else is recognized by nobody, bare ids included.
	 *
	 * @return void
	 */
	public function test_other_values_are_not_recognized(): void {
		foreach ( array( 12, '12', 'post:12', null, array(), new stdClass(), new WP_User( 0 ) ) as $value ) {
			$this->assertSame( array(), $this->recognizers( $value ), is_object( $value ) ? get_class( $value ) : var_export( $value, true ) );
		}
	}

	/**
	 * Existence follows the stubs of the WordPress functions, and an attachment is not a post.
	 *
	 * @return void
	 */
	public function test_existence_checks(): void {
		$this->assertTrue( $this->types->get( 'post' )->exists( '12' ) );
		$this->assertFalse( $this->types->get( 'post' )->exists( '88' ) );
		$this->assertFalse( $this->types->get( 'post' )->exists( '13' ) );
		$this->assertTrue( $this->types->get( 'attachment' )->exists( '88' ) );
		$this->assertFalse( $this->types->get( 'attachment' )->exists( '12' ) );
		$this->assertTrue( $this->types->get( 'term' )->exists( '5' ) );
		$this->assertFalse( $this->types->get( 'term' )->exists( '6' ) );
		$this->assertTrue( $this->types->get( 'user' )->exists( '3' ) );
		$this->assertFalse( $this->types->get( 'user' )->exists( '4' ) );
	}

	/**
	 * The loader gives the object back, or null.
	 *
	 * @return void
	 */
	public function test_loaders(): void {
		$this->assertInstanceOf( WP_Post::class, $this->types->get( 'post' )->load( '12' ) );
		$this->assertNull( $this->types->get( 'post' )->load( '88' ) );
		$this->assertInstanceOf( WP_Term::class, $this->types->get( 'term' )->load( '5' ) );
		$this->assertInstanceOf( WP_User::class, $this->types->get( 'user' )->load( '3' ) );
		$this->assertNull( $this->types->get( 'user' )->load( '9' ) );
	}

	/**
	 * Without behaviors the built-in types check, recognize and load nothing; the statement type has none either.
	 *
	 * @return void
	 */
	public function test_types_without_behaviors_check_nothing(): void {
		$plain = EntityTypeRegistry::with_builtins()->get( 'post' );

		$this->assertNull( $plain->exists( '12' ) );
		$this->assertNull( $plain->identify( new WP_Post( 12 ) ) );
		$this->assertNull( $plain->load( '12' ) );
		$this->assertNull( $this->types->get( 'statement' )->exists( '1' ) );
	}

	/**
	 * A type with a loader and no existence check exists when the loader finds something.
	 *
	 * @return void
	 */
	public function test_a_loader_stands_for_the_existence_check(): void {
		$type = new EntityType(
			'template',
			'Template',
			static function ( $id ) {
				return '' !== $id;
			},
			null,
			null,
			null,
			static function ( $id ) {
				return 'a' === $id ? new stdClass() : null;
			}
		);

		$this->assertTrue( $type->exists( 'a' ) );
		$this->assertFalse( $type->exists( 'b' ) );
	}

	/**
	 * The shorthand references.
	 *
	 * @return void
	 */
	public function test_shorthand_references(): void {
		$this->assertSame( 'post:12', (string) Ref::post( 12 ) );
		$this->assertSame( 'attachment:88', (string) Ref::attachment( '88' ) );
		$this->assertSame( 'term:5', (string) Ref::term( 5 ) );
		$this->assertSame( 'user:3', (string) Ref::user( 3 ) );
	}
}
