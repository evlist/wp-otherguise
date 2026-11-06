<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the address of a wiki page (slice 503).
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Address\Address;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress.
 *
 * @covers \Otherguise\Wiki\Address\Address
 */
class WikiAddressTest extends TestCase {

	/**
	 * The underscore for the space, the slash kept, the accents and the signs percent-encoded in upper case.
	 *
	 * @return void
	 */
	public function test_the_form_of_an_address(): void {
		$this->assertSame( 'Biography:Paris', Address::path( 'Biography', 'Paris' ) );
		$this->assertSame( 'Biography:Sant_Lloren%C3%A7_de_Morunys', Address::path( 'Biography', 'Sant Llorenç de Morunys' ) );
		$this->assertSame( 'Biography:1958-1968/fr', Address::path( 'Biography', '1958-1968/fr' ), 'A slash is kept.' );
		$this->assertSame( 'Encyclop%C3%A9die:Paris', Address::path( 'Encyclopédie', 'Paris' ), 'The space is encoded too.' );
		$this->assertSame( 'Opinion:C%2B%2B_%26_C', Address::path( 'Opinion', 'C++ & C' ), 'What a path cannot carry is encoded.' );
		$this->assertSame( 'Opinion:100%C2%A0%25', Address::path( 'Opinion', "100\u{a0}%" ), 'Upper-case hexadecimal digits.' );
	}

	/**
	 * What is valid in a path and a reader expects to see stays as it is: the colon, the parentheses, the comma, the apostrophe.
	 *
	 * @return void
	 */
	public function test_the_readable_signs_stay(): void {
		$this->assertSame( 'Biography:Rue:_Edgar_Faure', Address::path( 'Biography', 'Rue: Edgar Faure' ), 'The other colons belong to the title.' );
		$this->assertSame( "Biography:O'Reilly_(editeur),_1!*@", Address::path( 'Biography', "O'Reilly (editeur), 1!*@" ) );
	}
}
