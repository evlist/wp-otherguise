<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Test double of a module.
 *
 * @package Otherguise
 */

use Otherguise\Core\ModuleInterface;

/**
 * Records the calls made on a module.
 */
class Otherguise_Test_Module implements ModuleInterface {
	/**
	 * Shared log of "action:id" entries.
	 *
	 * @var string[]
	 */
	private $log;

	/**
	 * Module identifier.
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
	 * @param string[] $log          Shared log, passed by reference.
	 */
	public function __construct( $id, array $dependencies, array &$log ) {
		$this->id           = $id;
		$this->dependencies = $dependencies;
		$this->log          = &$log;
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
		$this->log[] = 'activate:' . $this->id;
	}

	/**
	 * Records the boot.
	 *
	 * @return void
	 */
	public function boot() {
		$this->log[] = 'boot:' . $this->id;
	}

	/**
	 * Records the uninstall.
	 *
	 * @return void
	 */
	public function uninstall() {
		$this->log[] = 'uninstall:' . $this->id;
	}
}
