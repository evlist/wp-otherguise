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

use Otherguise\Core\DescribedModule;
use Otherguise\Core\DisabledByDefault;
use Otherguise\Core\ModuleInterface;
use Otherguise\Core\Modules;
use Otherguise\Wiki\Admin\WantedScreen;
use Otherguise\Wiki\Tree\CitedBy;
use Otherguise\Wiki\Tree\Citations;
use Otherguise\Wiki\Tree\Links;
use Otherguise\Wiki\Tree\Reserved;
use Otherguise\Wiki\Tree\SearchTitles;
use Otherguise\Wiki\Tree\Router as TreeRouter;
use Otherguise\Wiki\Admin\SettingsScreen;
use Otherguise\Wiki\Cli\PagesCommand;
use Otherguise\Wiki\Cli\TreeCommand;
use Otherguise\Wiki\Editor\RulesPanel;
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
use Otherguise\Wiki\Rest\RulesRoutes;
use Otherguise\Wiki\Tree\Branch;
use Otherguise\Wiki\Tree\Keys;
use Otherguise\Wiki\Tree\Nodes;
use Otherguise\Wiki\Tree\Pages as TreePages;
use Otherguise\Wiki\Tree\Rules;
use Otherguise\Wiki\Tree\RulesMeta;
use Otherguise\Wiki\Tree\Saving as TreeSaving;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the wiki module (slice 500, design note `docs/design/wiki.md`): the spaces of the wiki, and later the names of the pages
 * (statements of Triples, slice 501), the pages (502) and their addresses (503). It depends on `triples` from the start, because the names
 * are statements.
 */
final class Module implements ModuleInterface, DescribedModule, DisabledByDefault {
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
	 * The router of the tree, built when it is needed.
	 *
	 * @var TreeRouter|null
	 */
	private $tree_router = null;

	/**
	 * The index of the links, built when it is needed.
	 *
	 * @var Citations|null
	 */
	private $citations = null;

	/**
	 * The page that does not exist, built when it is needed.
	 *
	 * @var MissingPage|null
	 */
	private $missing = null;

	/**
	 * The index of the tree, built when it is needed.
	 *
	 * @var Keys|null
	 */
	private $keys = null;

	/**
	 * The pages of the tree, built when they are needed.
	 *
	 * @var TreePages|null
	 */
	private $tree = null;

	/**
	 * The rules of the branches, built when they are needed.
	 *
	 * @var Rules|null
	 */
	private $rules = null;

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
		// The routing has no rewrite rule of its own: one flush drops the rule of the first model.
		flush_rewrite_rules( false );
		update_option( TreeRouter::OPTION, TreeRouter::VERSION );
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

		$tree_saving = new TreeSaving( array( $this, 'tree' ) );
		$branch      = new Branch( $this->rules() );
		$rules_meta  = new RulesMeta();
		$rules_route = new RulesRoutes( $this->rules() );

		( $this->add_action )( 'init', array( $rules_meta, 'register' ), 10, 0 );
		( $this->add_action )( 'init', array( $branch, 'register_block' ), 10, 0 );
		( $this->add_action )( 'body_class', array( $branch, 'body_class' ), 10, 1 );
		( $this->add_action )( 'page_template_hierarchy', array( $branch, 'templates' ), 10, 1 );
		( $this->add_action )( 'rest_api_init', array( $rules_route, 'register' ), 10, 0 );
		( $this->add_action )(
			'enqueue_block_editor_assets',
			array(
				new RulesPanel(
					static function ( $file ) {
						return plugins_url( 'modules/wiki/editor/rules/' . $file, OTHERGUISE_PLUGIN_FILE );
					}
				),
				'enqueue',
			),
			10,
			0
		);

		( $this->add_action )( 'rest_pre_insert_' . Nodes::TYPE, array( $tree_saving, 'check_rest' ), 10, 2 );
		( $this->add_action )( 'rest_after_insert_' . Nodes::TYPE, array( $tree_saving, 'after_rest' ), 10, 1 );
		( $this->add_action )( 'wp_after_insert_post', array( $tree_saving, 'after_insert' ), 10, 1 );
		// Slice 503: no rewrite rule; the route is resolved on parse_request. WordPress's own redirect is turned off for our requests.
		( $this->add_action )( 'init', array( $this->tree_router(), 'maybe_flush' ), 99, 0 );
		( $this->add_action )( 'parse_request', array( $this->tree_router(), 'parse_request' ), 10, 1 );
		( $this->add_action )( 'redirect_canonical', array( $this->tree_router(), 'redirect_canonical' ), 5, 2 );
		( $this->add_action )( 'page_link', array( $this->tree_router(), 'page_link' ), 10, 2 );

		// Slice 505: ordinary links are links by name; the link tool of the editor tells two pages of one title apart.
		$links  = new Links( $this->keys(), $this->tree_router() );
		$search = new SearchTitles( $this->keys() );

		$cited = new CitedBy( $this->citations(), $this->keys(), $this->tree_router() );

		( $this->add_action )( 'the_content', array( $links, 'filter' ), 20, 1 );
		( $this->add_action )( 'init', array( $cited, 'register_block' ), 10, 0 );
		( $this->add_action )( 'wp_after_insert_post', array( $this, 'index_links' ), 20, 2 );
		( $this->add_action )( 'rest_post_search_query', array( $search, 'begin' ), 10, 1 );
		( $this->add_action )( 'rest_post_dispatch', array( $search, 'end' ), 10, 1 );
		( $this->add_action )( 'the_title', array( $search, 'title' ), 10, 2 );

