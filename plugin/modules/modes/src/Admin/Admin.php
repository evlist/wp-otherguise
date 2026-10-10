<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Composition of the administration screen.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Admin;

use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Modes\Settings\Settings;
use Otherguise\Modes\Template\TemplateLookup;
use Otherguise\Modes\Variant\Variants;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the classes of the screen and hooks them to WordPress. Created only in the administration (see `Module::boot()`).
 */
final class Admin {
	/**
	 * The page.
	 *
	 * @var AdminPage
	 */
	private $page;

	/**
	 * The handlers.
	 *
	 * @var AdminActions
	 */
	private $actions;

	/**
	 * The setting.
	 *
	 * @var SettingsPanel
	 */
	private $panel;

	/**
	 * Builds the screen.
	 *
	 * @param Environment    $environment Environment.
	 * @param ModeRegistry   $modes       Modes.
	 * @param Variants       $variants    Variants.
	 * @param TemplateLookup $lookup      Lookup of templates.
	 * @param Settings       $settings    Settings.
	 */
	public function __construct( Environment $environment, ModeRegistry $modes, Variants $variants, TemplateLookup $lookup, Settings $settings ) {
		$this->panel   = new SettingsPanel( $environment, $settings );
		$this->actions = new AdminActions( $environment, $variants, $modes );
		$this->page    = new AdminPage( $environment, new VariantsScreen( $environment, $modes, $variants, $lookup, $this->panel ) );
	}

	/**
	 * Adds the callbacks to WordPress: the menu, the setting and the three handlers.
	 *
	 * @param callable $add_action Adds an action: `add_action`.
	 * @return void
	 */
	public function register( $add_action ) {
		$add_action( 'admin_menu', array( $this->page, 'register_menu' ) );
		$add_action( 'admin_init', array( $this->panel, 'register' ) );
		$add_action( 'option_page_capability_' . SettingsPanel::GROUP, array( $this->panel, 'capability' ) );
		$add_action( 'admin_post_modes_declare', array( $this->actions, 'declare_variant' ) );
		$add_action( 'admin_post_modes_withdraw', array( $this->actions, 'withdraw_variant' ) );
		$add_action( 'admin_post_modes_remove', array( $this->actions, 'remove_variant' ) );
	}
}
