<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the loader of the Section panel and of the module's registration of the sections (slice 223).
 *
 * @package Otherguise
 */

use Otherguise\Books\Module;
use Otherguise\Books\Section\SectionEditor;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: the loader works with doubles and the stubs of the tests.
 *
 * @covers \Otherguise\Books\Section\SectionEditor
 * @covers \Otherguise\Books\Module
 */
class BooksSectionEditorTest extends TestCase {

	/**
	 * Starts without any recorded call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * Cleans up.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		otherguise_test_reset();
	}

	/**
	 * Returns the calls recorded for a WordPress function.
	 *
	 * @param string $name Function.
	 * @return array<int, array>
	 */
	private function calls( $name ) {
		return array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => $call[0] === $name ) );
	}

	/**
	 * Builds the loader with doubles.
	 *
	 * @param int      $post_id Post being edited.
	 * @param string   $type    Its type.
	 * @param bool     $can     Whether the user may edit it.
	 * @param string[] $types   The types that can hold sections.
	 * @return SectionEditor
	 */
	private function editor( $post_id, $type, $can, array $types = array( 'post', 'page' ) ): SectionEditor {
		return new SectionEditor(
			static fn( $file ) => 'https://example.test/section/' . $file,
			static fn() => $types,
			static fn() => $post_id,
			static fn() => $type,
			static fn( $capability, $id ) => $can && 'edit_post' === $capability && $id === $post_id
		);
	}

	/**
	 * The scripts, the style and the settings are loaded for a post that can hold sections.
	 *
	 * @return void
	 */
	public function test_it_loads_the_panel(): void {
		$this->editor( 12, 'page', true )->enqueue();

		$scripts = $this->calls( 'wp_enqueue_script' );

		$this->assertSame(
			array( 'books-section-model', 'books-section-dialog', 'books-section-window', 'books-section-ui', 'books-section' ),
			array_column( $scripts, 1 )
		);
		$this->assertSame( 'https://example.test/section/section-model.js', $scripts[0][2] );
		$this->assertSame( 'https://example.test/section/section.js', $scripts[4][2] );

		$last = $scripts[4][3];

		foreach ( array( 'books-section-model', 'books-section-dialog', 'books-section-window', 'books-section-ui', 'wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-i18n', 'wp-api-fetch' ) as $dependency ) {
			$this->assertContains( $dependency, $last );
		}

		$this->assertSame( array(), $scripts[0][3], 'The pieces depend on nothing.' );
		$styles = $this->calls( 'wp_enqueue_style' );

		$this->assertCount( 1, $styles );
		$this->assertContains( 'dashicons', $styles[0][3], 'The icons of the window.' );

		$inline = $this->calls( 'wp_add_inline_script' );

		$this->assertCount( 1, $inline );
		$this->assertSame( 'books-section', $inline[0][1] );
		$this->assertSame( 'window.booksSection = {"path":"\/books\/v1\/sections","postTypes":["post","page"]};', $inline[0][2] );
		$this->assertSame( 'before', $inline[0][3] );
	}

	/**
	 * Nothing is loaded without a post, for a type that cannot hold sections, or without the right to edit the post.
	 *
	 * @return void
	 */
	public function test_it_loads_nothing_otherwise(): void {
		$this->editor( 0, 'post', true )->enqueue();
		$this->editor( 12, 'attachment', true )->enqueue();
		$this->editor( 12, 'post', false )->enqueue();
		$this->editor( 12, 'post', true, array( 'page' ) )->enqueue();

		$this->assertSame( array(), $this->calls( 'wp_enqueue_script' ) );
		$this->assertSame( array(), $this->calls( 'wp_add_inline_script' ) );
	}

	/**
	 * The global post is the default source of the id.
	 *
	 * @return void
	 */
	public function test_the_default_post(): void {
		$editor = new SectionEditor( static fn( $file ) => $file, static fn() => array( 'post' ) );

		unset( $GLOBALS['post'] );
		$this->assertSame( 0, $editor->global_post_id() );

		$GLOBALS['post'] = new WP_Post( 31 );
		$this->assertSame( 31, $editor->global_post_id() );

		unset( $GLOBALS['post'] );
	}

	/**
	 * The module registers the two predicates of the sections.
	 *
	 * @return void
	 */
	public function test_the_predicates(): void {
		$registry = new class() {
			/**
			 * Registered definitions.
			 *
			 * @var array
			 */
			public $definitions = array();

			/**
			 * Records a definition.
			 *
			 * @param object $definition Definition.
			 * @return void
			 */
			public function register( $definition ) {
				$this->definitions[ $definition->slug() ] = $definition;
			}
		};

		( new Module() )->register_predicates( $registry );

		$this->assertSame( array( 'books/contains', 'books/is-section', 'books/root', 'books/profile' ), array_keys( $registry->definitions ) );
		$this->assertSame( 1, $registry->definitions['books/root']->max_objects_per_subject(), 'A project has one root section.' );
		$this->assertSame( 1, $registry->definitions['books/profile']->max_objects_per_subject(), 'A project has one profile.' );
		$this->assertSame( array( 'post' ), $registry->definitions['books/root']->object_types() );
		$this->assertSame( array( 'post' ), $registry->definitions['books/contains']->subject_types() );
		$this->assertSame( array( 'post' ), $registry->definitions['books/contains']->object_types() );
		$this->assertSame( array( 'boolean' ), $registry->definitions['books/is-section']->object_types() );
		$this->assertSame( 1, $registry->definitions['books/is-section']->max_objects_per_subject() );
	}

	/**
	 * Without the Modes module, or with nothing usable in the filter, there is no print version to open.
	 *
	 * @return void
	 */
	public function test_no_print_address_without_the_modes(): void {
		$module = new Module();

		$this->assertSame( '', $module->print_address( 'https://example.test/?p=1' ), 'Without the Modes module (the test has none).' );

		$GLOBALS['otherguise_test_filters'] = array( 'books_preview_frame_modes' => 'print' );
		$this->assertSame( '', $module->print_address( 'https://example.test/?p=1' ), 'A filter that does not give a list.' );

		$GLOBALS['otherguise_test_filters'] = array( 'books_preview_frame_modes' => array() );
		$this->assertSame( '', $module->print_address( 'https://example.test/?p=1' ), 'An empty list.' );

		unset( $GLOBALS['otherguise_test_filters'] );
	}
}
