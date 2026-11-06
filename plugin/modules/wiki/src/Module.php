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
use Otherguise\Wiki\Address\Router;
use Otherguise\Wiki\Admin\SettingsScreen;
use Otherguise\Wiki\Cli\PagesCommand;
use Otherguise\Wiki\Editor\SpacePanel;
use Otherguise\Wiki\Missing\CreateAction;
use Otherguise\Wiki\Missing\MissingPage;
use Otherguise\Wiki\Missing\Presentation;
use Otherguise\Wiki\Page\Lists;
use Otherguise\Wiki\Page\PageStore;
use Otherguise\Wiki\Page\Pages;
use Otherguise\Wiki\Page\PostType;
use Otherguise\Wiki\Page\Saving;
use Otherguise\Wiki\Page\SpaceBox;
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
	 * The pages, built when they are needed.
	 *
	 * @var Pages|null
	 */
	private $pages = null;

	/**
	 * The router, built when it is needed.
	 *
	 * @var Router|null
	 */
	private $router = null;

	/**
	 * The page that does not exist, built when it is needed.
	 *
	 * @var MissingPage|null
	 */
	private $missing = null;

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
		// Nothing to create but the rewrite rule: the spaces are an option, saved from the screen.
		$this->router()->add_rule();
		flush_rewrite_rules( false );
		update_option( Router::OPTION, Router::VERSION );
	}

	/**
	 * Registers the predicates of the names on the registration of Triples, and the screen of the spaces in the administration.
	 *
	 * @return void
	 */
	public function boot() {
		$type   = new PostType();
		$saving = new Saving( array( $this, 'pages' ) );

		( $this->add_action )( 'triples_register_predicates', array( $this, 'register_predicates' ), 10, 1 );
		( $this->add_action )( 'init', array( $type, 'register' ), 10, 0 );
		( $this->add_action )( 'update_post_metadata', array( $type, 'lock_space' ), 10, 4 );
		( $this->add_action )( 'delete_post_metadata', array( $type, 'lock_space' ), 10, 4 );
		( $this->add_action )( 'rest_pre_insert_' . PageStore::TYPE, array( $saving, 'check_rest' ), 10, 2 );
		( $this->add_action )( 'rest_after_insert_' . PageStore::TYPE, array( $saving, 'after_rest' ), 10, 1 );
		( $this->add_action )( 'wp_after_insert_post', array( $saving, 'after_insert' ), 10, 1 );
		( $this->add_action )( 'cli_init', array( $this, 'register_command' ), 10, 0 );
		( $this->add_action )( 'init', array( $this->router(), 'add_rule' ), 10, 0 );
		( $this->add_action )( 'init', array( $this->router(), 'maybe_flush' ), 99, 0 );
		( $this->add_action )( 'query_vars', array( $this->router(), 'query_vars' ), 10, 1 );
		( $this->add_action )( 'parse_request', array( $this->router(), 'parse_request' ), 10, 1 );
		( $this->add_action )( 'redirect_canonical', array( $this->router(), 'redirect_canonical' ), 5, 2 );
		( $this->add_action )( 'post_type_link', array( $this->router(), 'post_link' ), 10, 2 );

		$presentation = new Presentation( $this->missing(), dirname( __DIR__ ) . '/templates/missing-page.php' );
		$create       = new CreateAction( $this->pages(), $this->missing() );

		( $this->add_action )( 'init', array( $presentation, 'register_block' ), 10, 0 );
		( $this->add_action )( 'init', array( $presentation, 'register_template' ), 10, 0 );
		( $this->add_action )( '404_template_hierarchy', array( $presentation, 'hierarchy' ), 10, 1 );
		( $this->add_action )( 'template_include', array( $presentation, 'template' ), 99, 1 );
		( $this->add_action )( 'admin_post_' . MissingPage::ACTION, array( $create, 'handle' ), 10, 0 );
		( $this->add_action )(
			'enqueue_block_editor_assets',
			array(
				new SpacePanel(
					static function ( $file ) {
						return plugins_url( 'modules/wiki/editor/space/' . $file, OTHERGUISE_PLUGIN_FILE );
					}
				),
				'enqueue',
			),
			10,
			0
		);

		if ( is_admin() ) {
			$box   = new SpaceBox();
			$lists = new Lists(
				function ( $id ) {
					return $this->names()->current_of( $id );
				}
			);

			( new SettingsScreen() )->register( $this->add_action );
			( $this->add_action )( 'admin_notices', array( $saving, 'notice' ), 10, 0 );
			( $this->add_action )( 'add_meta_boxes_' . PageStore::TYPE, array( $box, 'register' ), 10, 0 );
			( $this->add_action )( 'save_post_' . PageStore::TYPE, array( $box, 'save' ), 10, 1 );
			( $this->add_action )( 'manage_' . PageStore::TYPE . '_posts_columns', array( $lists, 'columns' ), 10, 1 );
			( $this->add_action )( 'manage_' . PageStore::TYPE . '_posts_custom_column', array( $lists, 'column' ), 10, 2 );
			( $this->add_action )( 'post_row_actions', array( $lists, 'row_actions' ), 10, 2 );
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
	 * The pages: the names and the posts of WordPress.
	 *
	 * @return Pages
	 */
	public function pages() {
		if ( null === $this->pages ) {
			$this->pages = new Pages( $this->names(), new PageStore() );
		}

		return $this->pages;
	}

	/**
	 * The page that does not exist: the offer of the 404 of a wiki address.
	 *
	 * @return MissingPage
	 */
	public function missing() {
		if ( null === $this->missing ) {
			$this->missing = new MissingPage( $this->names() );
		}

		return $this->missing;
	}

	/**
	 * The router of the addresses.
	 *
	 * @return Router
	 */
	public function router() {
		if ( null === $this->router ) {
			$this->router = new Router( $this->names() );
		}

		return $this->router;
	}

	/**
	 * Registers the WP-CLI commands `wp otherguise wiki`. Action `cli_init`.
	 *
	 * @return void
	 */
	public function register_command() {
		\WP_CLI::add_command( 'otherguise wiki', new PagesCommand( $this->pages() ) );
	}

	/**
	 * Removes the data owned by the module: the spaces. The statements of the names stay with the table of Triples, which keeps or deletes
	 * its data as its own setting says (`delete_data_on_uninstall`); the index can be made again (`Names::rebuild()`).
	 *
	 * @return void
	 */
	public function uninstall() {
		delete_option( Spaces::OPTION );
		delete_option( Router::OPTION );
	}
}
