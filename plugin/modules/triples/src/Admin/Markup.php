<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Small pieces of escaped HTML shared by the screens.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the display data of `StatementsView` into HTML. Everything that comes from the data is escaped here.
 */
final class Markup {
	/**
	 * Returns the HTML of an end of a statement.
	 *
	 * @param array<string, mixed> $node Entity or literal, as given by `StatementsView`.
	 * @return string
	 */
	public static function node( array $node ) {
		if ( ! empty( $node['literal'] ) ) {
			return '<code>' . esc_html( (string) $node['label'] ) . '</code> <small>' . esc_html( (string) $node['datatype'] ) . '</small>';
		}

		$label = esc_html( (string) $node['label'] );

		if ( ! empty( $node['url'] ) && empty( $node['missing'] ) ) {
			$label = '<a href="' . esc_url( (string) $node['url'] ) . '">' . $label . '</a>';
		}

		if ( ! empty( $node['missing'] ) ) {
			$label = '<span class="triples-missing" style="color:#b32d2e">' . $label . '</span> <strong>' . esc_html__( '(missing)', 'triples' ) . '</strong>';
		}

		return $label . ( (string) $node['label'] === (string) $node['raw'] ? '' : ' <small><code>' . esc_html( (string) $node['raw'] ) . '</code></small>' );
	}

	/**
	 * Returns the HTML of a predicate.
	 *
	 * @param array<string, mixed> $predicate Predicate, as given by `StatementsView`.
	 * @return string
	 */
	public static function predicate( array $predicate ) {
		$html = esc_html( (string) $predicate['label'] );

		if ( (string) $predicate['label'] !== (string) $predicate['slug'] ) {
			$html .= ' <small><code>' . esc_html( (string) $predicate['slug'] ) . '</code></small>';
		}

		if ( empty( $predicate['registered'] ) ) {
			$html .= ' <strong>' . esc_html__( '(not registered)', 'triples' ) . '</strong>';
		}

		return $html;
	}

	/**
	 * Returns the HTML of a list of types.
	 *
	 * @param string[] $types Types; an empty list means any entity.
	 * @return string
	 */
	public static function types( array $types ) {
		return array() === $types ? esc_html__( 'any entity', 'triples' ) : '<code>' . implode( '</code>, <code>', array_map( 'esc_html', $types ) ) . '</code>';
	}

	/**
	 * Returns the HTML of a list of predicate slugs.
	 *
	 * @param string[] $slugs Slugs; may be empty.
	 * @return string
	 */
	public static function slugs( array $slugs ) {
		return array() === $slugs ? '' : '<code>' . implode( '</code>, <code>', array_map( 'esc_html', $slugs ) ) . '</code>';
	}

	/**
	 * Returns the HTML of a yes or a no.
	 *
	 * @param bool $value Value.
	 * @return string
	 */
	public static function yes_no( $value ) {
		return $value ? esc_html__( 'yes', 'triples' ) : esc_html__( 'no', 'triples' );
	}
}
