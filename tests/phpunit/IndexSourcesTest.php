<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the registry of the sources and of the selection of an index (slice 605), without a database.
 *
 * @package Otherguise
 */

use Otherguise\Index\Build\Selection;
use Otherguise\Index\Sources;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/class-otherguise-test-memory-kinds.php';

/**
 * Stubs only.
 *
 * @covers \Otherguise\Index\Sources
 * @covers \Otherguise\Index\Build\Selection
 */
class IndexSourcesTest extends TestCase {

	/**
	 * Starts clean, with the built-in kinds and the hamlet kind under `place`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		Otherguise_Test_Memory_Kinds::install();
		\Otherguise\Index\Kinds::add( 'hamlet', 'Hamlet', 'place' );
	}

	/**
	 * The registry is a hierarchy of paths: the parent is the path without its last part, a source is a sort of its ancestors, and the
	 * descendants of a source are the sources below it.
	 *
	 * @return void
	 */
	public function test_the_hierarchy_of_the_sources(): void {
		$this->assertTrue( Sources::is_source( 'post/tag' ) );
		$this->assertTrue( Sources::is_source( 'image/address' ) );
		$this->assertTrue( Sources::is_source( 'whobird' ) );
		$this->assertFalse( Sources::is_source( 'post/nonsense' ) );

		$this->assertSame( 'post', Sources::parent( 'post/tag' ) );
		$this->assertSame( '', Sources::parent( 'whobird' ) );

		$this->assertTrue( Sources::is_a( 'post/tag', 'post' ) );
		$this->assertTrue( Sources::is_a( 'post', 'post' ) );
		$this->assertFalse( Sources::is_a( 'post', 'post/tag' ) );
		$this->assertFalse( Sources::is_a( 'postal/tag', 'post' ), 'A path that only begins like another is not below it.' );

		$this->assertSame( array( 'post', 'post/category', 'post/tag', 'post/link', 'post/mark' ), Sources::descendants( 'post' ) );
		$this->assertContains( 'image/address', Sources::descendants( 'image' ) );
		$this->assertContains( 'trace/end', Sources::descendants( 'trace' ) );
	}

	/**
	 * The tree lists a parent before its children with their depth; a path is checked; a plugin adds a source with the filter, which cannot
	 * replace one that exists; a source may declare the modes in which it is shown.
	 *
	 * @return void
	 */
	public function test_the_tree_the_paths_and_the_filter(): void {
		$rows  = array_column( Sources::tree(), 'depth', 'path' );
		$paths = array_keys( $rows );

		$this->assertSame( 0, $rows['post'] );
		$this->assertSame( 1, $rows['post/tag'] );
		$this->assertLessThan( array_search( 'post/tag', $paths, true ), array_search( 'post', $paths, true ) );

		$this->assertTrue( Sources::valid( 'image/address' ) );
		$this->assertFalse( Sources::valid( 'Image' ) );
		$this->assertFalse( Sources::valid( 'a/b/c/d' ) );
		$this->assertFalse( Sources::valid( '/x' ) );

		$GLOBALS['otherguise_test_filters']['index_sources_registry'] = array(
			'plugin/thing' => array(
				'label'   => 'A thing',
				'visible' => array( 'web' ),
			),
			'post/tag'     => 'Not the tag',
			'Bad Path'     => 'Bad',
		);

		$this->assertTrue( Sources::is_source( 'plugin/thing' ) );
		$this->assertSame( array( 'web' ), Sources::default_visibility( 'plugin/thing' ) );
		$this->assertSame( 'A tag of the post', Sources::label( 'post/tag' ), 'A source of the code is not replaced.' );
		$this->assertFalse( Sources::is_source( 'Bad Path' ) );
		$this->assertSame( array(), Sources::default_visibility( 'post/tag' ) );
	}

