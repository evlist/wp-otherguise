<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the flat list of the posts of a section (slice 610).
 *
 * @package Otherguise
 */

use Otherguise\Books\Section\SectionPosts;
use Otherguise\Books\Section\SectionPostsFilter;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: the units and the posts are arrays.
 *
 * @covers \Otherguise\Books\Section\SectionPosts
 * @covers \Otherguise\Books\Section\SectionPostsFilter
 */
class BooksSectionPostsTest extends TestCase {

	/**
	 * Builds the list on sections and posts given as arrays.
	 *
	 * @param array<int, int[]>                                $units The units of each section.
	 * @param array<int, array{status:string,password:string}> $posts The status and password of each post (the others are published).
	 * @return SectionPosts
	 */
	private function list( array $units, array $posts = array() ): SectionPosts {
		return new SectionPosts(
			static fn( $section ) => $units[ $section ] ?? array(),
			static fn( $id ) => array_key_exists( $id, $posts ) ? $posts[ $id ] : array(
				'status'   => 'publish',
				'password' => '',
			)
		);
	}

	/**
	 * A section inside a section is followed right after its own post, as in the merge.
	 *
	 * @return void
	 */
	public function test_a_section_inside_a_section(): void {
		$list = $this->list(
			array(
				1  => array( 10, 20, 30 ),
				20 => array( 21, 22 ),
			)
		);

		$this->assertSame( array( 10, 20, 21, 22, 30 ), $list->ids( 1 ) );
	}

	/**
	 * A draft and a post with a password are left out, with nothing that hangs below them.
	 *
	 * @return void
	 */
	public function test_unpublished_and_protected_posts_are_left_out(): void {
		$list = $this->list(
			array(
				1  => array( 10, 20, 30, 40 ),
				20 => array( 21 ),
			),
			array(
				20 => array(
					'status'   => 'draft',
					'password' => '',
				),
				30 => array(
					'status'   => 'publish',
					'password' => 'secret',
				),
				40 => array(
					'status'   => 'publish',
					'password' => '',
				),
			)
		);

		$this->assertSame( array( 10, 40 ), $list->ids( 1 ) );
	}

	/**
	 * A cycle stops: a section that contains itself, or an ancestor, is listed once.
	 *
	 * @return void
	 */
	public function test_a_cycle_stops(): void {
		$list = $this->list(
			array(
				1 => array( 10, 2 ),
				2 => array( 11, 1 ),
			)
		);

		$ids = $list->ids( 1 );

		$this->assertSame( array( 10, 2, 11, 1 ), $ids, 'The section 1 is listed (as a unit of the section 2) but not followed again.' );
	}

	/**
	 * The depth is limited to six levels.
	 *
	 * @return void
	 */
	public function test_the_depth_is_limited(): void {
		$units = array();

		for ( $i = 1; $i <= 12; ++$i ) {
			$units[ $i ] = array( $i + 1 );
		}

		$ids = $this->list( $units )->ids( 1 );

		$this->assertSame( range( 2, 8 ), $ids );
	}

	/**
	 * An empty section, and a section that does not exist, give nothing.
	 *
	 * @return void
	 */
	public function test_an_empty_section(): void {
		$this->assertSame( array(), $this->list( array() )->ids( 1 ) );
	}

	/**
	 * The filter answers with the posts of the section, unless an answer is already there; a command line asks for the sections as trusted.
	 *
	 * @return void
	 */
	public function test_the_filter(): void {
		otherguise_test_wp_objects( array( new WP_Post( 1 ), new WP_Post( 2 ) ) );

		$asked  = array();
		$filter = new SectionPostsFilter(
			static function ( $trusted ) use ( &$asked ) {
				$asked[] = $trusted;

				return new class() {
					/**
					 * The units of a section.
					 *
					 * @param int $section Section.
					 * @return int[]
					 */
					public function order( $section ) {
						return 7 === $section ? array( 1, 2 ) : array();
					}
				};
			}
		);

		$this->assertSame( array( 1, 2 ), $filter->filter( array(), 7 ) );
		$this->assertSame( array( 9 ), $filter->filter( array( 9 ), 7 ), 'An answer that is already there wins.' );
		$this->assertSame( array( false ), $asked, 'The second call did not even ask for the sections.' );
	}
}
