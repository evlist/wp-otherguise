<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the modes with the Triples module, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Integration\TriplesIntegration;
use Otherguise\Modes\Mode\ModeDefinition;
use Otherguise\Modes\Module as ModesModule;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Module as TriplesModule;
use Otherguise\Triples\Predicate\PredicateDefinition;
use Otherguise\Triples\Statements;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';

/**
 * The entity type `mode` and the predicate `modes/mode` as Triples sees them.
 *
 * @covers \Otherguise\Modes\Integration\TriplesIntegration
 */
class ModesTriplesDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Modes module.
	 *
	 * @var ModesModule
	 */
	private $modes;

	/**
	 * Service.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Triples module.
	 *
	 * @var TriplesModule
	 */
	private $triples;

	/**
	 * Builds a Triples module on which the modes register, and a predicate that the modes can qualify.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Otherguise_Test_Fixtures::objects();

		$this->modes       = new ModesModule( static function () {}, static function () {}, static fn() => array() );
		$integration       = new TriplesIntegration( $this->modes->modes() );
		$this->triples     = new TriplesModule(
			static function ( $hook, $registry ) use ( $integration ) {
				if ( 'triples_register_entity_types' === $hook ) {
					$integration->register_entity_type( $registry );
				}

				if ( 'triples_register_predicates' === $hook ) {
					$integration->register_predicate( $registry );
					$registry->register(
						PredicateDefinition::from_array(
							array(
								'slug'          => 'test/illustrated-by',
								'label'         => 'Illustrated by',
								'subject_types' => array( 'post' ),
								'object_types'  => array( 'attachment' ),
								'qualified_by'  => array( 'modes/mode' ),
							)
						)
					);
				}
			},
			$this->wpdb
		);
		$this->statements  = $this->triples->statements();
	}

	/**
	 * A mode can be given as the object that stands for it, as a reference, and read back.
	 *
	 * @return void
	 */
	public function test_modes_as_objects_and_references(): void {
		$link  = $this->statements->triple( get_post( 12 ), 'test/illustrated-by', get_post( 88 ) );
		$print = $this->statements->triple( $link, 'modes/mode', $this->modes->modes()->get( 'print' ) );
		$again = $this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'print' ) );

		$this->assertSame( $print->id(), $again->id() );
		$this->assertSame( 'mode:print', (string) $print->object() );
		$this->assertInstanceOf( ModeDefinition::class, $this->statements->resolve( $print->object() ) );
		$this->assertSame( 'Print', $this->statements->describe( $print->object() )['label'] );
		$this->assertTrue( $this->statements->describe( $print->object() )['exists'] );
	}

	/**
	 * Only declared modes exist, and only statements can be put in a mode.
	 *
	 * @return void
	 */
	public function test_only_declared_modes_and_only_statements(): void {
		$link = $this->statements->triple( get_post( 12 ), 'test/illustrated-by', get_post( 88 ) );

		$this->assertSame( 'object_missing', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'ghost' ) ) ) );
		$this->assertSame( 'invalid_id', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'Print' ) ) ) );
		$this->assertSame( 'subject_type_not_allowed', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->triple( get_post( 12 ), 'modes/mode', new EntityRef( 'mode', 'print' ) ) ) );
		$this->assertSame( 'ambiguous_literal', Otherguise_Test_Fixtures::refusal( fn() => $this->statements->triple( $link, 'modes/mode', 'print' ) ), 'A bare string is a literal, and this predicate takes no literal.' );
	}

	/**
	 * A listing scoped to a mode given as a mode object: the reading rule of the slice 103 with the real predicate.
	 *
	 * @return void
	 */
	public function test_a_listing_scoped_to_a_mode_object(): void {
		$web   = $this->statements->triple( get_post( 12 ), 'test/illustrated-by', get_post( 88 ) );
		$print = $this->statements->triple( get_post( 12 ), 'test/illustrated-by', get_post( 90 ) );
		$none  = $this->statements->triple( get_post( 12 ), 'test/illustrated-by', get_post( 91 ) );

		$this->statements->triple( $web, 'modes/mode', $this->modes->modes()->get( 'web' ) );
		$this->statements->triple( $web, 'modes/mode', $this->modes->modes()->get( 'print' ) );
		$this->statements->triple( $print, 'modes/mode', $this->modes->modes()->get( 'print' ) );

		$ids = static fn( array $statements ) => array_map( static fn( $statement ) => $statement->id(), $statements );

		$this->assertSame( array( $web->id() ), $ids( $this->statements->listing( get_post( 12 ), 'test/illustrated-by', array( 'scope' => array( 'modes/mode', $this->modes->modes()->get( 'web' ) ) ) ) ) );
		$this->assertSame( array( $web->id(), $print->id() ), $ids( $this->statements->listing( get_post( 12 ), 'test/illustrated-by', array( 'scope' => array( 'modes/mode', new EntityRef( 'mode', 'print' ) ) ) ) ) );
		$this->assertSame( array( $web->id(), $print->id(), $none->id() ), $ids( $this->statements->listing( get_post( 12 ), 'test/illustrated-by' ) ) );
	}
}
