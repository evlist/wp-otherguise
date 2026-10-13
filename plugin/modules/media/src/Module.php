<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Media items attached to several posts, in modes, at a rank.
 *
 * @package Otherguise
 */

namespace Otherguise\Media;

use Otherguise\Core\ModuleInterface;
use Otherguise\Core\Modules;
use Otherguise\Media\Cli\MigrateCommand;
use Otherguise\Media\Link\Links;
use Otherguise\Media\Method\MediaHelperMethod;
use Otherguise\Media\Method\PostParents;
use Otherguise\Media\Migration\AttachmentSource;
use Otherguise\Media\Migration\NativeLinksMigration;
use Otherguise\Triples\Predicate\PredicateDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the media module. It declares the predicate `media/illustrated-by` in the Triples module and, when Media Helper is there
 * with a contract it knows, offers Media Helper its attachment method (`otherguise`). It does not make the method the one in use: that is
 * a setting of Media Helper.
 */
final class Module implements ModuleInterface {
	/**
	 * The version of the contract of Media Helper this module is written for.
	 */
	public const CONTRACT = 1;

	/**
	 * Adds an action or a filter.
	 *
	 * @var callable
	 */
	private $add_action;

	/**
	 * Tells whether Media Helper offers the contract: returns the version of its contract, or 0.
	 *
	 * @var callable
	 */
	private $contract;

	/**
	 * Builds the module.
	 *
	 * @param callable|null $add_action Adds an action or a filter; defaults to WordPress `add_action`.
	 * @param callable|null $contract   Returns the version of the contract that Media Helper offers, 0 when it is not there or has no
	 *                                  attachment method; defaults to a test of `WP_MEDIA_HELPER_CONTRACT` and of the interface.
	 */
	public function __construct( $add_action = null, $contract = null ) {
		$this->add_action = $add_action ?? 'add_action';
		$this->contract   = $contract ?? static function () {
			return defined( 'WP_MEDIA_HELPER_CONTRACT' ) && interface_exists( 'WP_Media_Helper\\Attachment\\Method' ) ? (int) WP_MEDIA_HELPER_CONTRACT : 0;
		};
	}

	/**
	 * Returns the identifier of the module.
	 *
	 * @return string
	 */
	public function id() {
		return 'media';
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
		// Nothing to create: the links are statements, stored by the Triples module.
	}

	/**
	 * Registers the predicate and, when Media Helper offers the contract, the method.
	 *
	 * @return void
	 */
	public function boot() {
		( $this->add_action )( 'triples_register_predicates', array( $this, 'register_predicate' ), 10, 1 );
		( $this->add_action )( 'wp_media_helper_attachment_methods', array( $this, 'add_method' ), 10, 1 );
		( $this->add_action )( 'wp_media_helper_native_links_in_context', array( $this, 'native_links_in_context' ), 10, 3 );
		( $this->add_action )( 'cli_init', array( $this, 'register_command' ), 10, 0 );
	}

	/**
	 * Tells Media Helper that, before the migration, the images attached the WordPress way (`post_parent`) are also those of the default mode,
	 * so that the web keeps showing them; the other modes show only the links that carry their mode. Filter
	 * `wp_media_helper_native_links_in_context`.
	 *
	 * @param mixed  $include Answer so far.
	 * @param string $context Slug of the mode that is asked for.
	 * @param int    $post_id Post.
	 * @return bool
	 */
	public function native_links_in_context( $include, $context = '', $post_id = 0 ) {
		unset( $post_id );

		if ( true === $include || ! is_string( $context ) || '' === $context ) {
			return true === $include;
		}

		$modes = Modules::get( 'modes' );

		return $modes instanceof \Otherguise\Modes\Module && $modes->modes()->default_mode()->slug() === $context;
	}

	/**
	 * Registers the WP-CLI command `wp otherguise media migrate`. Action `cli_init`.
	 *
	 * @return void
	 */
	public function register_command() {
		\WP_CLI::add_command( 'otherguise media migrate', new MigrateCommand( array( $this, 'migration' ) ) );
	}

	/**
	 * Builds the migration of the native attachments, or returns null when a module it needs is not there.
	 *
	 * @return NativeLinksMigration|null
	 */
	public function migration() {
		$triples = Modules::get( 'triples' );
		$modes   = Modules::get( 'modes' );

		if ( ! $triples instanceof \Otherguise\Triples\Module || ! $modes instanceof \Otherguise\Modes\Module ) {
			return null;
		}

		return new NativeLinksMigration( new Links( $triples->statements() ), array_keys( $modes->modes()->all() ), new AttachmentSource() );
	}

	/**
	 * Removes the data owned by the module.
	 *
	 * @return void
	 */
	public function uninstall() {
		// Nothing of its own: the Triples module removes the statements.
	}

	/**
	 * Registers the predicate `media/illustrated-by`: a post, a media item, qualified by the modes in which the item is shown. The rank
	 * (`triples/position`) is built in. Action `triples_register_predicates`.
	 *
	 * @param \Otherguise\Triples\Predicate\PredicateRegistry $predicates Predicates.
	 * @return void
	 */
	public function register_predicate( $predicates ) {
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => Links::PREDICATE,
					'label'         => __( 'Illustrated by', 'otherguise' ),
					'inverse_label' => __( 'Illustrates', 'otherguise' ),
					'subject_types' => array( 'post' ),
					'object_types'  => array( 'attachment' ),
					'qualified_by'  => array( 'modes/mode' ),
				)
			)
		);
	}

	/**
	 * Adds the method to those of Media Helper, only when it offers the contract this module knows. Filter
	 * `wp_media_helper_attachment_methods`.
	 *
	 * @param mixed $methods Methods by identifier.
	 * @return mixed
	 */
	public function add_method( $methods ) {
		if ( self::CONTRACT !== ( $this->contract )() || ! is_array( $methods ) ) {
			return $methods;
		}

		$triples = Modules::get( 'triples' );
		$modes   = Modules::get( 'modes' );

		if ( ! $triples instanceof \Otherguise\Triples\Module || ! $modes instanceof \Otherguise\Modes\Module ) {
			return $methods;
		}

		$method                  = new MediaHelperMethod( new Links( $triples->statements() ), $modes->modes(), new PostParents() );
		$methods[ $method->id() ] = $method;

		return $methods;
	}
}
