<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the datatypes and of their registry.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Datatype\DatatypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the datatypes.
 *
 * @covers \Otherguise\Triples\Datatype\BooleanDatatype
 * @covers \Otherguise\Triples\Datatype\DatatypeRegistry
 * @covers \Otherguise\Triples\Datatype\IntegerDatatype
 * @covers \Otherguise\Triples\Datatype\StringDatatype
 */
class TriplesDatatypeTest extends TestCase {

	/**
	 * Returns a built-in datatype.
	 *
	 * @param string $name Datatype name.
	 * @return \Otherguise\Triples\Datatype\DatatypeInterface
	 */
	private function datatype( $name ) {
		return DatatypeRegistry::with_builtins()->get( $name );
	}

	/**
	 * The built in datatypes are registered with their xsd name.
	 *
	 * @return void
	 */
	public function test_the_built_in_datatypes_are_registered_with_their_xsd_name(): void {
		$datatypes = DatatypeRegistry::with_builtins()->all();

		$this->assertSame( array( 'string', 'integer', 'boolean' ), array_keys( $datatypes ) );
		$this->assertSame( 'xsd:string', $datatypes['string']->datatype() );
		$this->assertSame( 'xsd:integer', $datatypes['integer']->datatype() );
		$this->assertSame( 'xsd:boolean', $datatypes['boolean']->datatype() );
	}

	/**
	 * A string is at most 191 bytes without control characters.
	 *
	 * @return void
	 */
	public function test_a_string_is_at_most_191_bytes_without_control_characters(): void {
		$type = $this->datatype( 'string' );

		$this->assertTrue( $type->validate( 'print' ) );
		$this->assertTrue( $type->validate( '' ) );
		$this->assertTrue( $type->validate( str_repeat( 'a', 191 ) ) );
		$this->assertFalse( $type->validate( str_repeat( 'a', 192 ) ) );
		$this->assertTrue( $type->validate( str_repeat( 'é', 95 ) ) );
		$this->assertFalse( $type->validate( str_repeat( 'é', 96 ) ), 'The limit is in bytes.' );
		$this->assertFalse( $type->validate( "two\nlines" ) );
		$this->assertFalse( $type->validate( "\xff\xfe" ), 'Invalid UTF-8 is refused.' );
		$this->assertFalse( $type->validate( 12 ) );
		$this->assertFalse( $type->validate( null ) );
		$this->assertSame( 'print', $type->normalize( 'print' ) );
	}

	/**
	 * An integer accepts integers and their decimal strings.
	 *
	 * @return void
	 */
	public function test_an_integer_accepts_integers_and_their_decimal_strings(): void {
		$type = $this->datatype( 'integer' );

		$this->assertTrue( $type->validate( 12 ) );
		$this->assertTrue( $type->validate( '-3' ) );
		$this->assertTrue( $type->validate( '0' ) );
		$this->assertFalse( $type->validate( '1.5' ) );
		$this->assertFalse( $type->validate( 1.5 ) );
		$this->assertFalse( $type->validate( 'abc' ) );
		$this->assertFalse( $type->validate( '' ) );
		$this->assertFalse( $type->validate( "12\n" ) );
		$this->assertFalse( $type->validate( '99999999999999999999' ) );
		$this->assertSame( '12', $type->normalize( 12 ) );
		$this->assertSame( '-3', $type->normalize( '-3' ) );
		$this->assertSame( '7', $type->normalize( '007' ) );
	}

	/**
	 * A boolean is normalized to one or zero.
	 *
	 * @return void
	 */
	public function test_a_boolean_is_normalized_to_one_or_zero(): void {
		$type = $this->datatype( 'boolean' );

		foreach ( array( true, 1, '1', 'true' ) as $truthy ) {
			$this->assertTrue( $type->validate( $truthy ) );
			$this->assertSame( '1', $type->normalize( $truthy ) );
		}

		foreach ( array( false, 0, '0', 'false' ) as $falsy ) {
			$this->assertTrue( $type->validate( $falsy ) );
			$this->assertSame( '0', $type->normalize( $falsy ) );
		}

		$this->assertFalse( $type->validate( 'yes' ) );
		$this->assertFalse( $type->validate( 2 ) );
		$this->assertFalse( $type->validate( null ) );
	}

	/**
	 * A datatype cannot be registered twice.
	 *
	 * @return void
	 */
	public function test_a_datatype_cannot_be_registered_twice(): void {
		$registry = DatatypeRegistry::with_builtins();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Datatype "string" is already registered.' );

		$registry->register( $registry->get( 'string' ) );
	}

	/**
	 * An unknown datatype is reported.
	 *
	 * @return void
	 */
	public function test_an_unknown_datatype_is_reported(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown datatype "ghost".' );

		DatatypeRegistry::with_builtins()->get( 'ghost' );
	}
}
