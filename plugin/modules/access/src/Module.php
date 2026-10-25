<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Who can reach a post: private links with a secret token.
 *
 * @package Otherguise
 */

namespace Otherguise\Access;

use Otherguise\Access\Admin\SettingsScreen;
use Otherguise\Access\Cli\LinksCommand;
use Otherguise\Access\Editor\LinkPanel;
use Otherguise\Access\Link\Links;
use Otherguise\Access\Link\Visit;
use Otherguise\Access\Rest\LinkRoutes;
use Otherguise\Access\Rest\UnlistedRoutes;
use Otherguise\Access\Settings\Settings;
use Otherguise\Access\Unlisted\Filters;
use Otherguise\Access\Unlisted\Unlisted;
use Otherguise\Core\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the access module (slice 400): a post can have a **private link**, the address of the site with a secret token, that
 * shows the post to someone who is not logged in even if it is not published. It has no dependency on the other modules: it can be lifted
 * out of the plugin (the architecture test checks it), and the other modules use it through the function `access_url()`.
 */
final class Module implements ModuleInterface {
	/**
	 * Adds an action or a filter.
	 *
	 * @var callable
	 */
	private $add_action;

	/**
	 * Returns the address of a file of the editor directory of the module.
	 *
	 * @var callable
	 */
	private $editor_url;

	/**
	 * The links, built when they are needed.
	 *
	 * @var Links|null
	 */
	private $links = null;

	/**
	 * The unlisted state, built when it is needed.
	 *
	 * @var Unlisted|null
	 */
	private $unlisted = null;

	/**
	 * The settings, built when they are needed.
	 *
	 * @var Settings|null
	 */
	private $settings = null;

	/**
	 * Builds the module.
	 *
	 * @param callable|null $add_action Adds an action or a filter; defaults to WordPress `add_action`.
	 * @param callable|null $editor_url Receives a file of `modules/access/editor/link/` and returns its address; defaults to `plugins_url()`.
	 */
	public function __construct( $add_action = null, $editor_url = null ) {
		$this->add_action = $add_action ?? 'add_action';
		$this->editor_url = $editor_url ?? static function ( $file ) {
			return plugins_url( 'modules/access/editor/link/' . $file, OTHERGUISE_PLUGIN_FILE );
		};
	}

	/**
	 * Returns the identifier of the module.
	 *
	 * @return string
	 */
	public function id() {
		return 'access';
	}

	/**
	 * Returns the identifiers of the modules this module needs: none.
	 *
	 * @return string[]
	 */
	public function dependencies() {
		return array();
	}

	/**
	 * Creates what the module needs when the plugin is activated.
	 *
	 * @return void
	 */
	public function activate() {
		// Nothing to create: the tokens are post metas.
	}

	/**
	 * The links of the site.
	 *
	 * @return Links
	 */
	public function links() {
		if ( null === $this->links ) {
			$this->links = new Links();
		}

		return $this->links;
	}

	/**
	 * The unlisted state.
	 *
	 * @return Unlisted
	 */
	public function unlisted() {
		if ( null === $this->unlisted ) {
			$this->unlisted = new Unlisted( $this->links() );
		}

		return $this->unlisted;
	}

	/**
	 * The settings.
	 *
	 * @return Settings
	 */
	public function settings() {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}

