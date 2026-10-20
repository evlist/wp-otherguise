<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI command `wp otherguise books sizes`.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Cli;

use Otherguise\Books\Images\PrintSizes;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the image sizes of the print (slice 221), to feed `wp media-helper thumbnails generate --size=`.
 */
final class SizesCommand {
	/**
	 * Sizes.
	 *
	 * @var PrintSizes
	 */
	private $sizes;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Stops with an error message.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Builds the command.
	 *
	 * @param PrintSizes    $sizes Sizes.
	 * @param callable|null $log   Defaults to `WP_CLI::log`.
	 * @param callable|null $fail  Defaults to `WP_CLI::error`.
	 */
	public function __construct( PrintSizes $sizes, $log = null, $fail = null ) {
		$this->sizes = $sizes;
		$this->log   = $log ?? array( 'WP_CLI', 'log' );
		$this->fail  = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Lists the image sizes of the print, in pixels, for the format of the page of the book.
	 *
	 * The sizes are declared by the theme or the site with the filter `books_print_sizes`; this command only reads. Read-only.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : `table` (one line per size, tab separated: name, width, height, crop, fraction, ratio), `json`, or `names` (the names separated by commas, for `wp media-helper thumbnails generate --size=`).
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise books sizes
	 *     wp media-helper thumbnails generate --size="$(wp otherguise books sizes --format=names)" --dry-run
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$format = $assoc_args['format'] ?? 'table';
		$sizes  = $this->sizes->sizes();

		if ( 'names' === $format ) {
			( $this->log )( implode( ',', array_keys( $sizes ) ) );
			return;
		}

		if ( 'json' === $format ) {
			( $this->log )( (string) wp_json_encode( $sizes ) );
			return;
		}

		if ( 'table' !== $format ) {
			( $this->fail )( '--format: table, json or names.' );
			return;
		}

		foreach ( $sizes as $name => $size ) {
			( $this->log )( implode( "\t", array( $name, $size['width'], $size['height'], $size['crop'] ? 'crop' : 'proportional', $size['fraction'], null === $size['ratio'] ? '-' : round( $size['ratio'], 3 ) ) ) );
		}
	}
}
