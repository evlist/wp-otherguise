<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Never the original image for a print size that is missing.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Images;

defined( 'ABSPATH' ) || exit;

/**
 * When a page of a print mode asks for a print size (`books-print-…`) that the photo does not have, WordPress falls back to the
 * **original file** (with the dimensions of the size asked for), and a PDF made from it can weigh 30 MB (slice 221, found on testing:
 * photos that Media Helper does not manage had no print size). In a print mode this filter gives instead the **smallest size that the
 * photo has and that is at least as wide as the size asked for**, or the largest it has when none is, so a missing size costs
 * resolution, not weight. The original is kept when it is not wider than the size asked for (it is then the right size already).
 */
final class PrintFallback {
	/**
	 * Gives the slug of the active mode.
	 *
	 * @var callable
	 */
	private $active_mode;

	/**
	 * Gives the slugs of the print modes.
	 *
	 * @var callable
	 */
	private $print_modes;

	/**
	 * Gives the metadata of an attachment.
	 *
	 * @var callable
	 */
	private $metadata;

	/**
	 * Gives the width, in pixels, of a print size: receives its name, returns an int or null.
	 *
	 * @var callable
	 */
	private $width_of;

	/**
	 * Returns the image of a size: `image_downsize` of WordPress.
	 *
	 * @var callable
	 */
	private $downsize;

	/**
	 * Builds the filter.
	 *
	 * @param PrintSizes    $sizes       Print sizes.
	 * @param callable|null $active_mode Defaults to the filter `modes_active_mode`, `web` when the Modes module is not there.
	 * @param callable|null $print_modes Defaults to the filter `books_preview_frame_modes`, `print` by default.
	 * @param callable|null $metadata    Defaults to `wp_get_attachment_metadata`.
	 * @param callable|null $downsize    Defaults to `image_downsize`.
	 */
	public function __construct( PrintSizes $sizes, $active_mode = null, $print_modes = null, $metadata = null, $downsize = null ) {
		$this->active_mode = $active_mode ?? static function () {
			return apply_filters( 'modes_active_mode', 'web' );
		};
		$this->print_modes = $print_modes ?? static function () {
			return apply_filters( 'books_preview_frame_modes', array( 'print' ) );
		};
		$this->metadata    = $metadata ?? 'wp_get_attachment_metadata';
		$this->downsize    = $downsize ?? 'image_downsize';
		$this->width_of    = static function ( $name ) use ( $sizes ) {
			$all = $sizes->sizes();

			return isset( $all[ $name ] ) ? (int) $all[ $name ]['width'] : null;
		};
	}

	/**
	 * Replaces the original by a smaller size of the photo. Filter `image_downsize`, late (priority 100): the answer of the others,
	 * Media Helper among them, is kept.
	 *
	 * @param mixed $result        What the filters before it answered: false when none did.
	 * @param int   $attachment_id Attachment.
	 * @param mixed $size          Size asked for.
	 * @return mixed
	 */
	public function filter( $result, $attachment_id = 0, $size = '' ) {
		if ( false !== $result || ! is_string( $size ) || 0 !== strpos( $size, PrintSizes::PREFIX ) || ! $this->in_print_mode() ) {
			return $result;
		}

		$wanted = ( $this->width_of )( $size );
		$meta   = ( $this->metadata )( (int) $attachment_id );

		if ( null === $wanted || ! is_array( $meta ) || ! isset( $meta['width'] ) || (int) $meta['width'] <= $wanted ) {
			return $result;
		}

		$sizes = is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array();

		// The size exists: WordPress finds it.
		if ( isset( $sizes[ $size ] ) ) {
			return $result;
		}

		$name = $this->best( $sizes, $wanted );

		if ( null === $name ) {
			return $result;
		}

		$image = ( $this->downsize )( (int) $attachment_id, $name );

		return is_array( $image ) && ! empty( $image[3] ) ? $image : $result;
	}

	/**
	 * The name of the size to use: the narrowest one that is at least `$wanted` pixels wide, else the widest. Print sizes are not
	 * candidates.
	 *
	 * @param array<string, mixed> $sizes  Sizes of the metadata.
	 * @param int                  $wanted Width asked for.
	 * @return string|null
	 */
	private function best( array $sizes, $wanted ) {
		$wide   = null;
		$widest = null;

		foreach ( $sizes as $name => $entry ) {
			if ( ! is_string( $name ) || 0 === strpos( $name, PrintSizes::PREFIX ) || ! is_array( $entry ) || ! isset( $entry['width'] ) ) {
				continue;
			}

			$width = (int) $entry['width'];

			if ( $width >= $wanted && ( null === $wide || $width < $sizes[ $wide ]['width'] ) ) {
				$wide = $name;
			}

			if ( null === $widest || $width > $sizes[ $widest ]['width'] ) {
				$widest = $name;
			}
		}

		return $wide ?? $widest;
	}

	/**
	 * Tells whether the request is in a print mode.
	 *
	 * @return bool
	 */
	private function in_print_mode() {
		$mode  = ( $this->active_mode )();
		$modes = ( $this->print_modes )();

		return is_string( $mode ) && is_array( $modes ) && in_array( $mode, $modes, true );
	}
}