		return $this->settings;
	}

	/**
	 * Registers the hooks that stay whatever the settings: the settings screen, the reading of the settings (`enable_links`, `enable_unlisted`), the export (a file that carries a token carries a valid
	 * link, so the tokens stay out of it even when the links are off), the command, and the reading of the settings itself, on `init`
	 * (priority 1, so that a plugin that sets a filter on `plugins_loaded` or earlier is heard).
	 *
	 * @return void
	 */
	public function boot() {
		require_once dirname( __DIR__ ) . '/functions.php';

		if ( is_admin() ) {
			( new SettingsScreen( $this->settings() ) )->register( $this->add_action );
		}

		( $this->add_action )( 'init', array( $this, 'enable_links' ), 1, 0 );
		( $this->add_action )( 'init', array( $this, 'enable_unlisted' ), 1, 0 );
		( $this->add_action )( 'wxr_export_skip_postmeta', array( $this, 'skip_in_export' ), 10, 2 );
		( $this->add_action )( 'cli_init', array( $this, 'register_command' ), 10, 0 );
	}

	/**
	 * Registers the hooks of the private links, **only when the setting is on**: switched off, the request that carries a token is not
	 * answered, and the panel and the routes do not exist. Action `init`, priority 1.
	 *
	 * @return void
	 */
	public function enable_links() {
		if ( ! $this->settings()->links_enabled() ) {
			return;
		}

		$settings = $this->settings();
		$can      = static function ( $id ) use ( $settings ) {
			return $settings->may_share( (int) $id );
		};
		$visit    = new Visit( $this->links() );
		$routes   = new LinkRoutes( $this->links(), $can );
		$panel    = new LinkPanel(
			$this->editor_url,
			null,
			static function ( $capability, $id = 0 ) use ( $can ) {
				unset( $capability );

				return $can( $id );
			}
		);

		( $this->add_action )( 'query_vars', array( $visit, 'query_vars' ), 10, 1 );
		( $this->add_action )( 'parse_request', array( $visit, 'parse_request' ), 10, 1 );
		( $this->add_action )( 'posts_pre_query', array( $visit, 'posts_pre_query' ), 10, 2 );
		( $this->add_action )( 'redirect_canonical', array( $visit, 'redirect_canonical' ), 10, 1 );
		( $this->add_action )( 'send_headers', array( $visit, 'send_headers' ), 10, 0 );
		( $this->add_action )( 'wp_robots', array( $visit, 'robots' ), 10, 1 );
		( $this->add_action )( 'rest_api_init', array( $routes, 'register' ), 10, 0 );
		( $this->add_action )( 'enqueue_block_editor_assets', array( $panel, 'enqueue' ), 10, 0 );
	}

	/**
	 * Registers the filters of the unlisted state, **only when the setting is on**: switched off, the unlisted posts are back in the lists
	 * (the state is kept). Action `init`, priority 1.
	 *
	 * @return void
	 */
	public function enable_unlisted() {
		if ( ! $this->settings()->unlisted_enabled() ) {
			return;
		}

		$filters = new Filters( $this->unlisted() );
		$routes  = new UnlistedRoutes( $this->unlisted() );

		( $this->add_action )( 'pre_get_posts', array( $filters, 'pre_get_posts' ), 10, 1 );
		( $this->add_action )( 'wp_sitemaps_posts_query_args', array( $filters, 'sitemap_arguments' ), 10, 1 );
		( $this->add_action )( 'get_previous_post_where', array( $filters, 'adjacent_where' ), 10, 1 );
		( $this->add_action )( 'get_next_post_where', array( $filters, 'adjacent_where' ), 10, 1 );
		( $this->add_action )( 'wp_robots', array( $filters, 'robots' ), 10, 1 );
		( $this->add_action )( 'display_post_states', array( $filters, 'post_states' ), 10, 2 );
		( $this->add_action )( 'rest_api_init', array( $routes, 'register' ), 10, 0 );
	}

	/**
	 * Keeps the tokens out of the WordPress export (a file that carries a token carries a valid link). Filter `wxr_export_skip_postmeta`.
	 *
	 * @param mixed  $skip     Whether the meta is skipped.
	 * @param string $meta_key Key of the meta.
	 * @return mixed
	 */
	public function skip_in_export( $skip, $meta_key = '' ) {
		return in_array( $meta_key, array( Links::TOKEN, Links::CREATED ), true ) ? true : $skip;
	}

	/**
	 * Registers the WP-CLI command `wp otherguise access`. Action `cli_init`.
	 *
	 * @return void
	 */
	public function register_command() {
		\WP_CLI::add_command( 'otherguise access', new LinksCommand( $this->links(), null, null, null, null, array( $this->settings(), 'links_enabled' ), $this->unlisted() ) );
	}

	/**
	 * Removes the data owned by the module: every token.
	 *
	 * @return void
	 */
	public function uninstall() {
		delete_post_meta_by_key( Links::TOKEN );
		delete_post_meta_by_key( Links::CREATED );
		delete_post_meta_by_key( Unlisted::META );
		delete_option( Settings::OPTION );
	}
}
