<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Books assembled from posts: composition, table of contents, index and PDF output.
 *
 * @package Otherguise
 */

namespace Otherguise\Books;

use Otherguise\Books\Admin\SettingsScreen;
use Otherguise\Books\Preview\EditorPreview;
use Otherguise\Books\Cli\RendererCommand;
use Otherguise\Books\Cli\SizesCommand;
use Otherguise\Books\Images\PrintFallback;
use Otherguise\Books\Images\PrintSizes;
use Otherguise\Books\Images\PrintPixelRatio;
use Otherguise\Books\Images\PrintSrcset;
use Otherguise\Books\Pdf\JobState;
use Otherguise\Books\Pdf\PdfJobs;
use Otherguise\Books\Pdf\PdfStore;
use Otherguise\Books\Pdf\WordPressPosts;
use Otherguise\Books\Render\GotenbergClient;
use Otherguise\Books\Rest\PdfRoutes;
use Otherguise\Books\Settings\PageFormat;
use Otherguise\Books\Settings\RendererSettings;
use Otherguise\Core\ModuleInterface;
use Otherguise\Core\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the books module.
 */
final class Module implements ModuleInterface {
	/**
	 * Name of the daily event that deletes the old PDF files.
	 */
	public const CLEANUP_EVENT = 'books_pdf_cleanup';

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
	 * Builds the module.
	 *
	 * @param callable|null $add_action Adds an action or a filter; defaults to WordPress `add_action`.
	 * @param callable|null $editor_url Receives a file of `modules/books/editor/preview/` and returns its address; defaults to `plugins_url()`.
	 */
	public function __construct( $add_action = null, $editor_url = null ) {
		$this->add_action = $add_action ?? 'add_action';
		$this->editor_url = $editor_url ?? static function ( $file ) {
			return plugins_url( 'modules/books/editor/preview/' . $file, OTHERGUISE_PLUGIN_FILE );
		};
	}

	/**
	 * Returns the identifier of the module.
	 *
	 * @return string
	 */
	public function id() {
		return 'books';
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
		// Nothing to create yet.
	}

	/**
	 * Registers the settings screen, the preview panel of the editor (slice 219) and the PDF preview (slice 220).
	 *
	 * @return void
	 */
	public function boot() {
		$format   = new PageFormat();
		$renderer = new RendererSettings();
		$settings = new SettingsScreen( $format, $renderer );
		$preview  = new EditorPreview( $format, array( $this, 'preview_modes' ), $this->editor_url, null, null, null, array( $renderer, 'is_configured' ) );
		$jobs     = $this->jobs( $format, $renderer );
		$sizes    = new PrintSizes( $format );
		$srcset   = new PrintSrcset();
		$fallback = new PrintFallback( $sizes );
		$ratio    = new PrintPixelRatio();
		$routes   = new PdfRoutes( $jobs );

		( $this->add_action )( 'admin_init', array( $settings, 'register' ), 10, 0 );
		( $this->add_action )( 'admin_menu', array( $settings, 'add_menu' ), 10, 0 );
		( $this->add_action )( 'option_page_capability_' . SettingsScreen::GROUP, array( $settings, 'save_capability' ), 10, 0 );
		( $this->add_action )( 'enqueue_block_editor_assets', array( $preview, 'enqueue' ), 10, 0 );
		( $this->add_action )( 'show_admin_bar', array( $preview, 'admin_bar' ), 10, 1 );
		( $this->add_action )( 'rest_api_init', array( $routes, 'register' ), 10, 0 );
		( $this->add_action )( PdfJobs::EVENT, array( $jobs, 'run' ), 10, 3 );
		( $this->add_action )( self::CLEANUP_EVENT, array( $jobs, 'cleanup' ), 10, 0 );
		( $this->add_action )( 'admin_init', array( $this, 'schedule_cleanup' ), 10, 0 );
		( $this->add_action )( 'cli_init', array( $this, 'register_command' ), 10, 0 );
		( $this->add_action )( 'init', array( $sizes, 'register' ), 20, 0 );
		( $this->add_action )( 'wp_calculate_image_srcset', array( $srcset, 'filter' ), 20, 1 );
		( $this->add_action )( 'image_downsize', array( $fallback, 'filter' ), 100, 3 );
		( $this->add_action )( 'wp_head', array( $ratio, 'print_script' ), 0, 0 );
	}

