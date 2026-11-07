<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the rules of the titles of the tree (slice 501, hierarchical model).
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Name;
use Otherguise\Wiki\NameException;
use Otherguise\Wiki\Tree\Title;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress.
 *
 * @covers \Otherguise\Wiki\Tree\Title
 */
class WikiTitleTest extends TestCase {

	/**
	 * The code of the refusal of a call, or null.
	 *
	 * @param callable $call Call.
	 * @return string|null
	 */
	private function refusal( $call ) {
		try {
			$call();
		} catch ( NameException $refusal ) {
			return $refusal->error_code();
		}

		return null;
	}

	/**
	 * A space and an underscore are the same, the ends are trimmed, and **nothing else changes**: the first letter is not made a capital.
	 *
	 * @return void
	 */
	public function test_the_form_of_a_title(): void {
		$this->assertSame( 'Maison de mes grands-parents', Title::title( 'Maison_de  mes   grands-parents' ) );
		$this->assertSame( 'Paris', Title::title( "  Paris\u{a0}" ), 'Spaces of every kind at the ends.' );
		$this->assertSame( 'paris', Title::title( 'paris' ), 'The first letter is not made a capital.' );
		$this->assertNotSame( Title::title( 'Paris' ), Title::title( 'paris' ), 'Two titles.' );
		$this->assertSame( 'vdV', Title::title( 'vdV' ) );
		$this->assertSame( 'Biography:1958-1968', Title::title( 'Biography:1958-1968' ), 'The colon is an ordinary character.' );
		$this->assertSame( 'élisabeth', Title::title( 'élisabeth' ) );
	}

	/**
	 * An accent as one letter or as a letter and a mark is the same title (needs intl); a title that differs by an accent is another.
	 *
	 * @return void
	 */
	public function test_the_normal_form_c(): void {
		if ( ! Name::has_intl() ) {
			$this->markTestSkipped( 'The extension intl is missing.' );
		}

		$this->assertSame( "Cit\u{e9}", Title::title( "Cite\u{301}" ) );
		$this->assertNotSame( Title::title( 'Cité' ), Title::title( 'Cite' ) );
	}

	/**
	 * What a title may not be.
	 *
	 * @return void
	 */
	public function test_the_refused_titles(): void {
		$cases = array(
			array( '', NameException::EMPTY_TITLE ),
			array( " \u{a0}_ ", NameException::EMPTY_TITLE ),
			array( null, NameException::BAD_ENCODING ),
			array( 12, NameException::BAD_ENCODING ),
			array( "bad \xff byte", NameException::BAD_ENCODING ),
			array( "tab\tin it", NameException::CONTROL_CHARACTER ),
			array( "line\nbreak", NameException::CONTROL_CHARACTER ),
			array( 'a#b', NameException::FORBIDDEN ),
			array( 'a?b', NameException::FORBIDDEN ),
			array( '100%', NameException::FORBIDDEN ),
			array( 'a<b', NameException::FORBIDDEN ),
			array( 'a>b', NameException::FORBIDDEN ),
			array( 'a[b', NameException::FORBIDDEN ),
			array( 'a]b', NameException::FORBIDDEN ),
			array( 'a{b', NameException::FORBIDDEN ),
			array( 'a}b', NameException::FORBIDDEN ),
			array( 'a|b', NameException::FORBIDDEN ),
			array( '1958-1968/fr', NameException::SLASH ),
			array( '/fr', NameException::SLASH ),
			array( 'Paris/', NameException::SLASH ),
			array( '.', NameException::DOTS ),
			array( '..', NameException::DOTS ),
		);

		foreach ( $cases as $case ) {
			$this->assertSame( $case[1], $this->refusal( static fn() => Title::title( $case[0] ) ), var_export( $case[0], true ) );
		}

		$this->assertSame( '...', Title::title( '...' ), 'Only "." and ".." are refused.' );
	}

	/**
	 * The key: the parent, a slash, the title; a title made only of digits is refused at the first level only.
	 *
	 * @return void
	 */
	public function test_the_key(): void {
		$this->assertSame( '0/Biography', Title::key( 0, 'Biography' ) );
		$this->assertSame( '625/Paris', Title::key( 625, 'Paris' ) );
		$this->assertSame( '625/sant llorenç', Title::key( '625', 'sant_llorenç' ) );
		$this->assertSame( '0/Biography', Title::key( -3, 'Biography' ), 'A negative parent is the first level.' );

		$this->assertSame( NameException::DIGITS, $this->refusal( static fn() => Title::key( 0, '2026' ) ) );
		$this->assertSame( '625/2026', Title::key( 625, '2026' ), 'Below the first level a number is a title.' );
		$this->assertSame( '0/2026-2027', Title::key( 0, '2026-2027' ), 'Only digits are refused.' );
		$this->assertSame( '0/1958-1968', Title::key( 0, '1958-1968' ) );
	}

	/**
	 * The key is at most 191 bytes, counted for the parent and the title together: the depth is not limited, one title is.
	 *
	 * @return void
	 */
	public function test_the_limit_is_in_bytes(): void {
		$room = 191 - strlen( '625/' );

		$this->assertSame( 191, strlen( Title::key( 625, str_repeat( 'a', $room ) ) ) );
		$this->assertSame( NameException::TOO_LONG, $this->refusal( static fn() => Title::key( 625, str_repeat( 'a', $room + 1 ) ) ) );
		$this->assertSame( 191 - 1, strlen( Title::key( 625, str_repeat( 'é', (int) floor( $room / 2 ) ) ) ), 'Two bytes a letter.' );
		$this->assertSame( NameException::TOO_LONG, $this->refusal( static fn() => Title::key( 625, str_repeat( 'é', (int) floor( $room / 2 ) + 1 ) ) ) );
		$this->assertSame( NameException::TOO_LONG, $this->refusal( static fn() => Title::join( 10, str_repeat( 'a', 191 ) ) ) );
	}

	/**
	 * A key is cut at its first slash; the folded form has no accents and no capitals.
	 *
	 * @return void
	 */
	public function test_split_and_folded(): void {
		$this->assertSame(
			array(
				'parent' => 625,
				'title'  => 'Rue: Edgar Faure',
				'key'    => '625/Rue: Edgar Faure',
			),
			Title::split( '625/Rue: Edgar Faure' )
		);
		$this->assertSame( 0, Title::split( 'No slash' )['parent'] );

		if ( ! Name::has_intl() ) {
			$this->markTestSkipped( 'The extension intl is missing.' );
		}

		$this->assertSame( 'cite', Title::folded( 'Cité' ) );
		$this->assertSame( Title::folded( 'Élisabeth' ), Title::folded( 'ELISABETH' ) );
		$this->assertNotSame( 'Élisabeth', Title::folded( 'Élisabeth' ) );
	}
}
