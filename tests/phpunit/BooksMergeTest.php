<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the merge of a section with its units: who may ask for it, the token, the log (slice 231).
 *
 * @package Otherguise
 */

use Otherguise\Books\Cli\MergeLogCommand;
use Otherguise\Books\Merge\MergeAccess;
use Otherguise\Books\Merge\MergeLog;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress: the time, the key, the capability and the address are doubles.
 *
 * @covers \Otherguise\Books\Merge\MergeAccess
 * @covers \Otherguise\Books\Merge\MergeLog
 * @covers \Otherguise\Books\Cli\MergeLogCommand
 * @covers \Otherguise\Books\Pdf\PdfStore
 */
class BooksMergeTest extends TestCase {

	/**
	 * Starts clean.
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
	 * Builds the check.
	 *
	 * @param int    $now       Time.
	 * @param bool   $can       Whether the user may edit the section.
	 * @param string $presented The token of the address.
	 * @param string $secret    The key.
	 * @return MergeAccess
	 */
	private function access( $now = 1000, $can = false, $presented = '', $secret = 'key' ) {
		return new MergeAccess( static fn() => $now, static fn() => $secret, static fn() => $can, static fn() => $presented );
	}

	/**
	 * A token is for one section, for an hour, and cannot be changed.
	 *
	 * @return void
	 */
	public function test_a_token(): void {
		$token = $this->access()->token( 12 );

		$this->assertMatchesRegularExpression( '/^4600\.[0-9a-f]{64}$/', $token, 'The expiry, a point, a SHA-256.' );
		$this->assertTrue( $this->access( 1000 )->valid( 12, $token ) );
		$this->assertTrue( $this->access( 4600 )->valid( 12, $token ), 'Valid until the expiry.' );
		$this->assertFalse( $this->access( 4601 )->valid( 12, $token ), 'Expired.' );
		$this->assertFalse( $this->access( 1000 )->valid( 13, $token ), 'Another section.' );
		$this->assertFalse( $this->access( 1000, false, '', 'other key' )->valid( 12, $token ), 'Another site.' );

		$later = '9999.' . substr( $token, 5 );

		$this->assertFalse( $this->access( 1000 )->valid( 12, $later ), 'A later expiry does not match the signature.' );

		foreach ( array( '', 'x', '4600', '4600.abc', '4600.' . str_repeat( 'g', 64 ), $token . '0', ' ' . $token ) as $bad ) {
			$this->assertFalse( $this->access( 1000 )->valid( 12, $bad ), 'Not a token: ' . $bad );
		}
	}

	/**
	 * A user who can edit the section, or a valid token in the address: nobody else.
	 *
	 * @return void
	 */
	public function test_who_is_allowed(): void {
		$token = $this->access()->token( 12 );

		$this->assertFalse( $this->access( 1000, false, '' )->allowed( 12 ), 'A visitor.' );
		$this->assertFalse( $this->access( 1000, false, 'junk' )->allowed( 12 ) );
		$this->assertTrue( $this->access( 1000, true, '' )->allowed( 12 ), 'An editor.' );
		$this->assertTrue( $this->access( 1000, false, $token )->allowed( 12 ), 'The PDF job.' );
		$this->assertFalse( $this->access( 1000, false, $token )->allowed( 13 ), 'The token of another section.' );
		$this->assertFalse( $this->access( 1000, true, $token )->allowed( 0 ), 'No section.' );
	}

	/**
	 * The log is off by default, writes one JSON line with the memory, and starts again when it is large.
	 *
	 * @return void
	 */
	public function test_the_log(): void {
		$path = tempnam( sys_get_temp_dir(), 'books-merge' );
		$on   = false;
		$log  = new MergeLog(
			static function () use ( &$on ) {
				return $on;
			},
			static fn() => $path
		);

		$this->assertFalse( $log->enabled() );
		$log->write( 'merge_start', array( 'section' => 12 ) );
		$this->assertSame( '', (string) file_get_contents( $path ), 'Off: nothing is written.' );

		$on = true;
		$log->write( 'merge_start', array( 'section' => 12 ) );
		$log->write( 'unit', array( 'n' => 1 ) );

		$lines = array_map( 'json_decode', array_filter( explode( "\n", (string) file_get_contents( $path ) ) ) );

		$this->assertCount( 2, $lines );
		$this->assertSame( 'merge_start', $lines[0]->event );
		$this->assertSame( 12, $lines[0]->section );
		$this->assertObjectHasProperty( 'php_peak_mb', $lines[0] );
		$this->assertObjectHasProperty( 'at', $lines[0] );

		file_put_contents( $path, str_repeat( 'x', MergeLog::MAX_BYTES + 1 ) );
		$log->write( 'unit', array( 'n' => 2 ) );
		$this->assertLessThan( 2000, filesize( $path ), 'A file past a megabyte is started again.' );
		$this->assertStringContainsString( '"n":2', (string) file_get_contents( $path ) );

		( new MergeLog( static fn() => true, static fn() => '' ) )->write( 'unit' );
		wp_delete_file( $path );
		$this->assertTrue( true, 'No path (the directory cannot be made): nothing happens.' );

		$this->assertArrayHasKey( 'php_mb', MergeLog::memory() );
	}

	/**
	 * The command prints the last lines, the path, and empties the log; a log that is off or empty prints nothing.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$path = tempnam( sys_get_temp_dir(), 'books-merge' );
		$out  = array();
		$log  = new MergeLog( static fn() => true, static fn() => $path );
		$say  = static function ( $line ) use ( &$out ) {
			$out[] = $line;
		};
		$cli  = new MergeLogCommand( $log, $say, $say );

		$cli->__invoke( array(), array() );
		$this->assertSame( array(), $out, 'Nothing to print.' );

		foreach ( array( 1, 2, 3 ) as $n ) {
			$log->write( 'unit', array( 'n' => $n ) );
		}

		$cli->__invoke( array(), array( 'lines' => '2' ) );
		$this->assertCount( 2, $out );
		$this->assertStringContainsString( '"n":2', $out[0] );
		$this->assertStringContainsString( '"n":3', $out[1], 'The last ones.' );

		$out = array();
		$cli->__invoke( array(), array( 'show-path' => true ) );
		$this->assertSame( array( $path ), $out );

		$out = array();
		$cli->__invoke( array(), array( 'clear' => true ) );
		$this->assertSame( array( 'The log is empty.' ), $out );
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * The name of the file of the log is made of a signature (the store accepts that form only), and the store keeps it with the PDF files.
	 *
	 * @return void
	 */
	public function test_the_name_of_the_log(): void {
		$directory = sys_get_temp_dir() . '/books-store-' . uniqid();
		$store     = new \Otherguise\Books\Pdf\PdfStore( $directory, static fn() => 1000, null, static fn( $path ) => mkdir( $path, 0777, true ), null, static fn( $from, $to ) => rename( $from, $to ) );

		$this->assertSame( '', $store->log_path( 'merge.log' ), 'A name that can be guessed is refused.' );
		$this->assertSame( '', $store->log_path( '../merge-0123456789abcdef.log' ) );
		$this->assertSame( $directory . '/merge-0123456789abcdef.log', $store->log_path( 'merge-0123456789abcdef.log' ) );
		$this->assertFileExists( $directory . '/.htaccess', 'The directory is protected.' );

		file_put_contents( $directory . '/merge-0123456789abcdef.log', "x\n" );
		$store->remove_all();
		$this->assertDirectoryDoesNotExist( $directory, 'The uninstall takes the log away with the files.' );
	}
}