	/**
	 * Registers the WP-CLI commands `wp otherguise books renderer`. Action `cli_init`.
	 *
	 * @return void
	 */
	public function register_command() {
		\WP_CLI::add_command( 'otherguise books sizes', new SizesCommand( new PrintSizes( new PageFormat() ) ) );
		\WP_CLI::add_command(
			'otherguise books renderer',
			new RendererCommand(
				new RendererSettings(),
				static function ( $address ) {
					return ( new GotenbergClient( $address ) )->health();
				}
			)
		);
	}

	/**
	 * Builds the PDF jobs: the posts and the modes come from WordPress and the Modes module, the server from the settings.
	 *
	 * @param PageFormat       $format   Format of the page.
	 * @param RendererSettings $renderer Settings of the server.
	 * @return PdfJobs
	 */
	public function jobs( PageFormat $format, RendererSettings $renderer ) {
		$posts = new WordPressPosts( null, null, array( $this, 'page_address' ) );

		return new PdfJobs(
			$renderer,
			$format,
			self::store(),
			new JobState(),
			array( $posts, 'info' ),
			array( $this, 'mode_exists' ),
			array( $posts, 'address' ),
			static function ( $address ) {
				return new GotenbergClient( $address );
			},
			static function ( $post_id, $mode, $key ) {
				wp_schedule_single_event( time(), PdfJobs::EVENT, array( (int) $post_id, (string) $mode, (string) $key ) );
				spawn_cron();
			}
		);
	}

	/**
	 * The store of the PDF files: the directory `books-pdf` of the uploads.
	 *
	 * @return PdfStore
	 */
	public static function store() {
		$uploads = wp_upload_dir( null, false );

		return new PdfStore( rtrim( (string) ( $uploads['basedir'] ?? '' ), '/' ) . '/books-pdf' );
	}

	/**
	 * Tells whether a mode exists, for the PDF routes.
	 *
	 * @param string $slug Slug of the mode.
	 * @return bool
	 */
	public function mode_exists( $slug ) {
		return in_array( $slug, array_column( $this->preview_modes(), 'value' ), true );
	}

	/**
	 * The address of a page in a mode: the one the Modes module builds (nothing is added for the default mode), else the permalink with
	 * `?mode=`.
	 *
	 * @param string $permalink Permalink.
	 * @param string $slug      Slug of the mode.
	 * @return string
	 */
	public function page_address( $permalink, $slug ) {
		$modes = Modules::get( 'modes' );

		if ( $modes instanceof \Otherguise\Modes\Module && $modes->modes()->has( $slug ) ) {
			return \Otherguise\Modes\Link\ModeUrl::build( $permalink, $modes->modes()->get( $slug ), $modes->modes() );
		}

		return ( new WordPressPosts() )->default_address( $permalink, $slug );
	}

	/**
	 * Schedules the daily cleanup of the PDF files when it is not. Action `admin_init`.
	 *
	 * @return void
	 */
	public function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CLEANUP_EVENT ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::CLEANUP_EVENT );
		}
	}

	/**
	 * Returns the modes the preview panel offers: `value` (slug) and `label`. Empty when the Modes module is not there.
	 *
	 * @return array<int, array{value:string,label:string}>
	 */
	public function preview_modes() {
		$modes = Modules::get( 'modes' );

		if ( ! $modes instanceof \Otherguise\Modes\Module ) {
			return array();
		}

		$choices = array();

		foreach ( $modes->modes()->all() as $mode ) {
			$choices[] = array(
				'value' => $mode->slug(),
				'label' => $mode->label(),
			);
		}

		return $choices;
	}

	/**
	 * Removes the data owned by the module.
	 *
	 * @return void
	 */
	public function uninstall() {
		delete_option( PageFormat::OPTION );
		delete_option( RendererSettings::OPTION );
		wp_clear_scheduled_hook( self::CLEANUP_EVENT );
		wp_clear_scheduled_hook( PdfJobs::EVENT );
		self::store()->remove_all();
	}
}
