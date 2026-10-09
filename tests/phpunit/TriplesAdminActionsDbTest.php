<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Integration tests of the handlers of the administration screen, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Admin\AdminActions;
use Otherguise\Triples\Admin\OrphanScanner;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\StatementQuery;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-environment.php';

/**
 * Capability, nonce and effect of the three handlers.
 *
 * @covers \Otherguise\Triples\Admin\AdminActions
 * @covers \Otherguise\Triples\Admin\Environment
 */
class TriplesAdminActionsDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Environment.
	 *
	 * @var Otherguise_Test_Environment
	 */
	private $environment;

	/**
	 * Handlers.
	 *
	 * @var AdminActions
	 */
	private $actions;

	/**
	 * Service.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Module.
	 *
	 * @var \Otherguise\Triples\Module
	 */
	private $module;

	/**
	 * Events fired.
	 *
	 * @var ArrayObject
	 */
	private $events;

	/**
	 * Builds the handlers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->events      = new ArrayObject();
		$this->module      = Otherguise_Test_Fixtures::module( $this->wpdb, $this->events );
		$this->statements  = $this->module->statements();
		$this->environment = new Otherguise_Test_Environment();
		$this->actions     = new AdminActions(
			$this->environment,
			$this->statements,
			$this->module->store(),
			new OrphanScanner( $this->module->store(), $this->module->entity_types() ),
			$this->module->predicates()
		);
	}

	/**
	 * Counts the statements.
	 *
	 * @return int
	 */
	private function total() {
		return $this->module->store()->count( new StatementQuery() );
	}

	/**
	 * Runs a handler and returns the URL it redirects to.
	 *
	 * @param string $method Handler.
	 * @return string
	 */
	private function redirect_of( $method ) {
		try {
			$this->actions->{$method}();
		} catch ( Otherguise_Test_Redirect $redirect ) {
			return $redirect->url;
		}

		$this->fail( 'The handler should have redirected.' );
	}

	/**
	 * Runs a handler and returns whether it was refused.
	 *
	 * @param string $method Handler.
	 * @return bool
	 */
	private function is_denied( $method ) {
		try {
			$this->actions->{$method}();
		} catch ( Otherguise_Test_Denied $denied ) {
			return true;
		} catch ( Otherguise_Test_Redirect $redirect ) {
			return false;
		}

		return false;
	}

	/**
	 * Without the capability nothing is read or written, whatever the nonce says.
	 *
	 * @return void
	 */
	public function test_no_capability_no_effect(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );

		$this->environment->allowed = false;

		foreach ( array(
			'delete'           => array( 'triples_bulk_delete', array( 'statement' => array( (string) $link->id() ) ) ),
			'delete_orphans'   => array( 'triples_delete_orphans', array( 'after' => '0' ) ),
			'delete_predicate' => array( 'triples_delete_predicate_ghost/predicate', array( 'predicate' => 'ghost/predicate' ) ),
		) as $method => list( $action, $fields ) ) {
			$this->environment->post_with_nonce( $action, $fields );

			$this->assertTrue( $this->is_denied( $method ), $method );
		}

		$this->assertSame( 1, $this->total() );
	}

	/**
	 * A missing, wrong or foreign nonce is refused.
	 *
	 * @return void
	 */
	public function test_bad_nonces_are_refused(): void {
		$link = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );

		$this->environment->post = array( 'statement' => array( (string) $link->id() ) );
		$this->assertTrue( $this->is_denied( 'delete' ), 'No nonce.' );

		$this->environment->valid_nonces = array( 'triples_bulk_delete' );
		$this->environment->post         = array(
			'statement' => array( (string) $link->id() ),
			'_wpnonce'  => 'nonce:another_action',
		);
		$this->assertTrue( $this->is_denied( 'delete' ), 'Nonce of another action.' );

		$this->environment->post_with_nonce( 'triples_delete_orphans', array( 'statement' => array( (string) $link->id() ) ) );
		$this->assertTrue( $this->is_denied( 'delete' ), 'Valid nonce of another handler.' );

		$this->environment->post = array(
			'statement' => array( (string) $link->id() ),
			'_wpnonce'  => array( 'nonce:triples_bulk_delete' ),
		);
		$this->assertTrue( $this->is_denied( 'delete' ), 'A list is not a nonce.' );

		$this->assertSame( 1, $this->total() );
	}

	/**
	 * The deletion removes the chosen statements with what is said about them, fires the events, and says how many.
	 *
	 * @return void
	 */
	public function test_delete(): void {
		$link  = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$mode  = $this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', 'web' ) );
		$other = $this->statements->triple( get_post( 13 ), 'media/illustrated-by', get_post( 88 ) );
		$kept  = $this->statements->triple( get_post( 13 ), 'media/illustrated-by', get_post( 90 ) );
		$this->events->exchangeArray( array() );

		$this->environment->post_with_nonce(
			'triples_bulk_delete',
			array( 'statement' => array( (string) $link->id(), (string) $other->id(), (string) $link->id(), 'abc', '0', '-5', '99999' ) )
		);

		$url = $this->redirect_of( 'delete' );

		$this->assertStringContainsString( 'tools.php?page=triples&tab=statements&triples_notice=deleted&n=3', $url );
		$this->assertSame( array( $kept->id() ), array_map( static fn( Statement $statement ) => $statement->id(), $this->statements->match() ) );
		$this->assertSame(
			array( 'deleted:' . $link->id(), 'deleted:' . $mode->id(), 'deleted:' . $other->id() ),
			$this->events->getArrayCopy()
		);
	}

	/**
	 * The batch of orphans is scanned again by the server: only the start comes from the browser.
	 *
	 * @return void
	 */
	public function test_orphans_are_found_again_by_the_server(): void {
		$ok     = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );
		$gone   = $this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 90 ) );
		$beyond = $this->statements->triple( get_post( 13 ), 'media/illustrated-by', get_post( 94 ) );

		otherguise_test_wp_objects( array( new WP_Post( 12 ), new WP_Post( 13 ), new WP_Post( 88, 'attachment' ), new WP_Post( 94, 'attachment' ) ) );

		$this->environment->post_with_nonce(
			'triples_delete_orphans',
			array(
				'after'     => (string) $gone->id(),
				'statement' => array( (string) $ok->id(), (string) $beyond->id() ),
				'orphans'   => array( (string) $ok->id() ),
			)
		);

		$this->assertStringContainsString( 'triples_notice=orphans_deleted&n=0', $this->redirect_of( 'delete_orphans' ), 'After the orphan: nothing to delete, the list sent by the browser is ignored.' );
		$this->assertSame( 3, $this->total() );

		$this->environment->post_with_nonce( 'triples_delete_orphans', array( 'after' => '0' ) );

		$this->assertStringContainsString( 'triples_notice=orphans_deleted&n=1', $this->redirect_of( 'delete_orphans' ) );
		$this->assertSame( array( $ok->id(), $beyond->id() ), array_map( static fn( Statement $statement ) => $statement->id(), $this->statements->match() ) );
	}

	/**
	 * The statements of a predicate that is no longer registered go, in batches; a registered predicate is refused.
	 *
	 * @return void
	 */
	public function test_delete_predicate(): void {
		$this->statements->triple( get_post( 12 ), 'media/illustrated-by', get_post( 88 ) );

		for ( $number = 1; $number <= 205; $number++ ) {
			$this->module->store()->insert( new Statement( new EntityRef( 'post', (string) $number ), 'old/relation', new EntityRef( 'post', '1' ) ) );
		}

		$this->environment->post_with_nonce( 'triples_delete_predicate_media/illustrated-by', array( 'predicate' => 'media/illustrated-by' ) );

		$this->assertStringContainsString( 'triples_notice=predicate_refused', $this->redirect_of( 'delete_predicate' ) );
		$this->assertSame( 206, $this->total() );

		$this->environment->post_with_nonce( 'triples_delete_predicate_other/predicate', array( 'predicate' => 'old/relation' ) );
		$this->assertTrue( $this->is_denied( 'delete_predicate' ), 'The nonce is made for one predicate.' );

		$this->environment->post_with_nonce( 'triples_delete_predicate_old/relation', array( 'predicate' => 'old/relation' ) );

		$this->assertStringContainsString( 'triples_notice=predicate_deleted&n=205', $this->redirect_of( 'delete_predicate' ) );
		$this->assertSame( 1, $this->total() );

		$this->environment->post_with_nonce( 'triples_delete_predicate_Not A Slug', array( 'predicate' => 'Not A Slug' ) );
		$this->assertStringContainsString( 'triples_notice=predicate_refused', $this->redirect_of( 'delete_predicate' ) );
	}
}
