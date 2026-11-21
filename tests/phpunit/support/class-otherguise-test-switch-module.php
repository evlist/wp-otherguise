<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Test double of a module with a name, for the switches of the overview.
 *
 * @package Otherguise
 */

use Otherguise\Core\DescribedModule;
use Otherguise\Core\ModuleInterface;

/**
 * A module that records its activation and says what it is.
 */
class Otherguise_Test_Switch_Module implements ModuleInterface, DescribedModule {
	/**
	 * The identifiers of the modules activated, shared.
	 *
	 * @var string[]
	 */
	public static $activated = array();

	/**
	 * Identifier.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Dependencies.
	 *
	 * @var string[]
	 */
	private $dependencies;

	/**
	 * Builds the module.
	 *
	 * @param string   $id           Identifier.
	 * @param string[] $dependencies Dependencies.
	 */
	public function __construct( $id, array $dependencies = array() ) {
		$this->id           = $id;
		$this->dependencies = $dependencies;
	}

	/**
	 * Identifier.
	 *
	 * @return string
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Dependencies.
	 *
	 * @return string[]
	 */
	public function dependencies() {
		return $this->dependencies;
	}

	/**
	 * Records the activation.
	 *
	 * @return void
	 */
	public function activate() {
		self::$activated[] = $this->id;
	}

	/**
	 * Nothing.
	 *
	 * @return void
	 */
	public function boot() {
	}

	/**
	 * Nothing.
	 *
	 * @return void
	 */
	public function uninstall() {
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function title() {
		return 'Module ' . $this->id;
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return 'Does ' . $this->id . '.';
	}
}
