<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Books assembled from posts: composition, table of contents, index and PDF output.
 *
 * @package Otherguise
 */

namespace Otherguise\Books;

use Otherguise\Books\Admin\SettingsScreen;
use Otherguise\Books\Preview\EditorPreview;
use Otherguise\Books\Settings\PageFormat;
use Otherguise\Core\ModuleInterface;
use Otherguise\Core\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the books module.
 */
final class Module implements ModuleInterface {
	/**
	 * Adds an action or a filter.
	 *
	 * @var callable
	 */
	private $add_action;

	/**
	 * Returns the address of a file of the editor directory of the module.
	 *
	 * @var callable
	 */
	private $editor_url;

	/**
	 * Builds the module.
	 *
	 * @param callable|null $add_action Adds an action or a filter; defaults to WordPress `add_action`.
	 * @param callable|null $editor_url Receives a file of `modules/books/editor/preview/` and returns its address; defaults to `plugins_url()`.
	 */
	public function __construct( $add_action = null, $editor_url = null ) {
		$this->add_action = $add_action ?? 'add_action';
		$this->editor_url = $editor_url ?? static function ( $file ) {
			return plugins_url( 'modules/books/editor/preview/' . $file, OTHERGUISE_PLUGIN_FILE );
		};
	}

	/**
	 * Returns the identifier of the module.
	 *
	 * @return string
	 */
	public function id() {
		return 'books';
	}

	/**
	 * Returns the identifiers of the modules this module needs.
	 *
	 * @return string[]
	 */
	public function dependencies() {
		return array( 'triples', 'modes' );
	}

	/**
	 * Creates what the module needs when the plugin is activated.
	 *
	 * @return void
	 */
	public function activate() {
		// Nothing to create yet.
	}

	/**
	 * Registers the settings screen and the preview panel of the editor (slice 219).
	 *
	 * @return void
	 */
	public function boot() {
		$format   = new PageFormat();
		$settings = new SettingsScreen( $format );
		$preview  = new EditorPreview( $format, array( $this, 'preview_modes' ), $this->editor_url );

		( $this->add_action )( 'admin_init', array( $settings, 'register' ), 10, 0 );
		( $this->add_action )( 'admin_menu', array( $settings, 'add_menu' ), 10, 0 );
		( $this->add_action )( 'option_page_capability_' . SettingsScreen::GROUP, array( $settings, 'save_capability' ), 10, 0 );
		( $this->add_action )( 'enqueue_block_editor_assets', array( $preview, 'enqueue' ), 10, 0 );
		( $this->add_action )( 'show_admin_bar', array( $preview, 'admin_bar' ), 10, 1 );
	}

	/**
	 * Returns the modes the preview panel offers: `value` (slug) and `label`. Empty when the Modes module is not there.
	 *
	 * @return array<int, array{value:string,label:string}>
	 */
	public function preview_modes() {
		$modes = Modules::get( 'modes' );

		if ( ! $modes instanceof \Otherguise\Modes\Module ) {
			return array();
		}

		$choices = array();

		foreach ( $modes->modes()->all() as $mode ) {
			$choices[] = array(
				'value' => $mode->slug(),
				'label' => $mode->label(),
			);
		}

		return $choices;
	}

	/**
	 * Removes the data owned by the module.
	 *
	 * @return void
	 */
	public function uninstall() {
		delete_option( PageFormat::OPTION );
	}
}
