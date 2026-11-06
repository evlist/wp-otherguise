<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the spaces of the wiki (slice 500).
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Space\Spaces;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: stubs and doubles.
 *
 * @covers \Otherguise\Wiki\Space\Spaces
 */
class WikiSpacesTest extends TestCase {

	/**
	 * Starts without any option, filter or recorded call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * The notices recorded by the Settings API stub, as codes.
	 *
	 * @return string[]
	 */
	private function notices(): array {
		$calls = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'add_settings_error' === $call[0] ) );

		return array_column( $calls, 2 );
	}

	/**
	 * The names that are accepted: letters (accents included), digits, hyphen, underscore.
	 *
	 * @return void
	 */
	public function test_the_names_that_are_accepted(): void {
		$this->assertSame( 'Biography', Spaces::normalize_name( 'Biography' ) );
		$this->assertSame( 'Encyclopédie', Spaces::normalize_name( '  Encyclopédie ' ), 'Trimmed, accents kept.' );
		$this->assertSame( 'Opinion-2_b', Spaces::normalize_name( 'Opinion-2_b' ) );
		$this->assertSame( str_repeat( 'a', 40 ), Spaces::normalize_name( str_repeat( 'a', 40 ) ) );
		$this->assertSame( "Encyclop\u{e9}die", Spaces::normalize_name( "Encyclope\u{301}die" ), 'Normal form C.' );
	}

	/**
	 * The names that are refused: a colon, a slash, a space, nothing, too long, not a string.
	 *
	 * @return void
	 */
	public function test_the_names_that_are_refused(): void {
		foreach ( array( 'a:b', 'a/b', 'a b', '', '   ', 'a.b', '#x', str_repeat( 'a', 41 ), 12, null, array( 'a' ) ) as $name ) {
			$this->assertNull( Spaces::normalize_name( $name ), var_export( $name, true ) );
		}
	}

	/**
	 * A site that never saved the screen has no space; the stored list is read by order and then by name, a record that is not one is ignored.
	 *
	 * @return void
	 */
	public function test_the_reader(): void {
		$this->assertSame( array(), Spaces::all() );
		$this->assertNull( Spaces::find( 'Biography' ) );
		$this->assertNull( Spaces::canonical( 'Biography' ) );

		update_option(
			Spaces::OPTION,
			array(
				array(
					'name' => 'Opinion',
					'label' => 'Opinions',
					'order' => 20,
					'other' => 'dropped',
				),
				array(
					'name' => 'Biography',
					'label' => 'Autobiography',
					'template' => 'Single Wiki',
					'class' => 'space bio!',
					'status' => 'Fact',
					'order' => 10,
				),
				array(
					'name' => 'Archive',
					'order' => 20,
				),
				array( 'name' => 'bad:name' ),
				'not a record',
			)
		);

		$all = Spaces::all();

		$this->assertSame( array( 'Biography', 'Archive', 'Opinion' ), array_column( $all, 'name' ), 'By order, then by name.' );
		$this->assertSame(
			array(
				'name'     => 'Biography',
				'label'    => 'Autobiography',
				'template' => 'singlewiki',
				'class'    => 'space bio',
				'status'   => 'Fact',
				'order'    => 10,
			),
			$all[0],
			'Six keys, each cleaned.'
		);
		$this->assertSame( 'Archive', $all[1]['label'], 'The name is the label when there is none.' );

		update_option( Spaces::OPTION, 'broken' );
		$this->assertSame( array(), Spaces::all() );
	}

	/**
	 * A space is found without regard to the case, and `canonical()` gives the registered form.
	 *
	 * @return void
	 */
	public function test_the_search_ignores_the_case(): void {
		update_option( Spaces::OPTION, array( array( 'name' => 'Encyclopédie' ), array( 'name' => 'Biography' ) ) );

		$this->assertSame( 'Biography', Spaces::find( 'biography' )['name'] );
		$this->assertSame( 'Biography', Spaces::canonical( 'BIOGRAPHY' ) );
		$this->assertSame( 'Encyclopédie', Spaces::canonical( 'ENCYCLOPÉDIE' ) );
		$this->assertNull( Spaces::canonical( 'Opinion' ) );
		$this->assertNull( Spaces::canonical( 'Bio:graphy' ), 'Not a name.' );
		$this->assertNull( Spaces::canonical( null ) );
	}

	/**
	 * A row of the form becomes a record; the empty row of the form is ignored; the order defaults to the position.
	 *
	 * @return void
	 */
	public function test_a_row_becomes_a_record(): void {
		$saved = Spaces::sanitize(
			array(
				array(
					'was' => '',
					'name' => ' Biography ',
					'label' => 'Autobiography',
					'template' => 'wiki',
					'class' => 'space',
					'status' => 'Fact',
					'order' => '5',
					'unknown' => 'x',
				),
				array(
					'was' => '',
					'name' => 'Opinion',
					'label' => '',
				),
				array(
					'was' => '',
					'name' => '',
					'label' => '',
				),
				'not a row',
			)
		);

		$this->assertSame( array( 'Biography', 'Opinion' ), array_column( $saved, 'name' ) );
		$this->assertSame( 5, $saved[0]['order'] );
		$this->assertSame( 20, $saved[1]['order'], 'The default order is 10 times the position.' );
		$this->assertArrayNotHasKey( 'unknown', $saved[0] );
		$this->assertSame( array(), $this->notices() );
		$this->assertSame( array(), Spaces::sanitize( 'broken' ) );
	}

	/**
	 * A bad name is dropped with a notice; the record keeps what it was when it already existed.
	 *
	 * @return void
	 */
	public function test_a_bad_name_is_refused(): void {
		update_option(
			Spaces::OPTION,
			array(
				array(
					'name' => 'Biography',
					'label' => 'Old',
				),
			)
		);

		$saved = Spaces::sanitize(
			array(
				array(
					'was' => 'Biography',
					'name' => 'Bio:graphy',
					'label' => 'New',
				),
				array(
					'was' => '',
					'name' => 'a/b',
					'label' => 'Slash',
				),
			)
		);

		$this->assertSame( array( 'Biography' ), array_column( $saved, 'name' ) );
		$this->assertSame( 'Old', $saved[0]['label'], 'A refused change leaves the record as it was.' );
		$this->assertCount( 2, $this->notices() );
	}

	/**
	 * Two names that differ by the case only are one name: the second is dropped with a notice.
	 *
	 * @return void
	 */
	public function test_a_name_is_unique_without_regard_to_the_case(): void {
		$saved = Spaces::sanitize(
			array(
				array(
					'was' => '',
					'name' => 'Biography',
				),
				array(
					'was' => '',
					'name' => 'BIOGRAPHY',
				),
				array(
					'was' => '',
					'name' => 'Opinion',
				),
			)
		);

		$this->assertSame( array( 'Biography', 'Opinion' ), array_column( $saved, 'name' ) );
		$this->assertCount( 1, $this->notices() );
	}

	/**
	 * A space can be renamed and removed while it holds no page.
	 *
	 * @return void
	 */
	public function test_a_space_without_pages_can_be_renamed_and_removed(): void {
		update_option( Spaces::OPTION, array( array( 'name' => 'Biography' ), array( 'name' => 'Opinion' ), array( 'name' => 'Archive' ) ) );

		$saved = Spaces::sanitize(
			array(
				array(
					'was' => 'Biography',
					'name' => 'Life',
				),
				array(
					'was' => 'Opinion',
					'name' => 'Opinion',
					'remove' => '1',
				),
			)
		);

		$this->assertSame( array( 'Life' ), array_column( $saved, 'name' ), 'Renamed; removed; the one that was left out of the list is removed too.' );
		$this->assertSame( array(), $this->notices() );
	}

	/**
	 * A space that holds pages cannot be renamed or removed, whether the box is ticked or the row is missing; nothing changes.
	 *
	 * @return void
	 */
	public function test_a_space_with_pages_is_kept(): void {
		update_option(
			Spaces::OPTION,
			array(
				array(
					'name' => 'Biography',
					'label' => 'Old',
				),
				array( 'name' => 'Opinion' ),
				array( 'name' => 'Archive' ),
			)
		);
		$GLOBALS['otherguise_test_filters']['wiki_space_has_pages'] = true;

		$saved = Spaces::sanitize(
			array(
				array(
					'was' => 'Biography',
					'name' => 'Life',
					'label' => 'New',
				),
				array(
					'was' => 'Opinion',
					'name' => 'Opinion',
					'remove' => '1',
				),
			)
		);

		$this->assertSame( array( 'Biography', 'Opinion', 'Archive' ), array_column( $saved, 'name' ) );
		$this->assertSame( 'Old', $saved[0]['label'] );
		$this->assertCount( 3, $this->notices(), 'Rename, removal and the missing row each have a notice.' );
	}

	/**
	 * The posted `was` is trusted for nothing: a name that is not registered counts for none.
	 *
	 * @return void
	 */
	public function test_an_unknown_previous_name_is_ignored(): void {
		update_option( Spaces::OPTION, array( array( 'name' => 'Biography' ) ) );
		$GLOBALS['otherguise_test_filters']['wiki_space_has_pages'] = true;

		$saved = Spaces::sanitize(
			array(
				array(
					'was' => 'Nothing',
					'name' => 'Opinion',
				),
				array(
					'was' => 'Biography',
					'name' => 'Biography',
				),
			)
		);

		$this->assertSame( array( 'Opinion', 'Biography' ), array_column( $saved, 'name' ) );
		$this->assertSame( array(), $this->notices() );
	}
}
