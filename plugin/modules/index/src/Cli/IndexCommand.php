<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI: the index.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Entries;
use Otherguise\Index\Indexer;
use Otherguise\Index\Kinds;
use Otherguise\Index\Mentions;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index rebuild|kind|entries|show|link` (slice 600). Every command that writes can be run twice; `rebuild` has `--dry-run`.
 */
final class IndexCommand {
	public const BATCH = 100;

	/**
	 * The store of the entries.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * The indexer.
	 *
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * The mentions.
	 *
	 * @var Mentions
	 */
	private $mentions;

	/**
	 * Returns the ids of the published posts: `fn( int $offset, int $limit ): int[]`.
	 *
	 * @var callable
	 */
	private $posts;

	/**
	 * Sets the kind of a term: `fn( string $taxonomy, string $term, string $kind ): bool|string` (true, or the reason it failed).
	 *
	 * @var callable
	 */
	private $set_term_kind;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Prints a success.
	 *
	 * @var callable
	 */
	private $success;

	/**
	 * Stops with an error.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Builds the command.
	 *
	 * @param Entries       $entries       Entries.
	 * @param Indexer       $indexer       Indexer.
	 * @param Mentions      $mentions      Mentions.
	 * @param callable      $posts         Ids of the published posts, by page.
	 * @param callable      $set_term_kind Sets the kind of a term.
	 * @param callable|null $log           Default `WP_CLI::log`.
	 * @param callable|null $success       Default `WP_CLI::success`.
	 * @param callable|null $fail          Default `WP_CLI::error`.
	 */
	public function __construct( Entries $entries, Indexer $indexer, Mentions $mentions, $posts, $set_term_kind, $log = null, $success = null, $fail = null ) {
		$this->entries       = $entries;
		$this->indexer       = $indexer;
		$this->mentions      = $mentions;
		$this->posts         = $posts;
		$this->set_term_kind = $set_term_kind;
		$this->log           = $log ?? array( 'WP_CLI', 'log' );
		$this->success       = $success ?? array( 'WP_CLI', 'success' );
		$this->fail          = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Reads the published posts through the sources and brings the mentions in line. Can be run twice.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<slug>]
	 * : Only this source (`categories` or `tags`).
	 *
	 * [--post=<id>]
	 * : Only this post.
	 *
	 * [--dry-run]
	 * : Reports what would be created, resolved and left ambiguous; writes nothing (the mentions are not counted).
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise index rebuild --dry-run
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function rebuild( $args, $assoc ) {
		unset( $args );

		$only = isset( $assoc['source'] ) ? array( (string) $assoc['source'] ) : null;
		$dry  = ! empty( $assoc['dry-run'] );

		if ( null !== $only && ! in_array( $only[0], $this->indexer->source_ids(), true ) ) {
			( $this->fail )( 'Unknown source "' . $only[0] . '" (' . implode( ', ', $this->indexer->source_ids() ) . ').' );

			return;
		}

		$total = array(
			'created'   => 0,
			'resolved'  => 0,
			'ambiguous' => 0,
			'added'     => 0,
			'removed'   => 0,
		);
		$count = 0;

		if ( isset( $assoc['post'] ) ) {
			$ids = array( (int) $assoc['post'] );
		} else {
			$ids = null;
		}

		$offset = 0;
		$more   = true;

		while ( $more ) {
			$batch = null === $ids ? (array) ( $this->posts )( $offset, self::BATCH ) : $ids;

			foreach ( $batch as $id ) {
				foreach ( $this->indexer->index_post( (int) $id, $only, $dry ) as $name => $number ) {
					$total[ $name ] += $number;
				}

				++$count;
			}

			$offset += self::BATCH;
			$more    = null === $ids && count( $batch ) === self::BATCH;
		}

		( $this->log )( ( $dry ? 'Dry run: ' : '' ) . $count . ' post(s) read.' );

		foreach ( $dry ? array( 'created', 'resolved', 'ambiguous' ) : array_keys( $total ) as $name ) {
			( $this->log )( '  ' . $name . ': ' . $total[ $name ] );
		}

		( $this->success )( $dry ? 'Nothing was written.' : 'The mentions are up to date.' );
	}

	/**
	 * Sets the kind of a category or a tag; the term is then indexed (use `none` to take it out). Can be run twice.
	 *
	 * ## OPTIONS
	 *
	 * <taxonomy>
	 * : `category` or `post_tag`.
	 *
	 * <term>
	 * : The id, the slug or the name of the term.
	 *
	 * <kind>
	 * : A kind (`place`, `species`...), or `none`.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function kind( $args, $assoc ) {
		unset( $assoc );

		list( $taxonomy, $term, $kind ) = array_pad( $args, 3, '' );

		if ( 'none' !== $kind && ! Kinds::is_kind( $kind ) ) {
			( $this->fail )( 'Unknown kind "' . $kind . '" (' . implode( ', ', array_keys( Kinds::all() ) ) . ', none).' );

			return;
		}

		$done = ( $this->set_term_kind )( (string) $taxonomy, (string) $term, 'none' === $kind ? '' : (string) $kind );

		if ( true !== $done ) {
			( $this->fail )( (string) $done );

			return;
		}

		( $this->success )( 'The kind of ' . $taxonomy . ' ' . $term . ' is ' . $kind . '. Run `wp otherguise index rebuild` to bring the mentions in line.' );
	}

	/**
	 * Lists the entries.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : `approved`, `candidate` (the ambiguous ones to resolve) or `refused`.
	 *
	 * [--kind=<kind>]
	 * : Only this kind.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function entries( $args, $assoc ) {
		unset( $args );

		$filters = array();

		foreach ( array( 'status', 'kind' ) as $name ) {
			if ( isset( $assoc[ $name ] ) ) {
				$filters[ $name ] = (string) $assoc[ $name ];
			}
		}

		foreach ( $this->entries->all( $filters ) as $entry ) {
			( $this->log )( $entry['id'] . "\t" . $entry['status'] . "\t" . $entry['kind'] . "\t" . $entry['label'] . ( $entry['parent'] > 0 ? ' (under ' . $entry['parent'] . ')' : '' ) . "\t" . implode( ' ', $entry['keys'] ) );
		}
	}

	/**
	 * Shows the entries a post mentions.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : The id of the post.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function show( $args, $assoc ) {
		unset( $assoc );

		foreach ( $this->mentions->of_post( (int) ( $args[0] ?? 0 ) ) as $id => $mention ) {
			$entry = $this->entries->get( $id );

			( $this->log )( $id . "\t" . ( null === $entry ? '?' : $entry['label'] ) . "\t" . $mention['strength'] . "\t" . implode( ',', $mention['sources'] ) );
		}
	}

	/**
	 * Links a key to an entry: the key is added to the aliases of the entry, so the sources will find it there. Settles an ambiguous
	 * candidate. Can be run twice.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The key (`term:post_tag:12`, `wikidata:Q42`...).
	 *
	 * <entry>
	 * : The id of the entry.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function link( $args, $assoc ) {
		unset( $assoc );

		$key   = (string) ( $args[0] ?? '' );
		$entry = (int) ( $args[1] ?? 0 );

		if ( '' === $key || null === $this->entries->get( $entry ) ) {
			( $this->fail )( 'Give a key and the id of an existing entry.' );

			return;
		}

		$this->entries->add_keys( $entry, array( $key ) );

		foreach ( $this->entries->with_key( 'ambiguous:' . $key ) as $pending ) {
			$this->entries->delete( $pending );
		}

		( $this->success )( 'The key ' . $key . ' is an alias of the entry ' . $entry . '. Run `wp otherguise index rebuild` to make the mentions.' );
	}
}
