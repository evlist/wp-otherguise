<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the qualifier types and of their registry.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Qualifier\QualifierTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the qualifier types and of their registry.
 *
 * @covers \Otherguise\Triples\Qualifier\BooleanType
 * @covers \Otherguise\Triples\Qualifier\EnumType
 * @covers \Otherguise\Triples\Qualifier\IntegerType
 * @covers \Otherguise\Triples\Qualifier\QualifierTypeRegistry
 * @covers \Otherguise\Triples\Qualifier\StringType
 */
class TriplesQualifierTypeTest extends TestCase {

	/**
	 * Returns a built-in type.
	 *
	 * @param string $name Type name.
	 * @return \Otherguise\Triples\Qualifier\QualifierTypeInterface
	 */
	private function type( $name ) {
		return QualifierTypeRegistry::with_builtins()->get( $name );
	}

	/**
	 * The built in types are registered with their xsd datatype.
	 *
	 * @return void
	 */
	public function test_the_built_in_types_are_registered_with_their_xsd_datatype(): void {
		$types = QualifierTypeRegistry::with_builtins()->all();

		$this->assertSame( array( 'string', 'integer', 'boolean', 'enum' ), array_keys( $types ) );
		$this->assertSame( 'xsd:string', $types['string']->datatype() );
		$this->assertSame( 'xsd:integer', $types['integer']->datatype() );
		$this->assertSame( 'xsd:boolean', $types['boolean']->datatype() );
		$this->assertSame( 'xsd:string', $types['enum']->datatype() );
	}

	/**
	 * A string is a string of at most 255 characters.
	 *
	 * @return void
	 */
	public function test_a_string_is_a_string_of_at_most_255_characters(): void {
		$type = $this->type( 'string' );

		$this->assertTrue( $type->validate( 'print', array() ) );
		$this->assertTrue( $type->validate( '', array() ) );
		$this->assertTrue( $type->validate( str_repeat( 'é', 255 ), array() ) );
		$this->assertFalse( $type->validate( str_repeat( 'é', 256 ), array() ) );
		$this->assertFalse( $type->validate( 12, array() ) );
		$this->assertFalse( $type->validate( null, array() ) );
		$this->assertSame( 'print', $type->normalize( 'print', array() ) );
	}

	/**
	 * An integer accepts integers and their decimal strings.
	 *
	 * @return void
	 */
	public function test_an_integer_accepts_integers_and_their_decimal_strings(): void {
		$type = $this->type( 'integer' );

		$this->assertTrue( $type->validate( 12, array() ) );
		$this->assertTrue( $type->validate( '-3', array() ) );
		$this->assertTrue( $type->validate( '0', array() ) );
		$this->assertFalse( $type->validate( '1.5', array() ) );
		$this->assertFalse( $type->validate( 1.5, array() ) );
		$this->assertFalse( $type->validate( 'abc', array() ) );
		$this->assertFalse( $type->validate( '', array() ) );
		$this->assertFalse( $type->validate( "12\n", array() ) );
		$this->assertFalse( $type->validate( '99999999999999999999', array() ) );
		$this->assertSame( '12', $type->normalize( 12, array() ) );
		$this->assertSame( '-3', $type->normalize( '-3', array() ) );
		$this->assertSame( '7', $type->normalize( '007', array() ) );
	}

	/**
	 * A boolean is normalized to one or zero.
	 *
	 * @return void
	 */
	public function test_a_boolean_is_normalized_to_one_or_zero(): void {
		$type = $this->type( 'boolean' );

		foreach ( array( true, 1, '1', 'true' ) as $truthy ) {
			$this->assertTrue( $type->validate( $truthy, array() ) );
			$this->assertSame( '1', $type->normalize( $truthy, array() ) );
		}

		foreach ( array( false, 0, '0', 'false' ) as $falsy ) {
			$this->assertTrue( $type->validate( $falsy, array() ) );
			$this->assertSame( '0', $type->normalize( $falsy, array() ) );
		}

		$this->assertFalse( $type->validate( 'yes', array() ) );
		$this->assertFalse( $type->validate( 2, array() ) );
		$this->assertFalse( $type->validate( null, array() ) );
	}

	/**
	 * An enum only accepts its values.
	 *
	 * @return void
	 */
	public function test_an_enum_only_accepts_its_values(): void {
		$type    = $this->type( 'enum' );
		$options = array( 'values' => array( 'web', 'print' ) );

		$this->assertTrue( $type->validate( 'web', $options ) );
		$this->assertFalse( $type->validate( 'book', $options ) );
		$this->assertFalse( $type->validate( 'WEB', $options ) );
		$this->assertSame( 'print', $type->normalize( 'print', $options ) );
	}

	/**
	 * An enum needs a non empty list of distinct strings.
	 *
	 * @return void
	 */
	public function test_an_enum_needs_a_non_empty_list_of_distinct_strings(): void {
		$type = $this->type( 'enum' );

		foreach ( array( array(), array( 'values' => array() ), array( 'values' => array( 'a', 'a' ) ), array( 'values' => array( 'a', 1 ) ), array( 'values' => 'a' ) ) as $options ) {
			try {
				$type->validate_options( $options );
				$this->fail( 'Invalid enum options were accepted.' );
			} catch ( InvalidArgumentException $exception ) {
				$this->assertStringContainsString( 'values', $exception->getMessage() );
			}
		}

		$type->validate_options( array( 'values' => array( 'a', 'b' ) ) );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * The other types take no option.
	 *
	 * @return void
	 */
	public function test_the_other_types_take_no_option(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->type( 'string' )->validate_options( array( 'values' => array( 'a' ) ) );
	}

	/**
	 * A type cannot be registered twice.
	 *
	 * @return void
	 */
	public function test_a_type_cannot_be_registered_twice(): void {
		$registry = QualifierTypeRegistry::with_builtins();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Qualifier type "string" is already registered.' );

		$registry->register( $registry->get( 'string' ) );
	}
}
