<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the checks that refuse a statement before the store is reached.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\EntityType;
use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Service\InvalidStatementException;
use Otherguise\Triples\Statements;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-forbidden-wpdb.php';

/**
 * No database: the module is built on an object that fails when the store is reached.
 *
 * @covers \Otherguise\Triples\Service\EntityResolver
 * @covers \Otherguise\Triples\Service\InvalidStatementException
 * @covers \Otherguise\Triples\Service\StatementValidator
 * @covers \Otherguise\Triples\Statements
 */
class TriplesStatementsCheckTest extends TestCase {

	/**
	 * Service.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Builds the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->statements = Otherguise_Test_Fixtures::module( new Otherguise_Test_Forbidden_Wpdb() )->statements();
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
	 * Asserts that a triple is refused with a code before the store is reached.
	 *
	 * @param string $code      Expected error code.
	 * @param mixed  $subject   Subject.
	 * @param string $predicate Predicate.
	 * @param mixed  $target    Object.
	 * @return void
	 */
	private function assertRefused( $code, $subject, $predicate, $target ) {
		$this->assertSame( $code, Otherguise_Test_Fixtures::refusal( fn() => $this->statements->triple( $subject, $predicate, $target ) ) );
		$this->assertSame( $code, Otherguise_Test_Fixtures::refusal( fn() => $this->statements->create( $subject, $predicate, $target ) ) );
		$this->assertSame( $code, Otherguise_Test_Fixtures::refusal( fn() => $this->statements->check( $subject, $predicate, $target ) ) );
	}

	/**
	 * An unregistered or malformed predicate.
	 *
	 * @return void
	 */
	public function test_unknown_predicate(): void {
		$this->assertRefused( 'unknown_predicate', get_post( 12 ), 'nobody/knows', get_post( 88 ) );
		$this->assertRefused( 'unknown_predicate', get_post( 12 ), 'Not A Slug', get_post( 88 ) );
		$this->assertRefused( 'unknown_predicate', get_post( 12 ), 42, get_post( 88 ) );
	}

	/**
	 * Values that are not entities, bare ids included.
	 *
	 * @return void
	 */
	public function test_unknown_entity(): void {
		foreach ( array( 12, '12', 'post:12', null, array( 12 ), new stdClass(), new Literal( 'integer', '1' ) ) as $subject ) {
			$this->assertRefused( 'unknown_entity', $subject, 'media/illustrated-by', Ref::attachment( 88 ) );
		}

		$this->assertRefused( 'unknown_entity', get_post( 12 ), 'media/illustrated-by', new stdClass() );
		$this->assertRefused( 'unknown_entity', get_post( 12 ), 'media/illustrated-by', null );
		$this->assertRefused( 'subject_missing', new WP_Post( 5 ), 'media/illustrated-by', Ref::attachment( 88 ) );
	}

	/**
	 * Two types that claim the same object.
	 *
	 * @return void
	 */
	public function test_ambiguous_entity(): void {
		$module = Otherguise_Test_Fixtures::module( new Otherguise_Test_Forbidden_Wpdb() );
		$module->entity_types()->register( new EntityType( 'wterm', 'Term again', fn( $id ) => true, null, null, fn( $value ) => $value instanceof WP_Term ? $value->term_id : null ) );
		$module->entity_types()->register( new EntityType( 'wterm2', 'Term once more', fn( $id ) => true, null, null, fn( $value ) => $value instanceof WP_Term ? $value->term_id : null ) );

		$code = Otherguise_Test_Fixtures::refusal( fn() => $module->statements()->triple( get_term( 7 ), 'test/anything', get_post( 12 ) ) );

		$this->assertSame( 'ambiguous_entity', $code );
	}

