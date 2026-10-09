<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Checks that the version is the same everywhere it is written.
 *
 * @package Otherguise
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests of VersionConsistency.
 *
 * @coversNothing
 */
class VersionConsistencyTest extends TestCase {

	/**
	 * Returns the first capture group of a pattern applied to a plugin file.
	 *
	 * @param string $file    File name inside the plugin directory.
	 * @param string $pattern Regular expression with one capture group.
	 * @return string
	 */
	private function capture( $file, $pattern ) {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/' . $file );

		$this->assertSame( 1, preg_match( $pattern, $source, $matches ), 'Pattern not found in ' . $file );

		return $matches[1];
	}

	/**
	 * The plugin header the constant and the readme agree.
	 *
	 * @return void
	 */
	public function test_the_plugin_header_the_constant_and_the_readme_agree(): void {
		$header = $this->capture( 'otherguise.php', '/^ \* Version: (\S+)$/m' );

		$this->assertSame( $header, $this->capture( 'otherguise.php', "/define\( 'OTHERGUISE_VERSION', '([^']+)' \)/" ) );
		$this->assertSame( $header, $this->capture( 'readme.txt', '/^Stable tag: (\S+)$/m' ) );
		$this->assertSame( $header, $this->capture( 'readme.txt', '/^= (\S+) =$/m' ), 'The newest changelog entry must be the current version.' );
	}

	/**
	 * The text domain is the plugin slug.
	 *
	 * @return void
	 */
	public function test_the_text_domain_is_the_plugin_slug(): void {
		$this->assertSame( 'otherguise', $this->capture( 'otherguise.php', '/^ \* Text Domain: (\S+)$/m' ) );
	}
}
