<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the address by which the PDF server reaches the site (slice 225).
 *
 * @package Otherguise
 */

use Otherguise\Books\Render\InternalHost;
use Otherguise\Books\Render\SiteAddress;
use Otherguise\Books\Settings\RendererSettings;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress: the stubs of the bootstrap.
 *
 * @covers \Otherguise\Books\Render\SiteAddress
 * @covers \Otherguise\Books\Render\InternalHost
 * @covers \Otherguise\Books\Settings\RendererSettings
 */
class BooksSiteAddressTest extends TestCase {

	/**
	 * Starts without any option.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * Only an http or https address of a host, with nothing after it.
	 *
	 * @return void
	 */
	public function test_normalize(): void {
		$this->assertSame( 'http://wordpress', SiteAddress::normalize( ' http://wordpress/ ' ) );
		$this->assertSame( 'http://wordpress-testing-vli-st:8080', SiteAddress::normalize( 'http://wordpress-testing-vli-st:8080' ) );

		foreach ( array( '', 'wordpress', 'ftp://wordpress', 'http://wordpress/blog', 'http://u:p@wordpress', 'http://wordpress?x=1', 'http://wordpress#a', 7, null ) as $bad ) {
			$this->assertNull( SiteAddress::normalize( $bad ), var_export( $bad, true ) );
		}
	}

	/**
	 * The beginning of an address of the site is replaced; nothing else.
	 *
	 * @return void
	 */
	public function test_rewrite(): void {
		$public = 'https://testing.vli.st';

		$this->assertSame( 'http://wordpress/2026/10/25/livre/?mode=print&books_merge_limit=3', SiteAddress::rewrite( 'https://testing.vli.st/2026/10/25/livre/?mode=print&books_merge_limit=3', $public, 'http://wordpress' ) );
		$this->assertSame( 'http://wordpress/?p=5', SiteAddress::rewrite( 'https://testing.vli.st/?p=5', 'https://testing.vli.st/', 'http://wordpress/' ) );
		$this->assertSame( 'http://wordpress/?shared=T&mode=print', SiteAddress::rewrite( 'HTTPS://Testing.VLI.st/?shared=T&mode=print', $public, 'http://wordpress' ), 'Scheme and host are not case sensitive.' );
		$this->assertSame( 'http://wordpress', SiteAddress::rewrite( 'https://testing.vli.st', $public, 'http://wordpress' ) );

		$this->assertSame( 'https://other.example/x', SiteAddress::rewrite( 'https://other.example/x', $public, 'http://wordpress' ), 'Another site is left alone.' );
		$this->assertSame( 'http://testing.vli.st/x', SiteAddress::rewrite( 'http://testing.vli.st/x', $public, 'http://wordpress' ), 'Another scheme is another address.' );
		$this->assertSame( 'https://testing.vli.st:8443/x', SiteAddress::rewrite( 'https://testing.vli.st:8443/x', $public, 'http://wordpress' ), 'Another port too.' );
		$this->assertSame( 'https://testing.vli.st/x', SiteAddress::rewrite( 'https://testing.vli.st/x', $public, '' ), 'Nothing to replace by.' );
		$this->assertSame( 'https://testing.vli.st/x', SiteAddress::rewrite( 'https://testing.vli.st/x', $public, 'http://wordpress/path' ), 'An address with a path is refused.' );
		$this->assertSame( 'not an address', SiteAddress::rewrite( 'not an address', $public, 'http://wordpress' ) );
	}

	/**
	 * The host of an address.
	 *
	 * @return void
	 */
	public function test_host(): void {
		$this->assertSame( 'wordpress', SiteAddress::host( 'http://WordPress/' ) );
		$this->assertSame( 'wordpress:8080', SiteAddress::host( 'http://wordpress:8080/x' ) );
		$this->assertNull( SiteAddress::host( 'nothing' ) );
		$this->assertNull( SiteAddress::host( 12 ) );
	}

	/**
	 * The setting: kept when valid, refused with a notice when not, empty by default, checked by the command.
	 *
	 * @return void
	 */
	public function test_the_setting(): void {
		$messages = array();
		$settings = new RendererSettings(
			static function ( $message ) use ( &$messages ) {
				$messages[] = $message;
			}
		);

		$this->assertSame( '', $settings->get()[ RendererSettings::SITE ] );

		$saved = $settings->sanitize( array( 'site_address' => ' http://wordpress-testing-vli-st/ ' ) );

		$this->assertSame( 'http://wordpress-testing-vli-st', $saved['site_address'] );
		$this->assertSame( array(), $messages );

		update_option( RendererSettings::OPTION, $saved );

		$kept = $settings->sanitize( array( 'site_address' => 'http://wordpress/blog' ) );

		$this->assertSame( 'http://wordpress-testing-vli-st', $kept['site_address'], 'A wrong address keeps the previous one.' );
		$this->assertCount( 1, $messages );

		$this->assertSame( '', $settings->sanitize( array( 'site_address' => '' ) )['site_address'], 'Emptied on purpose.' );

		$this->assertSame( 'http://wordpress', $settings->validate( array( 'site_address' => 'http://wordpress/' ) )['values']['site_address'] );
		$this->assertSame( '', $settings->validate( array( 'site_address' => '' ) )['values']['site_address'] );
		$this->assertNotSame( '', $settings->validate( array( 'site_address' => 'http://wordpress/x' ) )['error'] );
	}

	/**
	 * The canonical redirect is cancelled for the host of the internal address, and only for it.
	 *
	 * @return void
	 */
	public function test_the_canonical_redirect(): void {
		update_option( RendererSettings::OPTION, array( 'site_address' => 'http://wordpress:8080' ) );

		$settings = new RendererSettings();

		$this->assertFalse( ( new InternalHost( $settings, static fn() => 'wordpress:8080' ) )->filter( 'https://testing.vli.st/x/' ) );
		$this->assertSame( 'https://testing.vli.st/x/', ( new InternalHost( $settings, static fn() => 'testing.vli.st' ) )->filter( 'https://testing.vli.st/x/' ) );
		$this->assertSame( 'https://testing.vli.st/x/', ( new InternalHost( $settings, static fn() => 'wordpress' ) )->filter( 'https://testing.vli.st/x/' ), 'Another port is another host.' );
		$this->assertSame( 'https://testing.vli.st/x/', ( new InternalHost( $settings, static fn() => '' ) )->filter( 'https://testing.vli.st/x/' ) );

		update_option( RendererSettings::OPTION, array() );
		$this->assertSame( 'https://x/', ( new InternalHost( new RendererSettings(), static fn() => 'wordpress:8080' ) )->filter( 'https://x/' ), 'Nothing is set: nothing is cancelled.' );
	}
}
