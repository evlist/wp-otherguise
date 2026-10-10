<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Modes selected by the query string and relations between templates.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes;

use Otherguise\Core\ModuleInterface;
use Otherguise\Core\Modules;
use Otherguise\Modes\Admin\Admin;
use Otherguise\Modes\Admin\Environment;
use Otherguise\Modes\Integration\TemplateIntegration;
use Otherguise\Modes\Integration\TriplesIntegration;
use Otherguise\Modes\Link\LinkBlock;
use Otherguise\Modes\Mode\ActiveMode;
use Otherguise\Modes\Mode\ModeDefinition;
use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Modes\Settings\DefaultTemplates;
use Otherguise\Modes\Settings\Settings;
use Otherguise\Modes\Stylesheet\StylesheetFiles;
use Otherguise\Modes\Stylesheet\StylesheetLoader;
use Otherguise\Modes\Stylesheet\Stylesheets;
use Otherguise\Modes\Template\NewPostTemplate;
use Otherguise\Modes\Template\TemplateLookup;
use Otherguise\Modes\Template\VariantApplier;
use Otherguise\Modes\Variant\Variants;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the modes module.
 *
 * It declares the modes (`web`, the default, and `print`, selected by `?mode=print` or by the alias `?print`; other modes are declared
 * with the action `modes_register_modes`), finds the mode of the request, registers the entity type `mode` and the predicate
 * `modes/mode` in the Triples module, and adds the class `modes-mode-{slug}` to the body. It changes no template yet.
 */
final class Module implements ModuleInterface {
	/**
	 * Runs an action: receives the hook name and the argument.
	 *
	 * @var callable
	 */
	private $do_action;

	/**
	 * Adds an action or a filter.
	 *
	 * @var callable
	 */
	private $add_action;

	/**
	 * Applies a filter: receives the hook name and the value.
	 *
	 * @var callable
	 */
	private $apply_filters;

	/**
	 * Modes.
	 *
	 * @var ModeRegistry
	 */
	private $modes;

	/**
	 * Mode of the request.
	 *
	 * @var ActiveMode
	 */
	private $active;

	/**
	 * Returns the service of the statements, or null.
	 *
	 * @var callable
	 */
	private $statements;

	/**
	 * Lookup of templates.
	 *
	 * @var TemplateLookup
	 */
	private $lookup;

	/**
	 * Variants, built at the first use.
	 *
	 * @var Variants|null
	 */
	private $variants = null;

	/**
	 * Tells whether the request is a page of the site.
	 *
	 * @var callable
	 */
	private $is_front;

	/**
	 * Tells whether the request is in the administration.
	 *
	 * @var callable
	 */
	private $is_admin;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Applies the variants.
	 *
	 * @var VariantApplier
	 */
	private $applier;

	/**
	 * The template of new posts.
	 *
	 * @var DefaultTemplates
	 */
	private $default_templates;

	/**
	 * Files of the Media Library that are stylesheets.
	 *
	 * @var StylesheetFiles
	 */
	private $stylesheet_files;

	/**
	 * The stylesheets of the modes, built at the first use.
	 *
	 * @var Stylesheets|null
	 */
	private $stylesheets = null;

	/**
	 * The block that links to another mode, built at the first use.
	 *
	 * @var LinkBlock|null
	 */
	private $link_block = null;

