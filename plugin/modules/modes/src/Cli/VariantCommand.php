<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * WP-CLI commands `wp otherguise modes variant ...`.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Cli;

use Otherguise\Modes\Template\TemplateRef;
use Otherguise\Modes\Variant\Retheme;
use Otherguise\Modes\Variant\Variants;

defined( 'ABSPATH' ) || exit;

/**
 * The variants of the templates and of the template parts.
 */
final class VariantCommand {
	/**
	 * Gives the variants, or null when the modules are not available.
	 *
	 * @var callable
	 */
	private $variants;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Stops with an error message.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Builds the command.
	 *
	 * @param callable      $variants Returns the Variants, or null when the Triples module is not enabled.
	 * @param callable|null $log      Prints a line; defaults to `WP_CLI::log`.
	 * @param callable|null $fail     Stops with a message; defaults to `WP_CLI::error`.
	 */
	public function __construct( $variants, $log = null, $fail = null ) {
		$this->variants = $variants;
		$this->log      = $log ?? array( 'WP_CLI', 'log' );
		$this->fail     = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Lists the variants: one line per relation, "kind, template, variant, modes", separated by tabs.
	 *
	 * ## OPTIONS
	 *
	 * [--theme=<theme>]
	 * : Only the relations of that theme (the stylesheet, such as `twentytwentyfive`).
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		unset( $args );

		$variants = $this->variants();
		$theme    = isset( $assoc_args['theme'] ) ? (string) $assoc_args['theme'] : '';

		if ( null === $variants ) {
			return;
		}

		foreach ( array( TemplateRef::TEMPLATE, TemplateRef::PART ) as $kind ) {
			foreach ( $variants->relations( $kind ) as $relation ) {
				if ( '' !== $theme && TemplateRef::theme( $relation['source']->id() ) !== $theme && TemplateRef::theme( $relation['variant']->id() ) !== $theme ) {
					continue;
				}

				( $this->log )( implode( "\t", array( $kind, $relation['source']->id(), $relation['variant']->id(), implode( ',', $relation['modes'] ) ) ) );
			}
		}
	}

	/**
	 * Moves the variants declared for a theme to another theme.
	 *
	 * The templates are known by `theme//slug`, so the variants declared for Twenty Twenty-Five point at nothing once a child theme is
	 * active. Each relation is declared again, with the same modes, between the templates of the other theme that have the same slugs
	 * (they must exist: activate the theme first), then withdrawn from the old one. It can be run twice.
	 *
	 * ## OPTIONS
	 *
	 * <from>
	 * : The theme the variants are declared for.
	 *
	 * <to>
	 * : The theme to move them to.
	 *
	 * [--dry-run]
	 * : Write nothing, say what would be done.
	 *
	 * @param array $args       The two themes.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function retheme( $args, $assoc_args ) {
		$variants = $this->variants();

		if ( null === $variants ) {
			return;
		}

		if ( 2 !== count( $args ) || ! TemplateRef::is_valid_id( $args[0] . '//x' ) || ! TemplateRef::is_valid_id( $args[1] . '//x' ) ) {
			( $this->fail )( 'Give the two themes: wp otherguise modes variant retheme <from> <to>.' );
			return;
		}

		if ( $args[0] === $args[1] ) {
			( $this->fail )( 'The two themes are the same.' );
			return;
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );
		$report  = ( new Retheme( $variants ) )->run( $args[0], $args[1], $dry_run, $this->log );

		foreach ( $report['skipped'] as $skipped ) {
			( $this->log )( 'Skipped, ' . $skipped );
		}

		( $this->log )(
			sprintf(
				'%s: %d relation(s) %s from %s to %s, %d skipped.',
				$dry_run ? 'Dry run, nothing written' : 'Done',
				$report['moved'],
				$dry_run ? 'to move' : 'moved',
				$args[0],
				$args[1],
				count( $report['skipped'] )
			)
		);

		if ( array() !== $report['skipped'] ) {
			( $this->fail )( 'Some relations were not moved: the templates of ' . $args[1] . ' with the same slugs must exist (activate the theme first).' );
		}
	}

	/**
	 * Returns the variants, or says that the Triples module is not enabled (WP-CLI then stops).
	 *
	 * @return Variants|null
	 */
	private function variants() {
		$variants = ( $this->variants )();

		if ( ! $variants instanceof Variants ) {
			( $this->fail )( 'The modules Triples and Modes must be enabled.' );

			return null;
		}

		return $variants;
	}
}
