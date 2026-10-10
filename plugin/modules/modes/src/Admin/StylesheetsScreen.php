<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The section of the screen that gives stylesheets to the modes.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Admin;

use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Modes\Stylesheet\StylesheetFiles;
use Otherguise\Modes\Stylesheet\Stylesheets;

defined( 'ABSPATH' ) || exit;

/**
 * Prints, for each mode, its stylesheets with the way to take one away, and the form that adds one: a file to upload, or a stylesheet that
 * is already in the Media Library. It prints only; `StylesheetActions` does the work.
 */
final class StylesheetsScreen {
	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Modes.
	 *
	 * @var ModeRegistry
	 */
	private $modes;

	/**
	 * Stylesheets of the modes.
	 *
	 * @var Stylesheets
	 */
	private $stylesheets;

	/**
	 * Files of the Media Library.
	 *
	 * @var StylesheetFiles
	 */
	private $files;

	/**
	 * Builds the section.
	 *
	 * @param Environment     $environment Environment.
	 * @param ModeRegistry    $modes       Modes.
	 * @param Stylesheets     $stylesheets Stylesheets of the modes.
	 * @param StylesheetFiles $files       Files of the Media Library.
	 */
	public function __construct( Environment $environment, ModeRegistry $modes, Stylesheets $stylesheets, StylesheetFiles $files ) {
		$this->environment = $environment;
		$this->modes       = $modes;
		$this->stylesheets = $stylesheets;
		$this->files       = $files;
	}

	/**
	 * Prints the section.
	 *
	 * @return void
	 */
	public function render() {
		echo '<h2>' . esc_html__( 'Stylesheets', 'otherguise' ) . '</h2>';
		echo '<p>' . esc_html__( 'A stylesheet of a mode is loaded after the styles of the theme, on the pages shown in that mode. It is a CSS file of the Media Library.', 'otherguise' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Mode', 'otherguise' ) . '</th><th>' . esc_html__( 'Stylesheets', 'otherguise' ) . '</th></tr></thead><tbody>';

		foreach ( $this->modes->all() as $mode ) {
			echo '<tr><td>' . esc_html( $mode->label() ) . ' <code>' . esc_html( $mode->slug() ) . '</code></td><td>';
			$ids = $this->stylesheets->for_mode( $mode );

			if ( array() === $ids ) {
				echo '<em>' . esc_html__( 'None.', 'otherguise' ) . '</em>';
			}

			foreach ( $ids as $id ) {
				$this->stylesheet( $mode->slug(), $id );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
		$this->form();
	}

	/**
	 * Prints a stylesheet of a mode and its button.
	 *
	 * @param string $mode Slug of the mode.
	 * @param int    $id   Attachment id.
	 * @return void
	 */
	private function stylesheet( $mode, $id ) {
		$file = $this->files->describe( $id );

		echo '<form method="post" action="' . esc_url( $this->environment->admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 .4em">';
		echo '<input type="hidden" name="action" value="modes_remove_stylesheet" /><input type="hidden" name="mode" value="' . esc_attr( $mode ) . '" /><input type="hidden" name="attachment" value="' . esc_attr( (string) $id ) . '" />';
		$this->environment->print_nonce_field( 'modes_remove_stylesheet' );

		if ( null === $file ) {
			echo esc_html( '#' . $id ) . ' <em>' . esc_html__( '(missing)', 'otherguise' ) . '</em> ';
		} else {
			echo '<a href="' . esc_url( $file['url'] ) . '">' . esc_html( $file['label'] ) . '</a> (' . esc_html( size_format( $file['bytes'] ) ) . ') ';
		}

		echo '<input type="submit" class="button button-small" value="' . esc_attr__( 'Remove from this mode', 'otherguise' ) . '" /></form>';
	}

	/**
	 * Prints the form that adds a stylesheet.
	 *
	 * @return void
	 */
	private function form() {
		$choices = $this->files->choices();

		echo '<h3>' . esc_html__( 'Add a stylesheet', 'otherguise' ) . '</h3>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( $this->environment->admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="modes_add_stylesheet" />';
		$this->environment->print_nonce_field( 'modes_add_stylesheet' );
		echo '<p><label>' . esc_html__( 'In the mode', 'otherguise' ) . ' <select name="mode" required>';

		// The default mode is the least likely to need one: the first of the others is selected, unless there is no other.
		$default  = $this->modes->default_mode()->slug();
		$selected = (string) key( $this->modes->all() );

		foreach ( array_keys( $this->modes->all() ) as $slug ) {
			if ( $slug !== $default ) {
				$selected = (string) $slug;
				break;
			}
		}

		foreach ( $this->modes->all() as $slug => $mode ) {
			echo '<option value="' . esc_attr( $mode->slug() ) . '"' . ( (string) $slug === $selected ? ' selected="selected"' : '' ) . '>' . esc_html( $mode->label() ) . '</option>';
		}

		echo '</select></label></p>';
		echo '<p><label><input type="radio" name="how" value="upload" checked="checked" /> ' . esc_html__( 'Upload a CSS file', 'otherguise' ) . '</label> ';
		echo '<input type="file" name="stylesheet_file" accept=".css,text/css" /></p>';

		if ( array() !== $choices ) {
			echo '<p><label><input type="radio" name="how" value="existing" /> ' . esc_html__( 'Use a CSS file of the Media Library', 'otherguise' ) . '</label> ';
			echo '<select name="attachment"><option value="">' . esc_html__( '— Select —', 'otherguise' ) . '</option>';

			foreach ( $choices as $id => $label ) {
				echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $label ) . '</option>';
			}

			echo '</select></p>';
		}

		echo '<p><input type="submit" class="button button-primary" value="' . esc_attr__( 'Add', 'otherguise' ) . '" /></p></form>';
	}
}
