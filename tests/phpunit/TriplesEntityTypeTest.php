<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the entity types and of their registry.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityType;
use Otherguise\Triples\Entity\EntityTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the entity types and of their registry.
 *
 * @covers \Otherguise\Triples\Entity\EntityType
 * @covers \Otherguise\Triples\Entity\EntityTypeRegistry
 * @covers \Otherguise\Triples\Support\LazyRegistry
 */
class TriplesEntityTypeTest extends TestCase {

	/**
	 * The built in types are registered.
	 *
	 * @return void
	 */
	public function test_the_built_in_types_are_registered(): void {
		$registry = EntityTypeRegistry::with_builtins();

		$this->assertSame( array( 'post', 'attachment', 'term', 'user', 'statement' ), array_keys( $registry->all() ) );
	}

	/**
	 * The built in types accept positive integers only.
	 *
	 * @return void
	 */
	public function test_the_built_in_types_accept_positive_integers_only(): void {
		$post = EntityTypeRegistry::with_builtins()->get( 'post' );

		$this->assertTrue( $post->is_valid_id( '1' ) );
		$this->assertTrue( $post->is_valid_id( '123456' ) );
		$this->assertFalse( $post->is_valid_id( '0' ) );
		$this->assertFalse( $post->is_valid_id( '007' ) );
		$this->assertFalse( $post->is_valid_id( '-3' ) );
		$this->assertFalse( $post->is_valid_id( '1.5' ) );
		$this->assertFalse( $post->is_valid_id( 'abc' ) );
		$this->assertFalse( $post->is_valid_id( str_repeat( '9', 19 ) ) );
	}

	/**
	 * A custom type uses its own validator.
	 *
	 * @return void
	 */
	public function test_a_custom_type_uses_its_own_validator(): void {
		$type = new EntityType(
			'template',
			'Template',
			static function ( $id ) {
				return 1 === preg_match( '#^[a-z0-9-]+//[a-z0-9-]+$#', $id );
			}
		);

		$this->assertTrue( $type->is_valid_id( 'theme//single' ) );
		$this->assertFalse( $type->is_valid_id( 'single' ) );
	}

	/**
	 * The iri resolver is optional.
	 *
	 * @return void
	 */
	public function test_the_iri_resolver_is_optional(): void {
		$without = new EntityType(
			'ext',
			'External',
			static function () {
				return true;
			}
		);
		$with    = new EntityType(
			'media',
			'Media',
			static function () {
				return true;
			},
			static function ( $id ) {
				return 'https://example.org/media/' . $id;
			}
		);

		$this->assertNull( $without->iri( '1' ) );
		$this->assertSame( 'https://example.org/media/7', $with->iri( '7' ) );
	}

	/**
	 * An invalid slug is rejected.
	 *
	 * @return void
	 */
	public function test_an_invalid_slug_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntityType(
			'Bad Slug',
			'Bad',
			static function () {
				return true;
			}
		);
	}

	/**
	 * A type cannot be registered twice.
	 *
	 * @return void
	 */
	public function test_a_type_cannot_be_registered_twice(): void {
		$registry = EntityTypeRegistry::with_builtins();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Entity type "post" is already registered.' );

		$registry->register( EntityType::positive_integer( 'post', 'Post' ) );
	}

	/**
	 * An unknown type is reported.
	 *
	 * @return void
	 */
	public function test_an_unknown_type_is_reported(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown entity type "ghost".' );

		EntityTypeRegistry::with_builtins()->get( 'ghost' );
	}

	/**
	 * The initializer runs once and only when the registry is used.
	 *
	 * @return void
	 */
	public function test_the_initializer_runs_once_and_only_when_the_registry_is_used(): void {
		$calls    = 0;
		$registry = EntityTypeRegistry::with_builtins(
			static function ( EntityTypeRegistry $registry ) use ( &$calls ) {
				++$calls;
				$registry->register(
					new EntityType(
						'template',
						'Template',
						static function () {
							return true;
						}
					)
				);
			}
		);

		$this->assertSame( 0, $calls );
		$this->assertTrue( $registry->has( 'template' ) );
		$this->assertTrue( $registry->has( 'post' ) );
		$registry->all();
		$this->assertSame( 1, $calls );
	}

	/**
	 * A slug is at most 20 characters.
	 *
	 * @return void
	 */
	public function test_a_slug_is_at_most_20_characters(): void {
		$validator = static function () {
			return true;
		};

		$this->assertSame( str_repeat( 'a', 20 ), ( new EntityType( str_repeat( 'a', 20 ), 'Long', $validator ) )->slug() );

		$this->expectException( InvalidArgumentException::class );

		new EntityType( str_repeat( 'a', 21 ), 'Too long', $validator );
	}
}