	/**
	 * A reference to a type nobody registered, and a malformed id.
	 *
	 * @return void
	 */
	public function test_unknown_type_and_invalid_id(): void {
		$this->assertRefused( 'unknown_type', new EntityRef( 'ghost', '1' ), 'test/anything', Ref::post( 12 ) );
		$this->assertRefused( 'unknown_type', Ref::post( 12 ), 'test/anything', new EntityRef( 'integer', '1' ) );
		$this->assertRefused( 'unknown_type', Ref::post( 12 ), 'test/note', new Literal( 'color', 'red' ) );
		$this->assertRefused( 'invalid_id', new EntityRef( 'post', '007' ), 'test/anything', Ref::post( 12 ) );
		$this->assertRefused( 'invalid_id', Ref::post( 12 ), 'test/anything', new EntityRef( 'post', 'abc' ) );
	}

	/**
	 * Types that the predicate does not accept; an empty list accepts any entity type.
	 *
	 * @return void
	 */
	public function test_types_not_allowed(): void {
		$this->assertRefused( 'subject_type_not_allowed', get_userdata( 3 ), 'media/illustrated-by', Ref::attachment( 88 ) );
		$this->assertRefused( 'object_type_not_allowed', get_post( 12 ), 'media/illustrated-by', get_post( 13 ) );
		$this->assertRefused( 'object_type_not_allowed', get_post( 12 ), 'test/tag', new Literal( 'integer', '5' ) );
		$this->assertRefused( 'subject_type_not_allowed', get_post( 12 ), 'books/pages', 2 );
	}

	/**
	 * Values the datatype refuses, and values that are too long.
	 *
	 * @return void
	 */
	public function test_invalid_value(): void {
		$this->assertRefused( 'invalid_value', Ref::post( 12 ), 'test/note', new Literal( 'integer', 'abc' ) );
		$this->assertRefused( 'invalid_value', Ref::post( 12 ), 'test/tag', str_repeat( 'é', 100 ) );
		$this->assertRefused( 'invalid_value', Ref::post( 12 ), 'test/tag', 12.5 );
	}

	/**
	 * A scalar needs exactly one datatype in the predicate.
	 *
	 * @return void
	 */
	public function test_ambiguous_literal(): void {
		$this->assertRefused( 'ambiguous_literal', get_post( 12 ), 'test/note', 'hello' );
		$this->assertRefused( 'ambiguous_literal', get_post( 12 ), 'media/illustrated-by', 'hello' );
		$this->assertRefused( 'ambiguous_literal', get_post( 12 ), 'test/anything', 3 );
	}

	/**
	 * Entities that do not exist, found through the existence checks of the types.
	 *
	 * @return void
	 */
	public function test_missing_entities(): void {
		$this->assertRefused( 'subject_missing', Ref::post( 99 ), 'media/illustrated-by', get_post( 88 ) );
		$this->assertRefused( 'object_missing', get_post( 12 ), 'media/illustrated-by', Ref::attachment( 99 ) );
		$this->assertRefused( 'object_missing', get_post( 12 ), 'test/anything', new EntityRef( 'mode', 'ghost' ) );
		$this->assertRefused( 'object_missing', get_post( 12 ), 'test/owner', Ref::user( 9 ) );
	}

	/**
	 * The validator names the checks in order: the predicate comes first, then the subject, then the object.
	 *
	 * @return void
	 */
	public function test_the_checks_run_in_order(): void {
		$this->assertRefused( 'unknown_predicate', Ref::post( 99 ), 'nobody/knows', new stdClass() );
		$this->assertRefused( 'subject_type_not_allowed', Ref::user( 99 ), 'media/illustrated-by', new stdClass() );
	}

	/**
	 * The refusals are exceptions of the standard family, with a code and an escaped message.
	 *
	 * @return void
	 */
	public function test_the_exception_carries_a_code_and_an_escaped_message(): void {
		try {
			$this->statements->triple( get_post( 12 ), '<b>nobody</b>', get_post( 88 ) );
			$this->fail( 'The triple should have been refused.' );
		} catch ( InvalidArgumentException $problem ) {
			$this->assertInstanceOf( InvalidStatementException::class, $problem );
			$this->assertSame( 'unknown_predicate', $problem->error_code() );
			$this->assertStringNotContainsString( '<b>', $problem->getMessage() );
		}
	}
}
