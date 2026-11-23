<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The index: topics, mentions, sources.
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

use Otherguise\Core\DescribedModule;
use Otherguise\Core\DisabledByDefault;
use Otherguise\Core\ModuleInterface;
use Otherguise\Core\Modules;
use Otherguise\Index\Admin\TermsScreen;
use Otherguise\Index\Cli\EnrichCommand;
use Otherguise\Index\Cli\IndexCommand;
use Otherguise\Index\Enrich\EnrichService;
use Otherguise\Index\Cli\KindsCommand;
use Otherguise\Index\Cli\SourcesCommand;
use Otherguise\Index\Cli\BuildCommand;
use Otherguise\Index\Build\IndexBuilder;
use Otherguise\Index\Build\Selection;
use Otherguise\Index\Admin\KindsScreen;
use Otherguise\Index\Cli\PagesCommand;
use Otherguise\Index\Cli\PhotosCommand;
use Otherguise\Index\Harvest\MentionElements;
use Otherguise\Index\Harvest\PageFetcher;
use Otherguise\Index\Harvest\SignalStore;
use Otherguise\Index\Job\PagesService;
use Otherguise\Index\Photo\PhotosService;
use Otherguise\Index\Source\Photos;
use Otherguise\Index\Source\Page;
use Otherguise\Index\Harvest\PageReader;
use Otherguise\Index\Source\Terms;
use Otherguise\Triples\Predicate\PredicateDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the Index module (slice 600, design note `docs/design/index.md`). Disabled by default. It depends on `triples`: a mention is a
 * statement. The sources of this slice are the categories and the tags that have a kind.
 */
final class Module implements ModuleInterface, DescribedModule, DisabledByDefault {
	public const BATCH_HOOK = 'otherguise_index_batch';
	public const OPTION     = 'index_sources';

	/**
	 * Adds an action or a filter.
	 *
	 * @var callable
	 */
	private $add_action;

	/**
	 * Posts waiting for the end of the request to be indexed.
	 *
	 * @var array<int, true>
	 */
	private $queue = array();

	/**
	 * The indexer, built when needed.
	 *
	 * @var Indexer|null
	 */
	private $indexer = null;

	/**
	 * The photographs and the tracks as sources, built when needed (slice 606).
	 *
	 * @var PhotosService|null
	 */
	private $photos = null;

	/**
	 * Builds the module.
	 *
	 * @param callable|null $add_action Adds an action or a filter; defaults to `add_action`.
	 */
	public function __construct( $add_action = null ) {
		$this->add_action = $add_action ?? 'add_action';
	}

	/**
	 * The identifier of the module.
	 *
	 * @return string
	 */
	public function id() {
		return 'index';
	}

	/**
	 * The modules it needs.
	 *
	 * @return string[]
	 */
	public function dependencies() {
		return array( 'triples' );
	}

	/**
	 * Nothing to create: the topics are terms, the mentions are statements.
	 *
	 * @return void
	 */
	public function activate() {}

	/**
	 * Registers the taxonomy, the predicates, the hooks, the screen and the command.
	 *
	 * @return void
	 */
	public function boot() {
		// The topics were the terms of `index_entry` until 2026-11-22: the taxonomy is renamed before it is registered under its new name.
		( $this->add_action )( 'init', array( TopicsMigration::class, 'run' ), 5, 0 );
		( $this->add_action )( 'init', array( TermTopics::class, 'register_taxonomy' ), 10, 0 );
		// Slice 603: the kinds are terms of a taxonomy; the built-in kinds are created once.
		( $this->add_action )( 'init', array( TermKindStore::class, 'register_taxonomy' ), 10, 0 );
		( $this->add_action )( 'init', array( Kinds::class, 'seed' ), 12, 0 );
		( $this->add_action )( 'triples_register_predicates', array( $this, 'register_predicates' ), 10, 1 );
		( $this->add_action )( 'wp_after_insert_post', array( $this, 'queue_post' ), 20, 1 );
		( $this->add_action )( 'set_object_terms', array( $this, 'queue_post' ), 10, 1 );
		( $this->add_action )( 'shutdown', array( $this, 'flush_queue' ), 10, 0 );
		( $this->add_action )( self::BATCH_HOOK, array( $this, 'run_batch' ), 10, 2 );
		( $this->add_action )( 'cli_init', array( $this, 'register_command' ), 10, 0 );
		// Slice 611: a consumer (the block of a book) asks for the lines of an index through this filter, without knowing the classes of this module.
		( $this->add_action )( 'index_build', array( $this, 'build_for_filter' ), 10, 3 );
		( $this->add_action )( 'index_mention_elements', array( $this, 'mention_elements_for_filter' ), 10, 2 );

		// Slice 602: the pages are read in the background, and a saved post is read a little later.
		( $this->add_action )( PagesService::TICK_HOOK, array( $this->pages(), 'tick' ), 10, 0 );
		// Slice 604: the Wikidata enrichment runs in the background only, and only when it was turned on.
		( $this->add_action )( EnrichService::TICK_HOOK, array( $this->enrich(), 'tick' ), 10, 0 );
		// Slice 606: the places of the photographs, asked again without network, when Media Helper says it has new ones and hourly while some wait.
		( $this->add_action )( PhotosService::TICK_HOOK, array( $this->photos(), 'tick' ), 10, 0 );
		( $this->add_action )( 'wp_media_helper_places_updated', array( $this->photos(), 'tick' ), 10, 0 );
		( $this->add_action )( PagesService::SINGLE_HOOK, array( $this->pages(), 'single' ), 10, 1 );
		( $this->add_action )( 'wp_after_insert_post', array( $this->pages(), 'on_save' ), 30, 1 );

		if ( is_admin() ) {
			$this->screen()->register( $this->add_action );
			( new KindsScreen() )->register( $this->add_action );
		}
	}

