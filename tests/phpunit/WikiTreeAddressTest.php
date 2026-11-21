<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the addresses of the tree and of the reserved titles (slice 503).
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Tree\Address;
use Otherguise\Wiki\Tree\Reserved;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress, no database.
 *
 * @covers \Otherguise\Wiki\Tree\Address
 * @covers \Otherguise\Wiki\Tree\Reserved
 */
class WikiTreeAddressTest extends TestCase {

	/**
	 * The form of an address: underscore, upper-case encoding, readable signs, no trailing slash.
	 *
	 * @return void
	 */
	public function test_the_form_of_an_address(): void {
		$this->assertSame( '/Biography/Sant_Lloren%C3%A7_de_Morunys', Address::path( array( 'Biography', 'Sant Llorenç de Morunys' ) ) );
		$this->assertSame( '/Biography/1958-1968/Souvenirs:_la_tour_(Saint-Just)', Address::path( array( 'Biography', '1958-1968', 'Souvenirs: la tour (Saint-Just)' ) ) );
		$this->assertSame( '/Paris', Address::path( array( 'Paris' ) ) );
		$this->assertSame( '/a%3Fb%23c%25d', Address::path( array( 'a?b#c%d' ) ) );
	}

	/**
	 * A path is cut into decoded titles; a segment already decoded is left alone; an empty path or segment is not an address.
	 *
	 * @return void
	 */
	public function test_a_path_is_cut_into_titles(): void {
		$this->assertSame( array( 'Biography', 'Lloren' . "\u{E7}" . '_de' ), Address::titles( 'Biography/Lloren%C3%A7_de/' ) );
		$this->assertSame( array( 'Encyclop' . "\u{E9}" . 'die' ), Address::titles( '/Encyclop' . "\u{E9}" . 'die' ) );
		$this->assertNull( Address::titles( '' ) );
		$this->assertNull( Address::titles( '/' ) );
		$this->assertNull( Address::titles( 'a//b' ) );
	}

	/**
	 * What WordPress or the web server serves first is reserved at the first level; an ordinary title is not.
	 *
	 * @return void
	 */
	public function test_reserved_titles(): void {
		$reserved = new Reserved(
			static fn() => array(
				'feed/(feed|rdf|rss|rss2|atom)/?$' => 'index.php?&feed=$matches[1]',
				'(feed|rdf|rss|rss2|atom)/?$'      => 'index.php?&feed=$matches[1]',
				'category/(.+?)/?$'                => 'index.php?category_name=$matches[1]',
				'([0-9]{4})/?$'                    => 'index.php?year=$matches[1]',
				'(.?.+?)(?:/([0-9]+))?/?$'         => 'index.php?pagename=$matches[1]&page=$matches[2]',
			),
			static fn( $name ) => 'uploads' === $name
		);

		foreach ( array( 'feed', 'wp-json', 'wp-admin', 'WP-Login.php', 'index.php', 'robots.txt', 'uploads' ) as $title ) {
			$this->assertTrue( $reserved( $title ), $title );
		}

		foreach ( array( 'Biography', 'Feed', 'category', 'paris', 'Accueil' ) as $title ) {
			$this->assertFalse( $reserved( $title ), $title );
		}
	}

	/**
	 * With plain permalinks there are no rules: only the list and the files count.
	 *
	 * @return void
	 */
	public function test_without_rules(): void {
		$reserved = new Reserved( static fn() => array(), static fn() => false );

		$this->assertTrue( $reserved( 'wp-content' ) );
		$this->assertFalse( $reserved( 'category' ) );
	}
}
