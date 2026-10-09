<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the entity references.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityRef;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the entity references.
 *
 * @covers \Otherguise\Triples\Entity\EntityRef
 */
class TriplesEntityRefTest extends TestCase {

	/**
	 * A reference is parsed and formatted.
	 *
	 * @return void
	 */
	public function test_a_reference_is_parsed_and_formatted(): void {
		$ref = EntityRef::parse( 'post:123' );

		$this->assertSame( 'post', $ref->type() );
		$this->assertSame( '123', $ref->id() );
		$this->assertSame( 'post:123', (string) $ref );
	}

	/**
	 * The id may contain colons and slashes.
	 *
	 * @return void
	 */
	public function test_the_id_may_contain_colons_and_slashes(): void {
		$this->assertSame( 'abc:def', EntityRef::parse( 'ext:abc:def' )->id() );
		$this->assertSame( 'twentytwentyfive//single', EntityRef::parse( 'template:twentytwentyfive//single' )->id() );
	}

	/**
	 * Two references with the same type and id are equal.
	 *
	 * @return void
	 */
	public function test_two_references_with_the_same_type_and_id_are_equal(): void {
		$this->assertTrue( EntityRef::parse( 'term:45' )->equals( new EntityRef( 'term', '45' ) ) );
		$this->assertFalse( EntityRef::parse( 'term:45' )->equals( EntityRef::parse( 'term:46' ) ) );
		$this->assertFalse( EntityRef::parse( 'term:45' )->equals( EntityRef::parse( 'post:45' ) ) );
	}

	/**
	 * Invalid references are rejected.
	 *
	 * @dataProvider invalid_references
	 *
	 * @param string $value Invalid reference.
	 */
	public function test_invalid_references_are_rejected( $value ): void {
		$this->expectException( InvalidArgumentException::class );

		EntityRef::parse( $value );
	}

	/**
	 * Invalid references.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function invalid_references(): array {
		return array(
			'no colon'          => array( 'post123' ),
			'empty type'        => array( ':123' ),
			'empty id'          => array( 'post:' ),
			'upper case type'   => array( 'Post:1' ),
			'type with space'   => array( 'my type:1' ),
			'id with space'     => array( 'post:1 2' ),
			'id with newline'   => array( "post:1\n" ),
			'type with newline' => array( "post\n:1" ),
			'type too long'     => array( str_repeat( 'a', 21 ) . ':1' ),
			'id too long'       => array( 'post:' . str_repeat( 'a', 192 ) ),
		);
	}
}
