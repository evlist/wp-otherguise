<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Moves the variants of a theme to another theme.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Variant;

use Otherguise\Modes\Template\TemplateRef;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Service\InvalidStatementException;

defined( 'ABSPATH' ) || exit;

/**
 * The templates are known by `theme//slug`, so the variants declared for a theme point at nothing once another theme (a child theme, for
 * example) is active. This declares each relation again, with the same modes, between the templates of the other theme that have the same
 * slugs, then withdraws the old one. A relation is withdrawn only after the new one is declared, so nothing is lost when the new templates do
 * not exist yet; running it again finds nothing more to do.
 */
final class Retheme {
	/**
	 * Variants.
	 *
	 * @var Variants
	 */
	private $variants;

	/**
	 * Builds the service.
	 *
	 * @param Variants $variants Variants.
	 */
	public function __construct( Variants $variants ) {
		$this->variants = $variants;
	}

	/**
	 * Runs it.
	 *
	 * @param string        $from    Theme (stylesheet) of the relations to move.
	 * @param string        $to      Theme to move them to.
	 * @param bool          $dry_run Whether to write nothing.
	 * @param callable|null $each    Called with a line of text per relation.
	 * @return array{moved:int, skipped:string[]} Relations moved (or that would be), and the reasons for those that were not.
	 */
	public function run( $from, $to, $dry_run = false, $each = null ) {
		$report = array(
			'moved'   => 0,
			'skipped' => array(),
		);

		foreach ( array( TemplateRef::TEMPLATE, TemplateRef::PART ) as $kind ) {
			foreach ( $this->variants->relations( $kind ) as $relation ) {
				$source  = $relation['source'];
				$variant = $relation['variant'];
				$source_in  = TemplateRef::theme( $source->id() ) === $from;
				$variant_in = TemplateRef::theme( $variant->id() ) === $from;

				if ( ! $source_in && ! $variant_in ) {
					continue;
				}

				$label = $kind . ' ' . $source->id() . ' -> ' . $variant->id() . ' (' . implode( ', ', $relation['modes'] ) . ')';

				if ( ! $source_in || ! $variant_in ) {
					$report['skipped'][] = $label . ': the two ends are not of the same theme';
					continue;
				}

				$new_source  = new EntityRef( $kind, $to . '//' . TemplateRef::slug( $source->id() ) );
				$new_variant = new EntityRef( $kind, $to . '//' . TemplateRef::slug( $variant->id() ) );

				if ( $dry_run ) {
					++$report['moved'];
					$this->say( $each, $label . ': would become ' . $new_source->id() . ' -> ' . $new_variant->id() );
					continue;
				}

				try {
					foreach ( $relation['modes'] as $mode ) {
						$this->variants->declare( $new_source, new EntityRef( 'mode', $mode ), $new_variant );
					}
				} catch ( VariantException | InvalidStatementException $problem ) {
					$report['skipped'][] = $label . ': ' . $problem->getMessage();
					continue;
				}

				$this->variants->remove( $source, $variant );
				++$report['moved'];
				$this->say( $each, $label . ': now ' . $new_source->id() . ' -> ' . $new_variant->id() );
			}
		}

		return $report;
	}

	/**
	 * Sends a line to the callback, if any.
	 *
	 * @param callable|null $each Callback.
	 * @param string        $line Line.
	 * @return void
	 */
	private function say( $each, $line ) {
		if ( null !== $each ) {
			$each( $line );
		}
	}
}
