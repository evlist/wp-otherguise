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
use Otherguise\Core\Modules;
use Otherguise\Wiki\Admin\SettingsScreen;
use Otherguise\Triples\Predicate\PredicateDefinition;
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
	 * The names, built when they are needed.
	 *
	 * @var Names|null
	 */
	private $names = null;

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
	 * Registers the predicates of the names on the registration of Triples, and the screen of the spaces in the administration.
	 *
	 * @return void
	 */
	public function boot() {
		( $this->add_action )( 'triples_register_predicates', array( $this, 'register_predicates' ), 10, 1 );

		if ( is_admin() ) {
			( new SettingsScreen() )->register( $this->add_action );
		}
	}

	/**
	 * Registers the predicates of the names (slice 501). Action `triples_register_predicates`.
	 *
	 * @param \Otherguise\Triples\Predicate\PredicateRegistry $predicates Predicates.
	 * @return void
	 */
	public function register_predicates( $predicates ) {
		$string = array( 'string' );
		$post   = array( 'post' );

		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'                    => Names::NAME,
					'label'                   => __( 'Has the name', 'otherguise' ),
					'inverse_label'           => __( 'Is the name of', 'otherguise' ),
					'subject_types'           => $post,
					'object_types'            => $string,
					'max_objects_per_subject' => 1,
					'max_subjects_per_object' => 1,
				)
			)
		);
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'                    => Names::FORMER,
					'label'                   => __( 'Had the name', 'otherguise' ),
					'inverse_label'           => __( 'Was the name of', 'otherguise' ),
					'subject_types'           => $post,
					'object_types'            => $string,
					'max_subjects_per_object' => 1,
				)
			)
		);
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'                    => Names::FOLDED,
					'label'                   => __( 'Has the folded name', 'otherguise' ),
					'inverse_label'           => __( 'Is the folded name of', 'otherguise' ),
					'subject_types'           => $post,
					'object_types'            => $string,
					'max_objects_per_subject' => 1,
				)
			)
		);
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'                    => Names::SPACE,
					'label'                   => __( 'Is in the space', 'otherguise' ),
					'inverse_label'           => __( 'Holds the page', 'otherguise' ),
					'subject_types'           => $post,
					'object_types'            => $string,
					'max_objects_per_subject' => 1,
				)
			)
		);
	}

	/**
	 * The names of the pages: the statements of the module Triples.
	 *
	 * @return Names
	 */
	public function names() {
		if ( null === $this->names ) {
			$this->names = new Names(
				static function () {
					return Modules::get( 'triples' )->statements();
				}
			);
		}

		return $this->names;
	}

	/**
	 * Removes the data owned by the module: the spaces. The statements of the names stay with the table of Triples, which keeps or deletes
	 * its data as its own setting says (`delete_data_on_uninstall`); the index can be made again (`Names::rebuild()`).
	 *
	 * @return void
	 */
	public function uninstall() {
		delete_option( Spaces::OPTION );
	}
}