	/**
	 * Builds the module.
	 *
	 * @param callable|null        $do_action     Runs an action; defaults to WordPress `do_action`.
	 * @param callable|null        $add_action    Adds an action or a filter; defaults to WordPress `add_action`.
	 * @param callable|null        $query         Returns the query string keys `mode` and the aliases that are present; defaults to a read of the
	 *                                            request with `filter_input_array()`.
	 * @param callable|null        $apply_filters Applies a filter; defaults to WordPress `apply_filters`.
	 * @param callable|null        $statements    Returns the service of the statements (`Statements`) or null; defaults to the Triples module booted
	 *                                            in this request.
	 * @param TemplateLookup|null  $lookup  What the module asks WordPress about templates.
	 * @param callable|null        $is_front      Tells whether the request is a page of the site, as opposed to the administration or REST;
	 *                                            defaults to a test of `is_admin()` and `REST_REQUEST`.
	 * @param callable|null        $is_admin      Tells whether this is an administration request; defaults to WordPress `is_admin`.
	 * @param StylesheetFiles|null $stylesheet_files The files of the Media Library that are stylesheets; defaults to the real ones.
	 */
	public function __construct( $do_action = null, $add_action = null, $query = null, $apply_filters = null, $statements = null, ?TemplateLookup $lookup = null, $is_front = null, $is_admin = null, ?StylesheetFiles $stylesheet_files = null ) {
		$this->do_action     = $do_action ?? 'do_action';
		$this->add_action    = $add_action ?? 'add_action';
		$this->apply_filters = $apply_filters ?? 'apply_filters';
		$this->lookup        = $lookup ?? new TemplateLookup();
		$this->is_admin      = $is_admin ?? 'is_admin';
		$this->stylesheet_files = $stylesheet_files ?? new StylesheetFiles();
		$this->default_templates = new DefaultTemplates( $this->lookup );
		$this->settings      = new Settings();
		$this->is_front      = $is_front ?? static function () {
			return ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		};
		$this->statements    = $statements ?? static function () {
			$triples = Modules::get( 'triples' );

			return $triples instanceof \Otherguise\Triples\Module ? $triples->statements() : null;
		};

		$this->modes  = new ModeRegistry(
			function ( $registry ) {
				$registry->register( new ModeDefinition( 'web', __( 'Web', 'otherguise' ) ) );
				$registry->register( new ModeDefinition( 'print', __( 'Print', 'otherguise' ), 'print' ) );

				/**
				 * Lets a plugin or a theme declare modes.
				 *
				 * @param ModeRegistry $registry Registry: call `register( new ModeDefinition( $slug, $label, $alias ) )`.
				 */
				( $this->do_action )( 'modes_register_modes', $registry );
			},
			function () {
				/**
				 * Filters the slug of the default mode, the mode of a request that asks for none.
				 *
				 * @param string $slug Slug; `web` by default. A slug that is not registered gives the first mode registered.
				 */
				return ( $this->apply_filters )( 'modes_default_mode', 'web' );
			}
		);
		$reader        = $query ?? array( $this, 'read_query' );
		$front         = $this->is_front;
		$this->active  = new ActiveMode(
			$this->modes,
			function () use ( $reader ) {
				// With the modes disabled the query string is not read: every request is in the default mode.
				return $this->settings->is_enabled() ? $reader() : array();
			}
		);
		$this->applier = new VariantApplier(
			array( $this, 'variants_of_the_request' ),
			array( $this->lookup, 'stylesheet' ),
			function () use ( $front ) {
				return $this->settings->is_enabled() && $front();
			}
		);
	}

	/**
	 * Returns the identifier of the module.
	 *
	 * @return string
	 */
	public function id() {
		return 'modes';
	}

	/**
	 * Returns the identifiers of the modules this module needs.
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
		// Nothing to create: the modes are declared in code.
	}

	/**
	 * Registers the hooks of the module: the registrations in the Triples module, the class of the body, the translations.
	 *
	 * @return void
	 */
	public function boot() {
		require_once dirname( __DIR__ ) . '/functions.php';

		$integration = new TriplesIntegration( $this->modes );

		( $this->add_action )( 'triples_register_entity_types', array( $integration, 'register_entity_type' ), 10, 1 );
		( $this->add_action )( 'triples_register_predicates', array( $integration, 'register_predicate' ), 10, 1 );
		$templates = new TemplateIntegration( $this->lookup );

		( $this->add_action )( 'triples_register_entity_types', array( $templates, 'register_entity_types' ), 10, 1 );
		( $this->add_action )( 'triples_register_predicates', array( $templates, 'register_predicates' ), 10, 1 );
		( $this->add_action )( 'body_class', array( $this, 'body_class' ), 10, 1 );
		( $this->add_action )( 'init', array( $this, 'register_variant_filters' ), 20, 0 );
		( $this->add_action )( 'init', array( $this, 'register_link_block' ), 10, 0 );
		( $this->add_action )( 'init', array( $this, 'register_stylesheet_loader' ), 20, 0 );
		( $this->add_action )( 'wp_insert_post', array( new NewPostTemplate( $this->default_templates ), 'apply' ), 10, 3 );

		if ( ( $this->is_admin )() ) {
			( new Admin( new Environment(), $this->modes, $this->variants(), $this->lookup, $this->settings, $this->stylesheets(), $this->stylesheet_files, $this->default_templates ) )->register( $this->add_action );
		}
	}

	/**
	 * Removes the data owned by the module.
	 *
	 * @return void
	 */
	public function uninstall() {
		delete_option( Settings::OPTION );
		delete_option( DefaultTemplates::OPTION );
	}

	/**
	 * Returns the settings.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Returns the registry of the modes.
	 *
	 * @return ModeRegistry
	 */
	public function modes() {
		return $this->modes;
	}

	/**
	 * Returns the finder of the mode of the request.
	 *
	 * @return ActiveMode
	 */
	public function active() {
		return $this->active;
	}

