<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI: build an index.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Build\IndexBuilder;
use Otherguise\Index\Build\IndexRenderer;
use Otherguise\Index\Build\Selection;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index build` (slice 610): the index of some posts, as JSON, CSV or HTML. It reads the mentions and writes nothing.
 */
final class BuildCommand {
	/**
	 * The builder.
	 *
	 * @var IndexBuilder
	 */
	private $builder;

	/**
	 * Returns the posts of a section, in order: `fn( int $section ): int[]`.
	 *
	 * @var callable
	 */
	private $section_posts;

	/**
	 * Writes the locator of a post in HTML: `fn( int $post, string $strength ): string`.
	 *
	 * @var callable
	 */
	private $locator;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Stops with an error.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Builds the command.
	 *
	 * @param IndexBuilder  $builder       The builder.
	 * @param callable      $section_posts The posts of a section.
	 * @param callable      $locator       The locator of a post in HTML.
	 * @param callable|null $log           Default `WP_CLI::line`.
	 * @param callable|null $fail          Default `WP_CLI::error`.
	 */
	public function __construct( IndexBuilder $builder, $section_posts, $locator, $log = null, $fail = null ) {
		$this->builder       = $builder;
		$this->section_posts = $section_posts;
		$this->locator       = $locator;
		$this->log           = $log ?? array( 'WP_CLI', 'line' );
		$this->fail          = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Builds the index of a section or of some posts. Reads the mentions; writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--section=<id>]
	 * : A section of Books: its posts, in order (the sections inside it are followed; only published posts without a password).
	 *
	 * [--posts=<ids>]
	 * : Post ids separated by commas, in the order of the book. Instead of `--section`.
	 *
	 * [--include=<tokens>]
	 * : What is in: tokens `kind:place`, `source:image`, `visibility:print` separated by spaces or commas (a bare word is a kind). A value stands for its sub-kinds or sub-sources; inside one dimension the tokens are alternatives, across dimensions all are required. Everything by default.
	 *
	 * [--exclude=<tokens>]
	 * : What is out (the same tokens); wins over `--include`.
	 *
	 * [--format=<format>]
	 * : `json` (default), `csv` or `html`.
	 *
	 * [--rollup=<kinds>]
	 * : Give a line of their own to the broader topics of these kinds (`kind:place`, `kind:other`, or `all`: the kinds are listed by `wp otherguise index kinds list`), with the pages of everything below them (slice 613).
	 *
	 * [--no-context]
	 * : Do not show the broader topic in parentheses after a label.
	 *
	 * ## EXAMPLES
	 *
	 *     wp otherguise index build --section=58840 --format=csv > index.csv
	 *     wp otherguise index build --posts=12,15,19 --include="kind:place visibility:print" --exclude=kind:waterway --format=html
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc ) {
		unset( $args );

		$format = (string) ( $assoc['format'] ?? 'json' );

		if ( ! in_array( $format, array( 'json', 'csv', 'html' ), true ) ) {
			( $this->fail )( 'The format must be json, csv or html.' );

			return;
		}

		$posts = $this->posts( $assoc );

		if ( ! is_array( $posts ) ) {
			( $this->fail )( (string) $posts );

			return;
		}

		$selection = Selection::parse( (string) ( $assoc['include'] ?? '' ), (string) ( $assoc['exclude'] ?? '' ) );

		if ( array() !== $selection->errors() ) {
			( $this->fail )( implode( ' ', $selection->errors() ) );

			return;
		}

		if ( array() !== IndexBuilder::rollup_errors( (string) ( $assoc['rollup'] ?? '' ) ) ) {
			( $this->fail )( implode( ' ', IndexBuilder::rollup_errors( (string) ( $assoc['rollup'] ?? '' ) ) ) );

			return;
		}

		$model = $this->builder->build(
			$posts,
			array(
				'include' => (string) ( $assoc['include'] ?? '' ),
				'exclude' => (string) ( $assoc['exclude'] ?? '' ),
				'context' => empty( $assoc['no-context'] ),
				'rollup'  => (string) ( $assoc['rollup'] ?? '' ),
			)
		);

		if ( 'csv' === $format ) {
			( $this->log )( rtrim( IndexRenderer::to_csv( $model ), "\n" ) );
		} elseif ( 'html' === $format ) {
			( $this->log )( IndexRenderer::to_html( $model, $this->locator ) );
		} else {
			( $this->log )( (string) wp_json_encode( IndexRenderer::to_array( $model ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}
	}

	/**
	 * The posts asked for: a list of ids, or the reason it cannot be made.
	 *
	 * @param array<string, mixed> $assoc Options.
	 * @return int[]|string
	 */
	private function posts( array $assoc ) {
		if ( isset( $assoc['section'] ) === isset( $assoc['posts'] ) ) {
			return 'Give a section (--section=<id>) or some posts (--posts=<ids>), not both.';
		}

		if ( isset( $assoc['posts'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $assoc['posts'] ) ) ) );

			return array() === $ids ? 'No post id in --posts.' : $ids;
		}

		$ids = (array) ( $this->section_posts )( absint( $assoc['section'] ) );

		return array() === $ids ? 'No post for this section: it is empty, it is not a section, or the Books module is not active.' : array_map( 'intval', $ids );
	}
}
