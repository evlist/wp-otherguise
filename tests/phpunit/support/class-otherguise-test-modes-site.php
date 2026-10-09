<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The Triples and Modes modules wired together, without WordPress.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Module as ModesModule;
use Otherguise\Triples\Module as TriplesModule;
use Otherguise\Triples\Statements;

require_once __DIR__ . '/class-otherguise-test-template-lookup.php';

/**
 * A tiny hook dispatcher stands for WordPress: the Modes module boots and adds its callbacks, the Triples module runs its registration
 * actions through it.
 */
final class Otherguise_Test_Modes_Site {
	/**
	 * Triples module.
	 *
	 * @var TriplesModule
	 */
	public $triples;

	/**
	 * Modes module.
	 *
	 * @var ModesModule
	 */
	public $modes;

	/**
	 * Lookup of templates.
	 *
	 * @var Otherguise_Test_Template_Lookup
	 */
	public $lookup;

	/**
	 * Service of the statements.
	 *
	 * @var Statements
	 */
	public $statements;

	/**
	 * Callbacks by hook.
	 *
	 * @var array<string, callable[]>
	 */
	private $hooks = array();

	/**
	 * Wires the modules on a database object.
	 *
	 * @param object     $wpdb  Database object.
	 * @param array      $query Query string of the request, as the modes read it.
	 * @param callable[] $extra Callbacks added to `triples_register_predicates`, for the predicates of the test.
	 */
	public function __construct( $wpdb, array $query = array(), array $extra = array() ) {
		$this->lookup  = new Otherguise_Test_Template_Lookup();
		$this->triples = new TriplesModule(
			function ( $hook, $registry ) use ( $extra ) {
				foreach ( $this->hooks[ $hook ] ?? array() as $callback ) {
					$callback( $registry );
				}

				if ( 'triples_register_predicates' === $hook ) {
					foreach ( $extra as $callback ) {
						$callback( $registry );
					}
				}
			},
			$wpdb
		);
		$this->modes   = new ModesModule(
			static function () {},
			function ( $hook, $callback ) {
				$this->hooks[ $hook ][] = $callback;
			},
			static fn() => $query,
			null,
			fn() => $this->triples->statements(),
			$this->lookup
		);

		$this->modes->boot();

		$this->statements = $this->triples->statements();
	}

	/**
	 * Declares a block template or a template part that exists.
	 *
	 * @param string $slug  Slug.
	 * @param string $type  `wp_template` or `wp_template_part`.
	 * @param string $title Title.
	 * @return WP_Block_Template
	 */
	public function template( $slug, $type = 'wp_template', $title = '' ) {
		$template                                              = new WP_Block_Template( $this->lookup->theme . '//' . $slug, $type, $title );
		$this->lookup->templates[ $type . '|' . $template->id ] = $template;

		return $template;
	}
}