	/**
	 * Registers the predicates: `index/mentions` (a post, a topic) and its qualifiers. Action `triples_register_predicates`.
	 *
	 * @param \Otherguise\Triples\Predicate\PredicateRegistry $predicates Predicates.
	 * @return void
	 */
	public function register_predicates( $predicates ) {
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => Mentions::MENTIONS,
					'label'         => __( 'Mentions', 'otherguise' ),
					'inverse_label' => __( 'Is mentioned by', 'otherguise' ),
					'subject_types' => array( 'post' ),
					'object_types'  => array( 'term' ),
					'qualified_by'  => array( Mentions::SOURCE, Mentions::STRENGTH, Mentions::ROLE, Mentions::ANCHOR, Mentions::VIA, Mentions::VISIBLE ),
				)
			)
		);

		foreach ( array(
			Mentions::SOURCE   => __( 'Said by the source', 'otherguise' ),
			Mentions::STRENGTH => __( 'Strength', 'otherguise' ),
			Mentions::ROLE     => __( 'Role', 'otherguise' ),
			Mentions::ANCHOR   => __( 'Anchor', 'otherguise' ),
			Mentions::VIA      => __( 'Produced by', 'otherguise' ),
			Mentions::VISIBLE  => __( 'Shown in the mode', 'otherguise' ),
		) as $slug => $label ) {
			$predicates->register(
				PredicateDefinition::from_array(
					array(
						'slug'          => $slug,
						'label'         => $label,
						'subject_types' => array( 'statement' ),
						'object_types'  => array( 'string' ),
						'qualifies'     => array( Mentions::MENTIONS ),
					)
				)
			);
		}
	}

	/**
	 * The store of the topics.
	 *
	 * @return Topics
	 */
	public function topics() {
		return new TermTopics();
	}

	/**
	 * The mentions.
	 *
	 * @return Mentions
	 */
	public function mentions() {
		return new Mentions(
			static function () {
				return Modules::get( 'triples' )->statements();
			}
		);
	}

	/**
	 * The indexer, with the sources that are on.
	 *
	 * @return Indexer
	 */
	public function indexer() {
		if ( null === $this->indexer ) {
			$on      = get_option( self::OPTION, array( 'categories', 'tags' ) );
			$on      = is_array( $on ) ? $on : array( 'categories', 'tags' );
			$sources = array();

			foreach ( array(
				'categories'   => array( Terms::class, 'categories' ),
				'tags'         => array( Terms::class, 'tags' ),
				Page::SLUG     => fn() => new Page( new SignalStore(), '1' === (string) get_option( EnrichService::SWITCH, '' ) ),
				Photos::SLUG   => fn() => $this->photos()->source(),
			) as $slug => $make ) {
				if ( in_array( $slug, $on, true ) ) {
					$sources[] = $make();
				}
			}

			$this->indexer = new Indexer(
				new Resolver( $this->topics() ),
				$this->mentions(),
				$sources,
				static function ( $post_id ) {
					return 'publish' === get_post_status( (int) $post_id );
				}
			);
		}

		return $this->indexer;
	}

	/**
	 * The WordPress side of the job that reads the pages (slice 602).
	 *
	 * @return PagesService
	 */
	public function pages() {
		return new PagesService(
			fn() => $this->indexer(),
			fn() => $this->post_types(),
			array( $this, 'published_posts' ),
			new PageFetcher(),
			new PageReader(),
			new SignalStore(),
			fn( $id, $signals ) => $this->photos()->process( $id, $signals )
		);
	}

	/**
	 * The photographs and the tracks as sources of places (slice 606).
	 *
	 * @return PhotosService
	 */
	public function photos() {
		if ( null === $this->photos ) {
			$this->photos = new PhotosService( fn() => $this->indexer() );
		}

		return $this->photos;
	}

	/**
	 * Answers the filter `index_build`: the index of some posts as plain data, for a consumer that does not know the classes of this module.
	 *
	 * @param mixed                $result   What was answered before (an answer that is already there wins).
	 * @param int[]                $post_ids The posts, in the order of the book.
	 * @param array<string, mixed> $options  `include`, `exclude` (tokens of the selection), `context`, `rollup`.
	 * @return array{groups:array<string,array>,attribution:array{osm:bool,wikimedia:bool},errors?:string[]}|mixed With `errors` (and no group) for an option that is not valid.
	 */
	public function build_for_filter( $result, $post_ids = array(), $options = array() ) {
		if ( null !== $result ) {
			return $result;
		}

		$options = is_array( $options ) ? $options : array();

		$errors = array_merge( Selection::parse( (string) ( $options['include'] ?? '' ), (string) ( $options['exclude'] ?? '' ) )->errors(), IndexBuilder::rollup_errors( (string) ( $options['rollup'] ?? '' ) ) );

		// A wrong option is told, not hidden: the consumer shows the message instead of an index that is silently empty.
		if ( array() !== $errors ) {
			return array(
				'groups'      => array(),
				'attribution' => array(
					'osm'       => false,
					'wikimedia' => false,
				),
				'errors'      => $errors,
			);
		}

		$model = ( new IndexBuilder( $this->topics(), $this->mentions() ) )->build( array_map( 'intval', (array) $post_ids ), $options );

		return array(
			'groups'      => $model->groups(),
			'attribution' => $model->attribution(),
		);
	}

	/**
	 * Marks the elements of a post that stand for a mention (slice 612): `data-index-mention` on each, with the `via` that the mention holds. Filter
	 * `index_mention_elements`, which the merge of the books applies to the HTML of a unit.
	 *
	 * @param mixed $html    The HTML of the post.
	 * @param mixed $post_id The post.
	 * @return mixed The HTML, marked.
	 */
	public function mention_elements_for_filter( $html, $post_id = 0 ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}

		return ( new MentionElements( new PageReader(), new Page( new SignalStore(), '1' === (string) get_option( EnrichService::SWITCH, '' ) ), $this->mentions() ) )->mark( $html, (int) $post_id );
	}

	/**
	 * The Wikidata enrichment (slice 604).
	 *
	 * @return EnrichService
	 */
	public function enrich() {
		return new EnrichService( $this->topics(), $this->mentions() );
	}

	/**
	 * The post types that are indexed (filter `index_post_types`, default `post`).
	 *
	 * @return string[]
	 */
	public function post_types() {
		$types = apply_filters( 'index_post_types', array( 'post' ) );

		return is_array( $types ) ? array_values( array_map( 'strval', $types ) ) : array( 'post' );
	}

	/**
	 * A post changed (saved, or its terms): it is indexed at the end of the request, once, with its final terms. Actions
	 * `wp_after_insert_post` and `set_object_terms`.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public function queue_post( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id > 0 && in_array( get_post_type( $post_id ), $this->post_types(), true ) && ! wp_is_post_revision( $post_id ) ) {
			$this->queue[ $post_id ] = true;
		}
	}

	/**
	 * Indexes the posts queued during the request. Action `shutdown`.
	 *
	 * @return void
	 */
	public function flush_queue() {
		$ids         = array_keys( $this->queue );
		$this->queue = array();

		foreach ( $ids as $id ) {
			$this->indexer()->index_post( $id );
		}
	}

	/**
	 * Ids of the published posts, by page, oldest first.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Page size.
	 * @return int[]
	 */
	public function published_posts( $offset, $limit ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => $this->post_types(),
					'post_status'      => 'publish',
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'offset'           => (int) $offset,
					'posts_per_page'   => (int) $limit,
					'fields'           => 'ids',
					'no_found_rows'    => true,
				)
			)
		);
	}

	/**
	 * A batch of the background rebuild: reads a page of posts and schedules the next one. Action `otherguise_index_batch`.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Page size.
	 * @return void
	 */
	public function run_batch( $offset = 0, $limit = IndexCommand::BATCH ) {
		$ids = $this->published_posts( (int) $offset, (int) $limit );

		foreach ( $ids as $id ) {
			$this->indexer()->index_post( $id );
		}

		if ( count( $ids ) === (int) $limit ) {
			wp_schedule_single_event( time() + 5, self::BATCH_HOOK, array( (int) $offset + (int) $limit, (int) $limit ) );
		}
	}

	/**
	 * The screen of the kinds.
	 *
	 * @return TermsScreen
	 */
	public function screen() {
		return new TermsScreen(
			function ( $taxonomy, $search, $no_kind, $page, $per_page ) {
				return $this->list_terms( $taxonomy, $search, $no_kind, $page, $per_page );
			},
			function ( $taxonomy, array $kinds ) {
				return $this->apply_kinds( $taxonomy, $kinds );
			}
		);
	}

	/**
	 * Lists the terms of a taxonomy for the screen of the kinds.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $search   Search in the names.
	 * @param bool   $no_kind  Only the terms that have no kind.
	 * @param int    $page     Page, from 1.
	 * @param int    $per_page Page size.
	 * @return array{rows: array<int, array<string, mixed>>, total:int}
	 */
	public function list_terms( $taxonomy, $search, $no_kind, $page, $per_page ) {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'name',
			'search'     => $search,
		);

		if ( $no_kind ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => Terms::KIND_META,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => Terms::KIND_META,
					'value' => '',
				),
			);
		}

		$total = (int) wp_count_terms( $args );
		$rows  = array();

		foreach ( (array) get_terms(
			$args + array(
				'number' => (int) $per_page,
				'offset' => ( max( 1, (int) $page ) - 1 ) * (int) $per_page,
			)
		) as $term ) {
			$rows[] = array(
				'id'    => (int) $term->term_id,
				'name'  => (string) $term->name,
				'count' => (int) $term->count,
				'kind'  => (string) get_term_meta( (int) $term->term_id, Terms::KIND_META, true ),
			);
		}

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Sets the kinds of some terms and brings the mentions of their posts in line (at once for a few posts, in the background for many).
	 *
	 * @param string             $taxonomy Taxonomy.
	 * @param array<int, string> $kinds    Term id => kind, '' for none.
	 * @return int The number of terms that changed.
	 */
	public function apply_kinds( $taxonomy, array $kinds ) {
		$changed = 0;
		$posts   = array();

		foreach ( $kinds as $id => $kind ) {
			if ( (string) get_term_meta( (int) $id, Terms::KIND_META, true ) === $kind ) {
				continue;
			}

			if ( '' === $kind ) {
				delete_term_meta( (int) $id, Terms::KIND_META );
			} else {
				update_term_meta( (int) $id, Terms::KIND_META, $kind );
			}

			++$changed;

			foreach ( (array) get_objects_in_term( (int) $id, $taxonomy ) as $post_id ) {
				$posts[ (int) $post_id ] = true;
			}
		}

		if ( count( $posts ) <= 200 ) {
			foreach ( array_keys( $posts ) as $post_id ) {
				if ( in_array( get_post_type( $post_id ), $this->post_types(), true ) ) {
					$this->indexer()->index_post( $post_id );
				}
			}
		} elseif ( false === wp_next_scheduled( self::BATCH_HOOK, array( 0, IndexCommand::BATCH ) ) ) {
			wp_schedule_single_event( time() + 5, self::BATCH_HOOK, array( 0, IndexCommand::BATCH ) );
		}

		return $changed;
	}

	/**
	 * Sets the kind of a term named on the command line.
	 *
	 * @param string $taxonomy `category`, `post_tag`.
	 * @param string $term     Id, slug or name.
	 * @param string $kind     Kind, '' for none.
	 * @return true|string True, or the reason it failed.
	 */
	public function set_term_kind( $taxonomy, $term, $kind ) {
		if ( ! array_key_exists( $taxonomy, TermsScreen::taxonomies() ) ) {
			return 'The taxonomy must be ' . implode( ' or ', array_keys( TermsScreen::taxonomies() ) ) . '.';
		}

		$found = ctype_digit( $term ) ? get_term( (int) $term, $taxonomy ) : get_term_by( 'slug', $term, $taxonomy );
		$found = $found instanceof \WP_Term ? $found : get_term_by( 'name', $term, $taxonomy );

		if ( ! $found instanceof \WP_Term ) {
			return 'No such term: ' . $term . '.';
		}

		$this->apply_kinds( $taxonomy, array( (int) $found->term_id => $kind ) );

		return true;
	}

	/**
	 * Registers the WP-CLI command. Action `cli_init`.
	 *
	 * @return void
	 */
	public function register_command() {
		\WP_CLI::add_command(
			'otherguise index',
			new IndexCommand(
				$this->topics(),
				$this->indexer(),
				$this->mentions(),
				array( $this, 'published_posts' ),
				array( $this, 'set_term_kind' ),
				null,
				null,
				null,
				new PageReader()
			)
		);

		\WP_CLI::add_command( 'otherguise index enrich', new EnrichCommand( $this->enrich() ) );
		$this->pages_command();
	}

	/**
	 * The commands: `wp otherguise index` and `wp otherguise index pages`.
	 *
	 * @return void
	 */
	private function pages_command() {
		$pages = $this->pages();

		\WP_CLI::add_command( 'otherguise index kinds', new KindsCommand() );
		\WP_CLI::add_command( 'otherguise index sources', new SourcesCommand( $this->mentions() ) );
		\WP_CLI::add_command(
			'otherguise index build',
			new BuildCommand(
				new IndexBuilder( $this->topics(), $this->mentions() ),
				// The posts of a section come from the module that has sections (Books answers this filter): this module does not know it.
				static fn( $section ) => apply_filters( 'index_section_posts', array(), (int) $section ),
				static function ( $post, $strength ) {
					$link = '<a href="' . esc_url( (string) get_permalink( (int) $post ) ) . '">' . esc_html( get_the_title( (int) $post ) ) . '</a>';

					return 'main' === $strength ? '<strong>' . $link . '</strong>' : $link;
				}
			)
		);
		$photos = $this->photos();

		\WP_CLI::add_command(
			'otherguise index photos',
			new PhotosCommand(
				array( $photos, 'set_source' ),
				static function ( $post, $dry ) use ( $pages, $photos ) {
					if ( ! $dry ) {
						return $pages->process( $post );
					}

					$read = $pages->read( $post );

					return '' !== $read['error'] ? array(
						'ok'      => false,
						'message' => $read['error'],
					) : array(
						'ok'       => true,
						'message'  => 'Dry run: nothing was written.',
						'analysis' => $photos->explain( $post, $read['signals'] ),
					);
				},
				array( $photos, 'tick' ),
				array( $photos, 'status' )
			)
		);
		\WP_CLI::add_command(
			'otherguise index pages',
			new PagesCommand(
				array( $pages, 'job' ),
				array( $pages, 'process' ),
				array( $pages, 'set_source' )
			)
		);
	}

	/**
	 * Removes the options of the module. The terms and the statements stay (Triples keeps or deletes its data by its own setting); the
	 * mentions can be made again with `wp otherguise index rebuild`.
	 *
	 * @return void
	 */
	public function uninstall() {
		delete_option( self::OPTION );
		delete_option( PagesService::OPTION );
		delete_option( TopicsMigration::OPTION );
		delete_option( 'index_site_address' );
		foreach ( array( EnrichService::SWITCH, EnrichService::JOB, 'index_wikidata_contact', 'index_wikidata_last_request', 'index_wikidata_classes', 'index_wikidata_ancestors', 'index_enrich_failures', 'index_enrich_pause_until' ) as $option ) {
			delete_option( $option );
		}
		wp_clear_scheduled_hook( EnrichService::TICK_HOOK );
		wp_clear_scheduled_hook( PhotosService::TICK_HOOK );
		foreach ( array( PhotosService::SETTINGS, PhotosService::LAST, PhotosService::WAITING ) as $option ) {
			delete_option( $option );
		}
		delete_post_meta_by_key( PhotosService::PENDING );
		delete_post_meta_by_key( SignalStore::SIGNALS );
		delete_post_meta_by_key( SignalStore::IMAGES );
		delete_post_meta_by_key( SignalStore::HARVEST );
		wp_clear_scheduled_hook( PagesService::TICK_HOOK );
		wp_clear_scheduled_hook( PagesService::SINGLE_HOOK );
	}

	/**
	 * The name of the module, for the switches of the overview.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Index', 'otherguise' );
	}

	/**
	 * What the module does, for the switches of the overview.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Topics of an index (places, species, routes...) and the posts that mention them, from the categories and the tags.', 'otherguise' );
	}
}
