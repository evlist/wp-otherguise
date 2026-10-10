<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Loads the stylesheets of the mode of the request.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Stylesheet;

use Otherguise\Modes\Mode\ModeDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * On the front end, enqueues the stylesheets of the mode of the request. Nothing when the modes are disabled, when the Triples module
 * is not there, or for a file that is gone.
 */
final class StylesheetLoader {
	/**
	 * Tells whether the modes are enabled.
	 *
	 * @var callable
	 */
	private $enabled;

	/**
	 * Returns the mode of the request.
	 *
	 * @var callable
	 */
	private $mode;

	/**
	 * Returns the service of the stylesheets, or null.
	 *
	 * @var callable
	 */
	private $stylesheets;

	/**
	 * Files of the Media Library.
	 *
	 * @var StylesheetFiles
	 */
	private $files;

	/**
	 * Enqueues a stylesheet: `wp_enqueue_style`.
	 *
	 * @var callable
	 */
	private $enqueue;

	/**
	 * Builds the loader.
	 *
	 * @param callable        $enabled     Tells whether the modes are enabled.
	 * @param callable        $mode        Returns the `ModeDefinition` of the request.
	 * @param callable        $stylesheets Returns the `Stylesheets` service, or null when it cannot be had.
	 * @param StylesheetFiles $files       Files of the Media Library.
	 * @param callable|null   $enqueue     Defaults to WordPress `wp_enqueue_style`.
	 */
	public function __construct( $enabled, $mode, $stylesheets, StylesheetFiles $files, $enqueue = null ) {
		$this->enabled     = $enabled;
		$this->mode        = $mode;
		$this->stylesheets = $stylesheets;
		$this->files       = $files;
		$this->enqueue     = $enqueue ?? 'wp_enqueue_style';
	}

	/**
	 * Enqueues the stylesheets of the mode of the request. Action `wp_enqueue_scripts`.
	 *
	 * @return void
	 */
	public function enqueue() {
		$service = ( $this->stylesheets )();

		if ( ! ( $this->enabled )() || null === $service ) {
			return;
		}

		$mode = ( $this->mode )();

		if ( ! $mode instanceof ModeDefinition ) {
			return;
		}

		foreach ( $service->for_mode( $mode ) as $id ) {
			$file = $this->files->describe( $id );

			if ( null !== $file ) {
				( $this->enqueue )( 'modes-' . $mode->slug() . '-' . $id, $file['url'], array(), '' !== $file['version'] ? $file['version'] : null );
			}
		}
	}
}