	/**
	 * Returns the variants of templates and of template parts.
	 *
	 * @return Variants
	 * @throws \LogicException When the Triples module is not enabled.
	 */
	public function variants() {
		if ( null === $this->variants ) {
			$statements = ( $this->statements )();

			if ( null === $statements ) {
				throw new \LogicException( 'The Triples module is not enabled.' );
			}

			$this->variants = new Variants( $statements, $this->lookup );
		}

		return $this->variants;
	}

	/**
	 * Returns the stylesheets of the modes.
	 *
	 * @return Stylesheets
	 * @throws \LogicException When the Triples module is not enabled.
	 */
	public function stylesheets() {
		if ( null === $this->stylesheets ) {
			$statements = ( $this->statements )();

			if ( null === $statements ) {
				throw new \LogicException( 'The Triples module is not enabled.' );
			}

			$this->stylesheets = new Stylesheets( $statements, $this->stylesheet_files );
		}

		return $this->stylesheets;
	}

	/**
	 * Hooks the loading of the stylesheets of the mode of the request. Action `init`, priority 20, so that the filter of the priority
	 * has been added by plugins and themes.
	 *
	 * @return void
	 */
	public function register_stylesheet_loader() {
		$loader = new StylesheetLoader(
			function () {
				return $this->settings->is_enabled();
			},
			function () {
				return $this->active->mode();
			},
			function () {
				return null === ( $this->statements )() ? null : $this->stylesheets();
			},
			$this->stylesheet_files
		);

		/**
		 * Filters the priority at which the stylesheets of a mode are enqueued (`wp_enqueue_scripts`).
		 *
		 * The styles of a theme are enqueued at the default priority, 10: the 100 of the modes comes after them, so that the stylesheet of a
		 * mode can override them without `!important`.
		 *
		 * @param int $priority Priority; 100 by default.
		 */
		$priority = (int) ( $this->apply_filters )( 'modes_stylesheet_priority', 100 );

		( $this->add_action )( 'wp_enqueue_scripts', array( $loader, 'enqueue' ), $priority, 0 );
	}

	/**
	 * Returns the block that links to another mode.
	 *
	 * @return LinkBlock
	 */
	public function link_block() {
		if ( null === $this->link_block ) {
			$this->link_block = new LinkBlock( $this->modes, $this->active, $this->settings );
		}

		return $this->link_block;
	}

	/**
	 * Registers the block `modes/link`. Action `init`, priority 10.
	 *
	 * @return void
	 */
	public function register_link_block() {
		$this->link_block()->register( __DIR__ . '/../blocks/link' );
	}

	/**
	 * Returns the applier of the variants.
	 *
	 * @return VariantApplier
	 */
	public function applier() {
		return $this->applier;
	}

	/**
	 * Hooks the application of the variants to WordPress: the template hierarchy of every type of template (the types of core, and those
	 * that the filter `modes_template_types` adds) and the blocks. Action `init`, priority 20, so that the plugins that add types have run.
	 *
	 * @return void
	 */
	public function register_variant_filters() {
		/**
		 * Filters the types of template whose hierarchy gets the variants: the `$type` of `{$type}_template_hierarchy`.
		 *
		 * @param string[] $types The types documented by WordPress core. Add the type of a plugin that calls `get_query_template()`.
		 */
		$types = ( $this->apply_filters )( 'modes_template_types', VariantApplier::TYPES );

		$this->applier->register( $this->add_action, is_array( $types ) ? $types : VariantApplier::TYPES );
	}

	/**
	 * Returns the variants of the mode of the request for a kind: the id of the variant by id of the template. Empty when the Triples module
	 * is not enabled.
	 *
	 * @param string $kind `template` or `template_part`.
	 * @return array<string, string>
	 */
	public function variants_of_the_request( $kind ) {
		return null === ( $this->statements )() ? array() : $this->variants()->map( $this->active->mode(), $kind );
	}

	/**
	 * Adds the class of the mode of the request to the body. Filter `body_class`, priority 10.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		$classes = is_array( $classes ) ? $classes : array();

		if ( ! $this->settings->is_enabled() ) {
			return $classes;
		}

		$classes[] = 'modes-mode-' . $this->active->mode()->slug();

		return $classes;
	}

	/**
	 * Reads the query string: the keys `mode` and the aliases of the registered modes, only those.
	 *
	 * @return array<string, string>
	 */
	public function read_query() {
		$definitions = array( 'mode' => FILTER_UNSAFE_RAW );

		foreach ( $this->modes->all() as $mode ) {
			if ( null !== $mode->alias() ) {
				$definitions[ $mode->alias() ] = FILTER_UNSAFE_RAW;
			}
		}

		$values = filter_input_array( INPUT_GET, $definitions, false );

		return is_array( $values ) ? array_filter( $values, 'is_string' ) : array();
	}
}
