<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the wiki module (slice 500).
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Module;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: stubs and doubles.
 *
 * @covers \Otherguise\Wiki\Module
 */
class WikiModuleTest extends TestCase {

	/**
	 * Starts without any option, filter or recorded call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * The module is called `wiki` and depends on Triples, and only on it.
	 *
	 * @return void
	 */
	public function test_it_depends_on_triples(): void {
		$module = new Module();

		$this->assertSame( 'wiki', $module->id() );
		$this->assertSame( array( 'triples' ), $module->dependencies() );
		$this->assertNull( $module->activate() );
	}

	/**
	 * The hooks of the module, with their priority and number of arguments; outside the administration there is no screen or notice.
	 *
	 * @return void
	 */
	public function test_the_hooks(): void {
		$hooks = array();

		( new Module(
			static function ( $hook, $callback, $priority, $accepted ) use ( &$hooks ) {
				$hooks[ $hook ][] = array( $callback[1], $priority, $accepted );
			}
		) )->boot();

		$this->assertSame(
			array(
				'triples_register_predicates' => array( array( 'register_predicates', 10, 1 ) ),
				'cli_init'                    => array( array( 'register_command', 10, 0 ) ),
				'init'                        => array( array( 'register', 10, 0 ), array( 'register_block', 10, 0 ), array( 'maybe_flush', 99, 0 ), array( 'register_block', 10, 0 ), array( 'register_block', 10, 0 ), array( 'register_block', 10, 0 ), array( 'register_template', 10, 0 ) ),
				'body_class'                  => array( array( 'body_class', 10, 1 ) ),
				'page_template_hierarchy'     => array( array( 'templates', 10, 1 ) ),
				'rest_api_init'               => array( array( 'register', 10, 0 ) ),
				'enqueue_block_editor_assets' => array( array( 'enqueue', 10, 0 ) ),
				'rest_pre_insert_page'        => array( array( 'check_rest', 10, 2 ) ),
				'rest_after_insert_page'      => array( array( 'after_rest', 10, 1 ) ),
				'wp_after_insert_post'        => array( array( 'after_insert', 10, 1 ), array( 'index_links', 20, 2 ) ),
				'parse_request'               => array( array( 'parse_request', 10, 1 ) ),
				'redirect_canonical'          => array( array( 'redirect_canonical', 5, 2 ) ),
				'page_link'                   => array( array( 'page_link', 10, 2 ) ),
				'the_content'                 => array( array( 'filter', 20, 1 ) ),
				'rest_post_search_query'      => array( array( 'begin', 10, 1 ) ),
				'rest_post_dispatch'          => array( array( 'end', 10, 1 ) ),
				'the_title'                   => array( array( 'title', 10, 2 ) ),
				'404_template_hierarchy'      => array( array( 'hierarchy', 10, 1 ) ),
				'template_include'            => array( array( 'template', 99, 1 ) ),
				'admin_post_wiki_create'      => array( array( 'handle', 10, 0 ) ),
			),
			$hooks
		);
	}

	/**
	 * Activating the plugin flushes (no rule of its own: the one of the first model goes) the rules once, and remembers it.
	 *
	 * @return void
	 */
	public function test_activating_flushes_the_rules(): void {
		( new Module() )->activate();

		$names = array_column( $GLOBALS['otherguise_test_calls'], 0 );

		$this->assertSame( array( 'flush_rewrite_rules' ), array_values( array_intersect( $names, array( 'add_rewrite_rule', 'flush_rewrite_rules' ) ) ) );
		$this->assertSame( '2', get_option( 'wiki_rules' ) );

		( new Module() )->uninstall();

		$this->assertFalse( get_option( 'wiki_rules' ) );
	}

	/**
	 * The four predicates of the names, with their limits (slice 501).
	 *
	 * @return void
	 */
	public function test_the_predicates_of_the_tree(): void {
		$registry = new class() {
			/**
			 * The definitions registered.
			 *
			 * @var array<string, \Otherguise\Triples\Predicate\PredicateDefinition>
			 */
			public $definitions = array();

			/**
			 * Records a definition.
			 *
			 * @param \Otherguise\Triples\Predicate\PredicateDefinition $definition Definition.
			 * @return void
			 */
			public function register( $definition ) {
				$this->definitions[ $definition->slug() ] = $definition;
			}
		};

		( new Module() )->register_predicates( $registry );

		$limits = array();

		foreach ( $registry->definitions as $slug => $definition ) {
			$limits[ $slug ] = array( $definition->subject_types(), $definition->object_types(), $definition->max_objects_per_subject(), $definition->max_subjects_per_object() );
		}

		$this->assertSame(
			array(
				'wiki/key'         => array( array( 'post' ), array( 'string' ), 1, 1 ),
				'wiki/former-key'  => array( array( 'post' ), array( 'string' ), null, 1 ),
				'wiki/folded-key'  => array( array( 'post' ), array( 'string' ), 1, null ),
				'wiki/title'       => array( array( 'post' ), array( 'string' ), 1, null ),
				'wiki/cites'       => array( array( 'post' ), array( 'post' ), null, null ),
				'wiki/wants'       => array( array( 'post' ), array( 'string' ), null, null ),
				'wiki/same-subject' => array( array( 'post' ), array( 'post' ), null, null ),
			),
			$limits
		);
	}

	/**
	 * Removing the plugin deletes its option and the one of the spaces of the first model, if it was left.
	 *
	 * @return void
	 */
	public function test_uninstalling_deletes_the_options(): void {
		update_option( 'wiki_spaces', array( array( 'name' => 'Biography' ) ) );
		update_option( 'wiki_rules', '2' );

		( new Module() )->uninstall();

		$this->assertFalse( get_option( 'wiki_spaces' ) );
		$this->assertFalse( get_option( 'wiki_rules' ) );
	}
}