	/**
	 * The tokens: a bare word is a kind, the lists may be separated by spaces or commas, a value stands for its descendants, and what is wrong is
	 * reported with a message.
	 *
	 * @return void
	 */
	public function test_the_tokens(): void {
		$this->assertTrue( Selection::parse( 'kind:place source:post/tag visibility:print' )->errors() === array() );
		$this->assertTrue( Selection::parse( 'place, species' )->errors() === array() );
		$this->assertTrue( Selection::parse( '' )->is_everything() );
		$this->assertTrue( Selection::parse( array(), array() )->is_everything() );
		$this->assertFalse( Selection::parse( 'place' )->is_everything() );

		$bad = Selection::parse( 'colour:red kind:nonsense source:nowhere kind:' )->errors();

		$this->assertCount( 4, $bad );
		$this->assertStringContainsString( 'Unknown selection "colour:red"', $bad[0] );
		$this->assertStringContainsString( 'Unknown kind "nonsense"', $bad[1] );
		$this->assertStringContainsString( 'Unknown source "nowhere"', $bad[2] );
	}

	/**
	 * Each dimension on its own: the kind with its sub-kinds, the source with its sub-sources, the visibility with the modes.
	 *
	 * @return void
	 */
	public function test_each_dimension(): void {
		$kind = Selection::parse( 'kind:place' );

		$this->assertTrue( $kind->passes( 'place', 'post/tag', null ) );
		$this->assertTrue( $kind->passes( 'hamlet', 'post/tag', null ), 'A sub-kind is a place.' );
		$this->assertTrue( $kind->passes( 'waterway', 'post/tag', null ) );
		$this->assertFalse( $kind->passes( 'species', 'post/tag', null ) );

		$source = Selection::parse( 'source:post' );

		$this->assertTrue( $source->passes( 'place', 'post/link', null ) );
		$this->assertFalse( $source->passes( 'place', 'image/address', null ) );
		$this->assertTrue( Selection::parse( 'source:image' )->passes( 'place', 'image/address', null ) );

		$print = Selection::parse( 'visibility:print' );

		$this->assertTrue( $print->passes( 'place', 'post/tag', array( 'print', 'web' ) ) );
		$this->assertFalse( $print->passes( 'place', 'post/tag', array( 'web' ) ) );
		$this->assertTrue( $print->passes( 'place', 'post/tag', null ), 'A mention with no visibility is shown in every mode.' );
	}

	/**
	 * Inside one dimension the tokens are alternatives, across dimensions all are required; a dimension that is not named is not tested.
	 *
	 * @return void
	 */
	public function test_include_across_dimensions(): void {
		$selection = Selection::parse( 'kind:place kind:species source:image visibility:print' );

		$this->assertTrue( $selection->passes( 'place', 'image/address', array( 'print' ) ) );
		$this->assertTrue( $selection->passes( 'species', 'image/tag', null ), 'Two kinds are alternatives.' );
		$this->assertFalse( $selection->passes( 'person', 'image/address', array( 'print' ) ), 'The kind is required.' );
		$this->assertFalse( $selection->passes( 'place', 'post/tag', array( 'print' ) ), 'The source is required.' );
		$this->assertFalse( $selection->passes( 'place', 'image/address', array( 'web' ) ), 'The visibility is required.' );
		$this->assertTrue( Selection::parse( 'kind:place' )->passes( 'place', 'whobird', array( 'web' ) ), 'The source and the visibility are not named, so not tested.' );
	}

	/**
	 * `exclude` removes whatever matches, in any dimension, and wins over `include`; a mention shown in every mode is excluded by an excluded
	 * mode.
	 *
	 * @return void
	 */
	public function test_exclude_wins(): void {
		$selection = Selection::parse( 'kind:place', 'kind:waterway source:post/tag visibility:web' );

		$this->assertTrue( $selection->passes( 'hamlet', 'post/link', array( 'print' ) ) );
		$this->assertFalse( $selection->passes( 'waterway', 'post/link', array( 'print' ) ), 'A waterway is a place but it is excluded.' );
		$this->assertFalse( $selection->passes( 'hamlet', 'post/tag', array( 'print' ) ) );
		$this->assertFalse( $selection->passes( 'hamlet', 'post/link', array( 'web' ) ) );
		$this->assertFalse( $selection->passes( 'hamlet', 'post/link', null ), 'Shown in every mode, web included.' );
		$this->assertTrue( Selection::parse( '', 'other' )->passes( 'place', 'post/tag', null ), 'Everything but the excluded.' );
	}
}
