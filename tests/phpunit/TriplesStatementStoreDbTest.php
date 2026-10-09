<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the statement store, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Datatype\DatatypeRegistry;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Storage\DuplicateStatementException;
use Otherguise\Triples\Storage\SchemaManager;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\StatementQuery;
use Otherguise\Triples\Storage\StatementStore;
use Otherguise\Triples\Storage\Transaction;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';

/**
 * Integration tests of the statement store, on a real database.
 *
 * They run when OTHERGUISE_TEST_DB is set, and are skipped otherwise.
 *
 * @covers \Otherguise\Triples\Storage\Database
 * @covers \Otherguise\Triples\Storage\SchemaManager
 * @covers \Otherguise\Triples\Storage\StatementQuery
 * @covers \Otherguise\Triples\Storage\StatementStore
 * @covers \Otherguise\Triples\Storage\Transaction
 */
class TriplesStatementStoreDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Builds a store with a fixed clock.
	 *
	 * @return StatementStore
	 */
	private function store() {
		return new StatementStore(
			$this->database,
			DatatypeRegistry::with_builtins(),
			static function () {
				return '2026-10-09 12:00:00';
			}
		);
	}

	/**
	 * Builds a statement between two entity references.
	 *
	 * @param string $subject   Subject, as "type:id".
	 * @param string $predicate Predicate.
	 * @param string $object    Object, as "type:id".
	 * @return Statement
	 */
	private function statement( $subject, $predicate, $object ) {
		return new Statement( EntityRef::parse( $subject ), $predicate, EntityRef::parse( $object ) );
	}

	/**
	 * Stores the statements of the example: a photo qualified by two modes and two positions.
	 *
	 * @param StatementStore $store Store.
	 * @return int[] Ids of the five statements.
	 */
	private function store_example( StatementStore $store ) {
		$photo = $store->insert( $this->statement( 'post:12', 'media/illustrated-by', 'attachment:88' ) );
		$web   = $store->insert( new Statement( new EntityRef( 'statement', (string) $photo ), 'modes/mode', new EntityRef( 'mode', 'web' ) ) );
		$print = $store->insert( new Statement( new EntityRef( 'statement', (string) $photo ), 'modes/mode', new EntityRef( 'mode', 'print' ) ) );
		$all   = $store->insert( new Statement( new EntityRef( 'statement', (string) $photo ), 'triples/position', new Literal( 'integer', '5' ) ) );
		$rank  = $store->insert( new Statement( new EntityRef( 'statement', (string) $print ), 'triples/position', new Literal( 'integer', '1' ) ) );

		return array( $photo, $web, $print, $all, $rank );
	}

	/**
	 * A statement is stored and read back.
	 *
	 * @return void
	 */
	public function test_a_statement_is_stored_and_read_back(): void {
		$store = $this->store();
		$id    = $store->insert( $this->statement( 'post:12', 'media/illustrated-by', 'attachment:88' ) );
		$found = $store->find( $id );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( $id, $found->id() );
		$this->assertSame( 'post:12', (string) $found->subject() );
		$this->assertSame( 'media/illustrated-by', $found->predicate() );
		$this->assertSame( 'attachment:88', (string) $found->object() );
		$this->assertSame( '2026-10-09 12:00:00', $found->created_gmt() );
		$this->assertSame( '2026-10-09 12:00:00', $found->updated_gmt() );
		$this->assertNull( $store->find( $id + 100 ) );
	}

	/**
	 * A stored statement cannot be inserted again.
	 *
	 * @return void
	 */
	public function test_a_stored_statement_cannot_be_inserted_again(): void {
		$store = $this->store();
		$id    = $store->insert( $this->statement( 'post:1', 'triples/related-to', 'post:2' ) );

		$this->expectException( InvalidArgumentException::class );

		$store->insert( $store->find( $id ) );
	}

	/**
	 * The same triple cannot be stored twice.
	 *
	 * @return void
	 */
	public function test_the_same_triple_cannot_be_stored_twice(): void {
		$store = $this->store();
		$id    = $store->insert( $this->statement( 'post:12', 'media/illustrated-by', 'attachment:88' ) );

		try {
			$store->insert( $this->statement( 'post:12', 'media/illustrated-by', 'attachment:88' ) );
			$this->fail( 'The duplicate was accepted.' );
		} catch ( DuplicateStatementException $duplicate ) {
			$this->assertSame( $id, $duplicate->existing()->id() );
		}

		$this->assertSame( 1, $store->count( new StatementQuery() ) );
	}

	/**
	 * The same subject and predicate may have several objects.
	 *
	 * @return void
	 */
	public function test_the_same_subject_and_predicate_may_have_several_objects(): void {
		$store = $this->store();
		$photo = $store->insert( $this->statement( 'post:12', 'media/illustrated-by', 'attachment:88' ) );

		$store->insert( new Statement( new EntityRef( 'statement', (string) $photo ), 'modes/mode', new EntityRef( 'mode', 'web' ) ) );
		$store->insert( new Statement( new EntityRef( 'statement', (string) $photo ), 'modes/mode', new EntityRef( 'mode', 'print' ) ) );

		$this->assertSame( 3, $store->count( new StatementQuery() ) );
	}

	/**
	 * Ids that differ by case or accent are different.
	 *
	 * @return void
	 */
	public function test_ids_that_differ_by_case_or_accent_are_different(): void {
		$store = $this->store();
		$upper = $store->insert( $this->statement( 'post:1', 'media/has-video', 'ext:AbCdEf' ) );
		$lower = $store->insert( $this->statement( 'post:1', 'media/has-video', 'ext:abcdef' ) );
		$plain = $store->insert( $this->statement( 'post:1', 'media/has-video', 'ext:cafe' ) );
		$acute = $store->insert( $this->statement( 'post:1', 'media/has-video', 'ext:café' ) );

		$this->assertCount( 4, array_unique( array( $upper, $lower, $plain, $acute ) ) );
		$this->assertSame( $upper, $store->find_by_triple( EntityRef::parse( 'post:1' ), 'media/has-video', EntityRef::parse( 'ext:AbCdEf' ) )->id() );
		$this->assertSame( $lower, $store->find_by_triple( EntityRef::parse( 'post:1' ), 'media/has-video', EntityRef::parse( 'ext:abcdef' ) )->id() );
		$this->assertSame( $acute, $store->find_by_triple( EntityRef::parse( 'post:1' ), 'media/has-video', EntityRef::parse( 'ext:café' ) )->id() );
		$this->assertSame( 1, $store->count( ( new StatementQuery() )->with_object( EntityRef::parse( 'ext:cafe' ) ) ) );
	}

	/**
	 * Quotes and backslashes survive the round trip.
	 *
	 * @return void
	 */
	public function test_quotes_and_backslashes_survive_the_round_trip(): void {
		$store = $this->store();
		$id    = $store->insert( $this->statement( 'post:1', 'triples/related-to', "ext:it's\\a\"b%s" ) );

		$this->assertSame( "ext:it's\\a\"b%s", (string) $store->find( $id )->object() );
	}

	/**
	 * Literals are stored with their datatype.
	 *
	 * @return void
	 */
	public function test_literals_are_stored_with_their_datatype(): void {
		$store   = $this->store();
		$caption = 'Le col de Somport, à 1 632 m';
		$number  = $store->insert( new Statement( new EntityRef( 'statement', '41' ), 'triples/position', new Literal( 'integer', '5' ) ) );
		$text    = $store->insert( new Statement( new EntityRef( 'statement', '41' ), 'media/caption', new Literal( 'string', $caption ) ) );

		$this->assertInstanceOf( Literal::class, $store->find( $number )->object() );
		$this->assertSame( 'integer', $store->find( $number )->object()->type() );
		$this->assertSame( '5', $store->find( $number )->object()->key() );
		$this->assertSame( $caption, $store->find( $text )->object()->key() );
	}

	/**
	 * The maximum lengths are stored.
	 *
	 * @return void
	 */
	public function test_the_maximum_lengths_are_stored(): void {
		$store = $this->store();
		$id    = $store->insert( $this->statement( 'post:1', 'owner/' . str_repeat( 'a', 58 ), 'ext:' . str_repeat( 'x', 191 ) ) );

		$this->assertSame( str_repeat( 'x', 191 ), $store->find( $id )->object()->key() );
		$this->assertSame( 64, strlen( $store->find( $id )->predicate() ) );
	}

	/**
	 * About returns the statements about some statements.
	 *
	 * @return void
	 */
	public function test_about_returns_the_statements_about_some_statements(): void {
		$store                             = $this->store();
		list( $photo, $web, $print, $all ) = $this->store_example( $store );

		$about_photo = array_map(
			static function ( Statement $statement ) {
				return $statement->id();
			},
			$store->about( array( $photo ) )
		);

		$this->assertSame( array( $web, $print, $all ), $about_photo );
		$this->assertCount( 1, $store->about( array( $print ) ) );
		$this->assertSame( array(), $store->about( array() ) );
		$this->assertSame( array(), $store->about( array( 999999 ) ) );
	}

	/**
	 * Statements are found by subject object and predicate.
	 *
	 * @return void
	 */
	public function test_statements_are_found_by_subject_object_and_predicate(): void {
		$store = $this->store();
		$this->store_example( $store );
		$store->insert( $this->statement( 'post:12', 'media/illustrated-by', 'attachment:91' ) );
		$store->insert( $this->statement( 'post:13', 'media/illustrated-by', 'attachment:88' ) );

		$query = new StatementQuery();

		$this->assertSame( 2, $store->count( $query->with_subject( EntityRef::parse( 'post:12' ) ) ) );
		$this->assertSame( 2, $store->count( $query->with_object( EntityRef::parse( 'attachment:88' ) ) ) );
		$this->assertSame( 3, $store->count( $query->with_predicates( array( 'media/illustrated-by' ) ) ) );
		$this->assertSame( 5, $store->count( $query->with_predicates( array( 'media/illustrated-by', 'triples/position' ) ) ), 'Three photos and two positions.' );
		$this->assertSame( 1, $store->count( $query->with_subject( EntityRef::parse( 'post:12' ) )->with_object( EntityRef::parse( 'attachment:91' ) ) ) );
		$this->assertSame( 0, $store->count( $query->with_subject( EntityRef::parse( 'post:99' ) ) ) );
	}

	/**
	 * The results are ordered by id and can be paged.
	 *
	 * @return void
	 */
	public function test_the_results_are_ordered_by_id_and_can_be_paged(): void {
		$store = $this->store();
		$ids   = array();

		foreach ( array( 5, 6, 7, 8 ) as $number ) {
			$ids[] = $store->insert( $this->statement( 'post:1', 'triples/related-to', 'post:' . $number ) );
		}

		$ids_of = static function ( array $statements ) {
			return array_map(
				static function ( Statement $statement ) {
					return $statement->id();
				},
				$statements
			);
		};

		$this->assertSame( $ids, $ids_of( $store->query( new StatementQuery() ) ) );
		$this->assertSame( array_reverse( $ids ), $ids_of( $store->query( ( new StatementQuery() )->descending() ) ) );
		$this->assertSame( array( $ids[1], $ids[2] ), $ids_of( $store->query( ( new StatementQuery() )->limit( 2, 1 ) ) ) );
		$this->assertSame( 4, $store->count( ( new StatementQuery() )->limit( 2 ) ), 'The count ignores the paging.' );
	}

	/**
	 * Statements are selected by the statements about them.
	 *
	 * @return void
	 */
	public function test_statements_are_selected_by_the_statements_about_them(): void {
		$store    = $this->store();
		$for_all  = $store->insert( $this->statement( 'post:12', 'media/illustrated-by', 'attachment:1' ) );
		$web_only = $store->insert( $this->statement( 'post:12', 'media/illustrated-by', 'attachment:2' ) );
		$both     = $store->insert( $this->statement( 'post:12', 'media/illustrated-by', 'attachment:3' ) );

		foreach ( array( array( $web_only, 'web' ), array( $both, 'web' ), array( $both, 'print' ) ) as $pair ) {
			$store->insert( new Statement( new EntityRef( 'statement', (string) $pair[0] ), 'modes/mode', new EntityRef( 'mode', $pair[1] ) ) );
		}

		$ids_of = static function ( array $statements ) {
			return array_map(
				static function ( Statement $statement ) {
					return $statement->id();
				},
				$statements
			);
		};

		$photos = ( new StatementQuery() )->with_predicates( array( 'media/illustrated-by' ) );

		$this->assertSame( array( $for_all ), $ids_of( $store->query( $photos->unqualified( 'modes/mode' ) ) ), 'No mode statement: every mode.' );
		$this->assertSame( array( $both ), $ids_of( $store->query( $photos->qualified( 'modes/mode', array( new EntityRef( 'mode', 'print' ) ) ) ) ) );
		$this->assertSame( array( $web_only, $both ), $ids_of( $store->query( $photos->qualified( 'modes/mode', array( new EntityRef( 'mode', 'web' ) ) ) ) ) );
		$this->assertSame( array( $web_only, $both ), $ids_of( $store->query( $photos->qualified( 'modes/mode' ) ) ), 'Any mode statement.' );
		$this->assertSame( array( $web_only, $both ), $ids_of( $store->query( $photos->qualified( 'modes/mode', array( new EntityRef( 'mode', 'print' ), new EntityRef( 'mode', 'web' ) ) ) ) ), 'Any of the modes.' );
		$this->assertSame( 1, $store->count( $photos->qualified( 'modes/mode', array( new EntityRef( 'mode', 'print' ) ) ) ) );
	}

	/**
	 * Deleting a statement deletes the statements about it recursively.
	 *
	 * @return void
	 */
	public function test_deleting_a_statement_deletes_the_statements_about_it_recursively(): void {
		$store = $this->store();
		$ids   = $this->store_example( $store );
		$other = $store->insert( $this->statement( 'post:13', 'media/illustrated-by', 'attachment:88' ) );

		$this->assertSame( 5, $store->delete_with_dependents( $ids[0] ) );
		$this->assertSame( 1, $store->count( new StatementQuery() ) );
		$this->assertNotNull( $store->find( $other ) );
		$this->assertSame( 0, $store->delete_with_dependents( $ids[0] ) );
	}

	/**
	 * Deleting a qualification keeps the statement it qualifies.
	 *
	 * @return void
	 */
	public function test_deleting_a_qualification_keeps_the_statement_it_qualifies(): void {
		$store = $this->store();
		$ids   = $this->store_example( $store );

		$this->assertSame( 2, $store->delete_with_dependents( $ids[2] ), 'The print mode and its position.' );
		$this->assertNotNull( $store->find( $ids[0] ) );
		$this->assertNotNull( $store->find( $ids[1] ) );
		$this->assertNotNull( $store->find( $ids[3] ) );
	}

	/**
	 * Deleting an entity deletes its statements and what is said about them.
	 *
	 * @return void
	 */
	public function test_deleting_an_entity_deletes_its_statements_and_what_is_said_about_them(): void {
		$store = $this->store();
		$this->store_example( $store );
		$kept = $store->insert( $this->statement( 'post:13', 'media/illustrated-by', 'attachment:91' ) );

		$this->assertSame( 5, $store->delete_by_entity( EntityRef::parse( 'attachment:88' ) ), 'The photo, its two modes and its two positions.' );
		$this->assertSame(
			array( $kept ),
			array_map(
				static function ( Statement $statement ) {
					return $statement->id();
				},
				$store->query( new StatementQuery() )
			)
		);
	}

	/**
	 * Deleting an entity can be limited to some predicates.
	 *
	 * @return void
	 */
	public function test_deleting_an_entity_can_be_limited_to_some_predicates(): void {
		$store = $this->store();
		$a     = $store->insert( $this->statement( 'post:12', 'media/illustrated-by', 'attachment:88' ) );
		$b     = $store->insert( $this->statement( 'post:12', 'media/mentions', 'attachment:88' ) );

		$this->assertSame( 0, $store->delete_by_entity( EntityRef::parse( 'attachment:88' ), array() ) );
		$this->assertSame( 1, $store->delete_by_entity( EntityRef::parse( 'attachment:88' ), array( 'media/mentions' ) ) );
		$this->assertNotNull( $store->find( $a ) );
		$this->assertNull( $store->find( $b ) );
	}

	/**
	 * A failing transaction is rolled back.
	 *
	 * @return void
	 */
	public function test_a_failing_transaction_is_rolled_back(): void {
		$store = $this->store();

		try {
			Transaction::run(
				$this->database,
				function () use ( $store ) {
					$store->insert( $this->statement( 'post:1', 'triples/related-to', 'post:2' ) );

					throw new RuntimeException( 'Something failed.' );
				}
			);
			$this->fail( 'The exception was swallowed.' );
		} catch ( RuntimeException $problem ) {
			$this->assertSame( 'Something failed.', $problem->getMessage() );
		}

		$this->assertSame( 0, $store->count( new StatementQuery() ) );

		Transaction::run(
			$this->database,
			function () use ( $store ) {
				$store->insert( $this->statement( 'post:1', 'triples/related-to', 'post:2' ) );
			}
		);

		$this->assertSame( 1, $store->count( new StatementQuery() ) );
	}

	/**
	 * The triple has a unique index and the columns are binary.
	 *
	 * @return void
	 */
	public function test_the_triple_has_a_unique_index_and_the_columns_are_binary(): void {
		$indexes = $this->wpdb->get_results( 'SHOW INDEX FROM `' . $this->table() . '`' );
		$unique  = array();

		foreach ( $indexes as $index ) {
			if ( '0' === $index['Non_unique'] && 'triple' === $index['Key_name'] ) {
				$unique[ (int) $index['Seq_in_index'] ] = $index['Column_name'];
				$this->assertNull( $index['Sub_part'], 'A prefix would make the uniqueness wrong.' );
			}
		}

		ksort( $unique );

		$this->assertSame(
			array(
				1 => 'subject_type',
				2 => 'subject_id',
				3 => 'predicate',
				4 => 'object_type',
				5 => 'object_id',
			),
			$unique
		);

		$columns = $this->wpdb->get_results( 'SHOW COLUMNS FROM `' . $this->table() . '`' );
		$types   = array_column( $columns, 'Type', 'Field' );

		$this->assertSame( 'varbinary(191)', $types['subject_id'] );
		$this->assertSame( 'varbinary(191)', $types['object_id'] );
	}

	/**
	 * The schema manager creates the table once.
	 *
	 * @return void
	 */
	public function test_the_schema_manager_creates_the_table_once(): void {
		$this->wpdb->query( 'DROP TABLE `' . $this->table() . '`' );
		otherguise_test_reset();
		$GLOBALS['wpdb'] = $this->wpdb;

		$manager = new SchemaManager( $this->database );

		$this->assertTrue( $manager->maybe_upgrade() );
		$this->assertTrue( $manager->maybe_upgrade() );
		$this->assertCount( 1, $GLOBALS['otherguise_test_dbdelta'], 'The second call finds the schema up to date.' );
		$this->assertSame( SchemaManager::SCHEMA_VERSION, get_option( SchemaManager::VERSION_OPTION ) );
		$this->assertSame( $this->table(), $this->wpdb->get_var( "SHOW TABLES LIKE '" . $this->table() . "'" ) );

		$manager->drop_table();

		$this->assertNull( $this->wpdb->get_var( "SHOW TABLES LIKE '" . $this->table() . "'" ) );
		$this->assertFalse( get_option( SchemaManager::VERSION_OPTION ) );
	}
}
