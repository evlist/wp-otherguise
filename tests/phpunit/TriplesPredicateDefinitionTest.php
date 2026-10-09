<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the predicate definitions.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Predicate\PredicateDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the predicate definitions.
 *
 * @covers \Otherguise\Triples\Predicate\DefinitionReader
 * @covers \Otherguise\Triples\Predicate\PredicateDefinition
 */
class TriplesPredicateDefinitionTest extends TestCase {

	/**
	 * Returns a valid minimal definition, with overrides.
	 *
	 * @param array<string, mixed> $overrides Values replacing the defaults.
	 * @return array<string, mixed>
	 */
	private function args( array $overrides = array() ) {
		return array_merge(
			array(
				'slug'  => 'triples/related-to',
				'label' => 'Related to',
			),
			$overrides
		);
	}

	/**
	 * A minimal definition gets the defaults.
	 *
	 * @return void
	 */
	public function test_a_minimal_definition_gets_the_defaults(): void {
		$predicate = PredicateDefinition::from_array( $this->args() );

		$this->assertSame( 'triples/related-to', $predicate->slug() );
		$this->assertSame( 'Related to', $predicate->label() );
		$this->assertNull( $predicate->inverse_label() );
		$this->assertSame( array(), $predicate->subject_types() );
		$this->assertSame( array(), $predicate->object_types() );
		$this->assertNull( $predicate->max_objects_per_subject() );
		$this->assertNull( $predicate->max_subjects_per_object() );
		$this->assertFalse( $predicate->is_symmetric() );
		$this->assertSame( 'remove', $predicate->on_delete() );
		$this->assertNull( $predicate->iri() );
		$this->assertSame( array(), $predicate->qualified_by() );
		$this->assertSame( array(), $predicate->qualifies() );
	}

	/**
	 * A complete definition is kept.
	 *
	 * @return void
	 */
	public function test_a_complete_definition_is_kept(): void {
		$predicate = PredicateDefinition::from_array(
			$this->args(
				array(
					'slug'                    => 'modes/has-variant',
					'inverse_label'           => 'Variant of',
					'subject_types'           => array( 'template' ),
					'object_types'            => array( 'template', 'string' ),
					'max_objects_per_subject' => 1,
					'max_subjects_per_object' => 3,
					'on_delete'               => 'keep',
					'iri'                     => 'https://example.org/vocab#hasVariant',
					'qualified_by'            => array( 'modes/mode', 'triples/position', 'modes/mode' ),
					'qualifies'               => array( 'books/contains', '*' ),
				)
			)
		);

		$this->assertSame( 'Variant of', $predicate->inverse_label() );
		$this->assertSame( array( 'template' ), $predicate->subject_types() );
		$this->assertSame( array( 'template', 'string' ), $predicate->object_types() );
		$this->assertSame( 1, $predicate->max_objects_per_subject() );
		$this->assertSame( 3, $predicate->max_subjects_per_object() );
		$this->assertSame( 'keep', $predicate->on_delete() );
		$this->assertSame( 'https://example.org/vocab#hasVariant', $predicate->iri() );
		$this->assertSame( array( 'modes/mode', 'triples/position' ), $predicate->qualified_by(), 'Duplicates are dropped.' );
		$this->assertSame( array( 'books/contains', '*' ), $predicate->qualifies() );
	}

	/**
	 * A slug of 64 characters is accepted.
	 *
	 * @return void
	 */
	public function test_a_slug_of_64_characters_is_accepted(): void {
		$slug = 'owner/' . str_repeat( 'a', 58 );

		$this->assertSame( 64, strlen( $slug ) );
		$this->assertSame( $slug, PredicateDefinition::from_array( $this->args( array( 'slug' => $slug ) ) )->slug() );
		$this->assertTrue( PredicateDefinition::is_valid_slug( 'books/contains' ) );
		$this->assertFalse( PredicateDefinition::is_valid_slug( 'contains' ) );
		$this->assertFalse( PredicateDefinition::is_valid_slug( 12 ) );
	}

	/**
	 * Invalid definitions are rejected.
	 *
	 * @dataProvider invalid_definitions
	 *
	 * @param array<string, mixed> $overrides Values making the definition invalid.
	 */
	public function test_invalid_definitions_are_rejected( array $overrides ): void {
		$this->expectException( InvalidArgumentException::class );

		PredicateDefinition::from_array( $this->args( $overrides ) );
	}

	/**
	 * Invalid definitions.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function invalid_definitions(): array {
		return array(
			'slug without owner'        => array( array( 'slug' => 'related-to' ) ),
			'slug with upper case'      => array( array( 'slug' => 'Triples/Related' ) ),
			'slug with two slashes'     => array( array( 'slug' => 'a/b/c' ) ),
			'slug with newline'         => array( array( 'slug' => "triples/related\n" ) ),
			'slug of 65 characters'     => array( array( 'slug' => 'owner/' . str_repeat( 'a', 59 ) ) ),
			'iri with newline'          => array( array( 'iri' => "http://example.org/p\n" ) ),
			'empty label'               => array( array( 'label' => '' ) ),
			'unknown key'               => array( array( 'colour' => 'red' ) ),
			'removed key qualifiers'    => array( array( 'qualifiers' => array() ) ),
			'removed key ordered'       => array( array( 'ordered' => true ) ),
			'removed key allow_repeats' => array( array( 'allow_repeats' => true ) ),
			'types not a list'          => array( array( 'subject_types' => 'post' ) ),
			'invalid type slug'         => array( array( 'object_types' => array( 'Post' ) ) ),
			'type slug too long'        => array( array( 'object_types' => array( str_repeat( 'a', 21 ) ) ) ),
			'zero limit'                => array( array( 'max_objects_per_subject' => 0 ) ),
			'negative limit'            => array( array( 'max_subjects_per_object' => -1 ) ),
			'string limit'              => array( array( 'max_objects_per_subject' => '1' ) ),
			'flag not a boolean'        => array( array( 'symmetric' => 'yes' ) ),
			'unknown on_delete'         => array( array( 'on_delete' => 'cascade' ) ),
			'iri with a space'          => array( array( 'iri' => 'http://a b' ) ),
			'relative iri'              => array( array( 'iri' => 'vocab/hasVariant' ) ),
			'qualified_by not a list'   => array( array( 'qualified_by' => 'modes/mode' ) ),
			'qualified_by bad slug'     => array( array( 'qualified_by' => array( 'mode' ) ) ),
			'wildcard in qualified_by'  => array( array( 'qualified_by' => array( '*' ) ) ),
			'qualifies bad slug'        => array( array( 'qualifies' => array( 'Books' ) ) ),
		);
	}
}
