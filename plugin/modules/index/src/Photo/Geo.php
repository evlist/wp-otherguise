<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What Media Helper knows about files.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Photo;

defined( 'ABSPATH' ) || exit;

/**
 * The door to Media Helper (slice 606): the facts about the photographs and the GPX files of a page (position, time, place) come from its batch
 * function, or its filter one URL at a time. `null` means no data (the file is not in its index, or the plugin is not there): never an error.
 * This module does no geocoding and does not depend on Media Helper.
 */
final class Geo {
	/**
	 * The filter of Media Helper that answers for one file (a hook of another plugin, so it is named here and not typed at the call).
	 */
	public const FILTER = 'wp_media_helper_file_geo';

	/**
	 * Receives a list of URLs, returns `url => answer|null`.
	 *
	 * @var callable
	 */
	private $batch;

	/**
	 * Builds the door.
	 *
	 * @param callable|null $batch The batch; default `wp_media_helper_get_files_geo()` when it exists, else the filter `wp_media_helper_file_geo`.
	 */
	public function __construct( $batch = null ) {
		$this->batch = $batch ?? static function ( array $urls ) {
			if ( function_exists( 'wp_media_helper_get_files_geo' ) ) {
				return (array) wp_media_helper_get_files_geo( $urls );
			}

			$found = array();

			foreach ( $urls as $url ) {
				$found[ $url ] = apply_filters( self::FILTER, null, $url );
			}

			return $found;
		};
	}

	/**
	 * The answers for some URLs.
	 *
	 * @param string[] $urls The URLs.
	 * @return array<string, array|null> By URL, in the order given.
	 */
	public function answers( array $urls ) {
		$urls = array_values( array_unique( array_map( 'strval', $urls ) ) );

		if ( array() === $urls ) {
			return array();
		}

		$given = (array) ( $this->batch )( $urls );
		$found = array();

		foreach ( $urls as $url ) {
			$found[ $url ] = isset( $given[ $url ] ) && is_array( $given[ $url ] ) ? $given[ $url ] : null;
		}

		return $found;
	}
}
