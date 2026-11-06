<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The functions of a wiki: spaces, names and addresses of pages.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki;

use Otherguise\Core\ModuleInterface;
use Otherguise\Wiki\Admin\SettingsScreen;
use Otherguise\Wiki\Space\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the wiki module (slice 500, design note `docs/design/wiki.md`): the spaces of the wiki, and later the names of the pages
 * (statements of Triples, slice 501), the pages (502) and their addresses (503). It depends on `triples` from the start, because the names
 * are statements.
 */
final class Module implements ModuleInterface {
	/**
	 * Adds an action or a filter.
	 *
	 * @var callable
	 */
	private $add_action;

	/**
	 * Builds the module.
	 *
	 * @param callable|null $add_action Adds an action or a filter; defaults to WordPress `add_action`.
	 */
	public function __construct( $add_action = null ) {
		$this->add_action = $add_action ?? 'add_action';
	}

	/**
	 * Returns the identifier of the module.
	 *
	 * @return string
	 */
	public function id() {
		return 'wiki';
	}

	/**
	 * Returns the identifiers of the modules this module needs: the names of the pages are statements of Triples.
	 *
	 * @return string[]
	 */
	public function dependencies() {
		return array( 'triples' );
	}

	/**
	 * Creates what the module needs when the plugin is activated.
	 *
	 * @return void
	 */
	public function activate() {
		// Nothing to create: the spaces are an option, saved from the screen.
	}

	/**
	 * Registers the screen of the spaces, in the administration only.
	 *
	 * @return void
	 */
	public function boot() {
		if ( is_admin() ) {
			( new SettingsScreen() )->register( $this->add_action );
		}
	}

	/**
	 * Removes the data owned by the module: the spaces.
	 *
	 * @return void
	 */
	public function uninstall() {
		delete_option( Spaces::OPTION );
	}
}
