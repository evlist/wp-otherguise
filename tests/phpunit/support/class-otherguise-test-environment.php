<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Test double of the environment of the administration screen.
 *
 * @package Otherguise
 */

use Otherguise\Triples\Admin\Environment;

require_once __DIR__ . '/class-otherguise-test-redirect.php';
require_once __DIR__ . '/class-otherguise-test-denied.php';

/**
 * Environment whose capability, nonces and input are set by the test.
 */
class Otherguise_Test_Environment extends Environment {
	/**
	 * Whether the user has the capability.
	 *
	 * @var bool
	 */
	public $allowed = true;

	/**
	 * Valid nonces: the actions they were made for.
	 *
	 * @var string[]
	 */
	public $valid_nonces = array();

	/**
	 * Query string.
	 *
	 * @var array
	 */
	public $get = array();

	/**
	 * Posted form.
	 *
	 * @var array
	 */
	public $post = array();

	/**
	 * Tells whether the user has the capability.
	 *
	 * @return bool
	 */
	public function can() {
		return $this->allowed;
	}

	/**
	 * Returns the capability.
	 *
	 * @return string
	 */
	public function capability() {
		return 'manage_options';
	}

	/**
	 * A nonce is valid when it is "nonce:" followed by an action in the list.
	 *
	 * @param string $value  Nonce.
	 * @param string $action Action.
	 * @return bool
	 */
	public function verify_nonce( $value, $action ) {
		return 'nonce:' . $action === $value && in_array( $action, $this->valid_nonces, true );
	}

	/**
	 * Prints a marker for the nonce field.
	 *
	 * @param string $action Action.
	 * @return void
	 */
	public function print_nonce_field( $action ) {
		echo '<input type="hidden" name="_wpnonce" value="nonce:' . esc_attr( $action ) . '" />';
	}

	/**
	 * Returns an URL.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public function admin_url( $path ) {
		return 'http://example.test/wp-admin/' . $path;
	}

	/**
	 * Raises the redirection.
	 *
	 * @param string $url URL.
	 * @return void
	 * @throws Otherguise_Test_Redirect Always.
	 */
	public function redirect( $url ) {
		$redirect      = new Otherguise_Test_Redirect( 'redirect' );
		$redirect->url = $url;

		throw $redirect;
	}

	/**
	 * Raises the refusal.
	 *
	 * @return void
	 * @throws Otherguise_Test_Denied Always.
	 */
	public function deny() {
		throw new Otherguise_Test_Denied( 'denied' );
	}

	/**
	 * Returns the date unchanged.
	 *
	 * @param string|null $gmt Date.
	 * @return string
	 */
	public function format_date( $gmt ) {
		return (string) $gmt;
	}

	/**
	 * Returns 20.
	 *
	 * @return int
	 */
	public function per_page() {
		return 20;
	}

	/**
	 * Returns the query string.
	 *
	 * @return array
	 */
	public function query() {
		return $this->get;
	}

	/**
	 * Returns the posted form.
	 *
	 * @return array
	 */
	public function form() {
		return $this->post;
	}

	/**
	 * Prepares a posted form with its nonce.
	 *
	 * @param string $action Action of the nonce.
	 * @param array  $fields Other fields.
	 * @return void
	 */
	public function post_with_nonce( $action, array $fields = array() ) {
		$this->valid_nonces[] = $action;
		$this->post           = $fields + array( '_wpnonce' => 'nonce:' . $action );
	}
}
