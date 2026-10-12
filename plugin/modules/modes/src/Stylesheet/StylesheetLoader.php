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
 * On the front end, enqueues the stylesheets of the mode of the request: first `modes/<mode>.css` of the theme, when it has one (a
 * convention, slice 213), then those of the Media Library. Nothing when the modes are disabled; the Media Library ones are skipped when the
 * Triples module is not there or for a file that is gone.
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
	 * Finds the stylesheet of a mode in the theme: receives the slug, returns array( 'url' => ..., 'version' => ... ) or null.
	 *
	 * @var callable
	 */
	private $theme_file;

	/**
	 * Builds the loader.
	 *
	 * @param callable        $enabled     Tells whether the modes are enabled.
	 * @param callable        $mode        Returns the `ModeDefinition` of the request.
	 * @param callable        $stylesheets Returns the `Stylesheets` service, or null when it cannot be had.
	 * @param StylesheetFiles $files       Files of the Media Library.
	 * @param callable|null   $enqueue     Defaults to WordPress `wp_enqueue_style`.
	 * @param callable|null   $theme_file  Defaults to the file `modes/<slug>.css` of the (child, then parent) theme.
	 */
	public function __construct( $enabled, $mode, $stylesheets, StylesheetFiles $files, $enqueue = null, $theme_file = null ) {
		$this->enabled     = $enabled;
		$this->mode        = $mode;
		$this->stylesheets = $stylesheets;
		$this->files       = $files;
		$this->enqueue     = $enqueue ?? 'wp_enqueue_style';
		$this->theme_file  = $theme_file ?? array( $this, 'find_theme_file' );
	}

	/**
	 * Finds `modes/<slug>.css` in the child theme, then in the parent theme.
	 *
	 * @param string $slug Slug of the mode.
	 * @return array{url:string,version:string}|null
	 */
	public function find_theme_file( $slug ) {
		$relative = 'modes/' . $slug . '.css';
		$path     = get_theme_file_path( $relative );

		if ( ! is_file( $path ) ) {
			return null;
		}

		$time = filemtime( $path );

		return array(
			'url'     => get_theme_file_uri( $relative ),
			'version' => false === $time ? '' : (string) $time,
		);
	}

	/**
	 * Enqueues the stylesheets of the mode of the request. Action `wp_enqueue_scripts`.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! ( $this->enabled )() ) {
			return;
		}

		$mode = ( $this->mode )();

		if ( ! $mode instanceof ModeDefinition ) {
			return;
		}

		$theme = ( $this->theme_file )( $mode->slug() );

		if ( null !== $theme ) {
			( $this->enqueue )( 'modes-theme-' . $mode->slug(), $theme['url'], array(), '' !== $theme['version'] ? $theme['version'] : null );
		}

		$service = ( $this->stylesheets )();

		if ( null === $service ) {
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