		$presentation = new Presentation( $this->missing(), dirname( __DIR__ ) . '/templates/missing-page.php' );
		$create       = new CreateAction( $this->tree(), $this->missing() );

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
			( new WantedScreen( $this->citations(), $this->keys(), $this->tree_router() ) )->register( $this->add_action );
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
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'                    => Keys::KEY,
					'label'                   => __( 'Has the key', 'otherguise' ),
					'inverse_label'           => __( 'Is the key of', 'otherguise' ),
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
					'slug'                    => Keys::FORMER,
					'label'                   => __( 'Had the key', 'otherguise' ),
					'inverse_label'           => __( 'Was the key of', 'otherguise' ),
					'subject_types'           => $post,
					'object_types'            => $string,
					'max_subjects_per_object' => 1,
				)
			)
		);
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'                    => Keys::FOLDED,
					'label'                   => __( 'Has the folded key', 'otherguise' ),
					'inverse_label'           => __( 'Is the folded key of', 'otherguise' ),
					'subject_types'           => $post,
					'object_types'            => $string,
					'max_objects_per_subject' => 1,
				)
			)
		);
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'                    => Keys::TITLE,
					'label'                   => __( 'Has the title', 'otherguise' ),
					'inverse_label'           => __( 'Is the title of', 'otherguise' ),
					'subject_types'           => $post,
					'object_types'            => $string,
					'max_objects_per_subject' => 1,
				)
			)
		);

		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => Citations::CITES,
					'label'         => __( 'Links to', 'otherguise' ),
					'inverse_label' => __( 'Cited by', 'otherguise' ),
					'subject_types' => $post,
					'object_types'  => $post,
				)
			)
		);
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => Citations::WANTS,
					'label'         => __( 'Wants the page', 'otherguise' ),
					'inverse_label' => __( 'Is wanted by', 'otherguise' ),
					'subject_types' => $post,
					'object_types'  => $string,
				)
			)
		);
	}

	/**
	 * The index of the tree (the hierarchical model, slice 501): the keys of the pages, as statements of the module Triples.
	 *
	 * @return Keys
	 */
	public function keys() {
		if ( null === $this->keys ) {
			$this->keys = new Keys(
				static function () {
					return Modules::get( 'triples' )->statements();
				},
				new Reserved()
			);
		}

		return $this->keys;
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
			$this->missing = new MissingPage( $this->keys(), $this->tree_router() );
		}

		return $this->missing;
	}

	/**
	 * The index of the links between the pages (slice 506).
	 *
	 * @return Citations
	 */
	public function citations() {
		if ( null === $this->citations ) {
			$this->citations = new Citations(
				$this->keys(),
				new Links( $this->keys(), $this->tree_router() ),
				static function () {
					return Modules::get( 'triples' )->statements();
				}
			);
		}

		return $this->citations;
	}

	/**
	 * Brings the links of a saved page into the index. Action `wp_after_insert_post`.
	 *
	 * @param mixed $post_id The page.
	 * @param mixed $post    The post object.
	 * @return void
	 */
	public function index_links( $post_id, $post = null ) {
		if ( ! is_object( $post ) || Nodes::TYPE !== ( $post->post_type ?? '' ) || in_array( $post->post_status ?? '', array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return;
		}

		$this->citations()->sync( (int) $post_id, (string) ( $post->post_content ?? '' ) );
	}

	/**
	 * The router of the tree (the hierarchical model, slice 503): no rewrite rule, the path is walked on `parse_request`.
	 *
	 * @return TreeRouter
	 */
	public function tree_router() {
		if ( null === $this->tree_router ) {
			$this->tree_router = new TreeRouter( $this->keys() );
		}

		return $this->tree_router;
	}

	/**
	 * The rules of the branches (the hierarchical model, slice 500): set on any page, inherited by its descendants.
	 *
	 * @return Rules
	 */
	public function rules() {
		if ( null === $this->rules ) {
			$this->rules = new Rules();
		}

		return $this->rules;
	}

	/**
	 * The pages of the tree (the hierarchical model, slice 502): the index of the tree and the pages of WordPress.
	 *
	 * @return TreePages
	 */
	public function tree() {
		if ( null === $this->tree ) {
			$this->tree = new TreePages(
				$this->keys(),
				new Nodes(),
				$this->rules(),
				function ( $id ) {
					$this->citations()->claimed( $id );
				}
			);
		}

		return $this->tree;
	}

	/**
	 * Registers the WP-CLI commands `wp otherguise wiki` and `wp otherguise tree`. Action `cli_init`.
	 *
	 * @return void
	 */
	public function register_command() {
		\WP_CLI::add_command( 'otherguise wiki', new PagesCommand( $this->pages() ) );
		\WP_CLI::add_command( 'otherguise tree', new TreeCommand( $this->tree(), null, null, null, null, $this->citations() ) );
	}

	/**
	 * Removes the data owned by the module: the spaces. The statements of the names stay with the table of Triples, which keeps or deletes
	 * its data as its own setting says (`delete_data_on_uninstall`); the index can be made again (`Names::rebuild()`).
	 *
	 * @return void
	 */
	public function uninstall() {
		delete_option( Spaces::OPTION );
		delete_option( TreeRouter::OPTION );
	}

	/**
	 * The name of the module, for the switches of the overview.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Wiki', 'otherguise' );
	}

	/**
	 * What the module does, for the switches of the overview.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'The functions of a wiki: pages with titles for addresses, a tree of branches with rules, red links.', 'otherguise' );
	}
}
