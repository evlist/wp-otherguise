<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What a merge writes down for the job of the PDF, and the pages that the job gives back.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

defined( 'ABSPATH' ) || exit;

/**
 * The manifest of a merge (the headings, the units, how many tables and indexes have page numbers), written with a key that names it in the PDF, and
 * the map of the pages that the job read in the first PDF and gives back in the address of the next pass (slices 227 and 228).
 */
final class MergeRecord {
	/**
	 * Writes a manifest: receives its key (32 hexadecimal digits) and its JSON, returns whether it was written.
	 *
	 * @var callable|null
	 */
	private $writer;

	/**
	 * Reads a map of the pages: receives its key, returns its JSON or null.
	 *
	 * @var callable|null
	 */
	private $reader;

	/**
	 * Builds the record.
	 *
	 * @param callable|null $writer Writes a manifest; none: no manifest.
	 * @param callable|null $reader Reads a map of the pages; none: the numbers are placeholders.
	 */
	public function __construct( $writer = null, $reader = null ) {
		$this->writer = $writer;
		$this->reader = $reader;
	}

	/**
	 * The page of each heading (`<post>#<n>`) that a map gives; none when there is no key, the map is not found or it is not readable.
	 *
	 * @param string $key The key of the map.
	 * @return array<string, int>
	 */
	public function pages( $key ) {
		if ( '' === $key || null === $this->reader ) {
			return array();
		}

		$data = json_decode( (string) ( $this->reader )( $key ), true );

		return is_array( $data ) ? array_map( 'intval', array_filter( $data, 'is_numeric' ) ) : array();
	}

	/**
	 * Writes the manifest of a merge and returns the marker that names it, to put in front of the units; an empty string when there are no units,
	 * no place to write it, or it could not be written.
	 *
	 * @param string               $html The units.
	 * @param array<string, mixed> $data `section`, `units`, `tocs` (tables and indexes with page numbers) and `headings`.
	 * @return string
	 */
	public function manifest( $html, array $data ) {
		if ( '' === $html || null === $this->writer ) {
			return '';
		}

		$json = wp_json_encode( $data );

		if ( ! is_string( $json ) ) {
			return '';
		}

		$key = md5( $json );

		return true === ( $this->writer )( $key, $json ) ? Headings::manifest_marker( $key ) : '';
	}
}
