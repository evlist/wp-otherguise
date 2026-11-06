<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the rules of the names of the wiki (slice 501).
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Name;
use Otherguise\Wiki\NameException;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress.
 *
 * @covers \Otherguise\Wiki\Name
 * @covers \Otherguise\Wiki\NameException
 */
class WikiNameTest extends TestCase {

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
	 * A space and an underscore are the same, the ends are trimmed, nothing else changes but the first letter.
	 *
	 * @return void
	 */
	public function test_the_form_of_a_name(): void {
		$this->assertSame( 'Maison de mes grands-parents', Name::title( 'maison_de  mes   grands-parents' ) );
		$this->assertSame( 'Paris', Name::title( "  Paris\u{a0}" ), 'Spaces of every kind at the ends.' );
		$this->assertSame( 'Élisabeth', Name::title( 'élisabeth' ), 'The first letter in capitals, in any alphabet.' );
		$this->assertSame( 'Vdv', Name::title( 'vdv' ) );
		$this->assertSame( 'VdV', Name::title( 'vdV' ), 'Nothing else is changed: the case tells two titles apart.' );
		$this->assertSame( 'Biography:1958-1968', Name::title( 'Biography:1958-1968' ), 'The colon is allowed in a title.' );
		$this->assertSame( '1958-1968/fr', Name::title( '1958-1968/fr' ), 'A slash is a subpage by convention.' );
	}

	/**
	 * An accent written as one letter or as a letter and a mark is the same title (needs intl); one that differs by an accent is another.
	 *
	 * @return void
	 */
	public function test_the_normal_form_c(): void {
		if ( ! Name::has_intl() ) {
			$this->markTestSkipped( 'The extension intl is missing.' );
		}

		$this->assertSame( "Cit\u{e9}", Name::title( "Cite\u{301}" ) );
		$this->assertNotSame( Name::title( 'Cité' ), Name::title( 'Cite' ) );
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
			array( '/fr', NameException::BAD_SLASH ),
			array( 'Paris/', NameException::BAD_SLASH ),
			array( 'Paris//fr', NameException::BAD_SLASH ),
			array( 'Paris/../fr', NameException::BAD_SLASH ),
			array( 'Paris/./fr', NameException::BAD_SLASH ),
			array( '..', NameException::BAD_SLASH ),
		);

		foreach ( $cases as $case ) {
			$this->assertSame( $case[1], $this->refusal( static fn() => Name::title( $case[0] ) ), var_export( $case[0], true ) );
		}
	}

	/**
	 * The qualified name is at most 191 bytes, not 191 characters.
	 *
	 * @return void
	 */
	public function test_the_limit_is_in_bytes(): void {
		$room = 191 - strlen( 'Space:' );

		$this->assertSame( 191, strlen( Name::qualified( 'Space', str_repeat( 'a', $room ) ) ) );
		$this->assertSame( NameException::TOO_LONG, $this->refusal( static fn() => Name::qualified( 'Space', str_repeat( 'a', $room + 1 ) ) ) );

		// Two bytes a letter: half as many fit.
		$this->assertSame( 191 - 1, strlen( Name::qualified( 'Space', str_repeat( 'é', (int) floor( $room / 2 ) ) ) ) );
		$this->assertSame( NameException::TOO_LONG, $this->refusal( static fn() => Name::qualified( 'Space', str_repeat( 'é', (int) floor( $room / 2 ) + 1 ) ) ) );
	}

	/**
	 * A name is cut at its first colon only.
	 *
	 * @return void
	 */
	public function test_a_name_is_cut_at_its_first_colon(): void {
		$this->assertSame(
			array(
				'space'     => 'Biography',
				'title'     => 'Rue: Edgar Faure',
				'qualified' => 'Biography:Rue: Edgar Faure',
			),
			Name::split( 'Biography:Rue: Edgar Faure' )
		);
		$this->assertSame( '', Name::split( 'No colon' )['space'] );
	}

	/**
	 * The folded name has no accents and no capitals, in both parts, and never serves as an identity.
	 *
	 * @return void
	 */
	public function test_the_folded_name(): void {
		if ( ! Name::has_intl() ) {
			$this->markTestSkipped( 'The extension intl is missing.' );
		}

		$this->assertSame( 'encyclopedie:cite', Name::folded( 'Encyclopédie:Cité' ) );
		$this->assertSame( Name::folded( 'Biography:Élisabeth' ), Name::folded( 'BIOGRAPHY:Elisabeth' ) );
		$this->assertNotSame( 'Biography:Élisabeth', Name::folded( 'Biography:Élisabeth' ) );
	}

	/**
	 * A refusal that carries the page that holds the name.
	 *
	 * @return void
	 */
	public function test_the_refusal_of_a_taken_name(): void {
		$refusal = new NameException( NameException::TAKEN, 'Taken.', 42, true );

		$this->assertSame( NameException::TAKEN, $refusal->error_code() );
		$this->assertSame( 42, $refusal->holder() );
		$this->assertTrue( $refusal->holder_has_it_as_current() );
		$this->assertSame( 0, ( new NameException( NameException::EMPTY_TITLE, 'x' ) )->holder() );
	}
}
