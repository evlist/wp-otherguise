<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Plugin Name: Otherguise slice 201 experiment
 * Description: Throw-away must-use plugin that tries four ways of replacing a template by its variant in a given mode. It is NOT part of the Otherguise plugin: see README.md.
 *
 * @package Otherguise
 */

// phpcs:ignoreFile -- an experiment run on a scratch WordPress, outside the quality rules of the plugin.

/**
 * Whether the experiment's mode is active: `?mode=print`.
 *
 * @return bool
 */
function og201_mode() {
	return isset( $_GET['mode'] ) && 'print' === $_GET['mode'];
}

/**
 * Strategy chosen by `?og_strategy=` : B (template hierarchy), A (get_block_templates, the hack), C (template_include and globals), none.
 *
 * @return string
 */
function og201_strategy() {
	return isset( $_GET['og_strategy'] ) ? (string) $_GET['og_strategy'] : 'B';
}

/**
 * Variants: slug of a template or template part => slug of its variant, from the option `og201_variants` (JSON).
 *
 * @return array<string, string>
 */
function og201_variants() {
	$variants = json_decode( (string) get_option( 'og201_variants', '{}' ), true );

	return is_array( $variants ) ? $variants : array();
}

$GLOBALS['og201'] = array(
	'hierarchies'        => array(),
	'block_template_calls' => array(),
	'part_swaps'         => array(),
);

// Strategy B: the template hierarchy of every type that core documents, in WordPress 7.1 (template.php, get_query_template()).
foreach ( array( '404', 'archive', 'attachment', 'author', 'category', 'date', 'embed', 'frontpage', 'home', 'index', 'page', 'paged', 'privacypolicy', 'search', 'single', 'singular', 'tag', 'taxonomy' ) as $og201_type ) {
	add_filter(
		$og201_type . '_template_hierarchy',
		function ( $templates ) use ( $og201_type ) {
			$GLOBALS['og201']['hierarchies'][] = $og201_type . ': ' . implode( ' ', $templates );

			if ( ! og201_mode() || 'B' !== og201_strategy() ) {
				return $templates;
			}

			$variants = og201_variants();
			$result   = array();

			foreach ( $templates as $candidate ) {
				$slug = preg_replace( '/\.(php|html)$/', '', $candidate );

				if ( isset( $variants[ $slug ] ) ) {
					$result[] = $variants[ $slug ] . ( $slug === $candidate ? '' : substr( $candidate, strlen( $slug ) ) );
				}

				$result[] = $candidate;
			}

			return $result;
		}
	);
}

// Strategy A: what the hack of wp-pdf-helper does, with the variants of the option instead of "-print".
add_filter(
	'get_block_templates',
	function ( $templates, $query, $template_type ) {
		$GLOBALS['og201']['block_template_calls'][] = $template_type . ' ' . wp_json_encode( $query ) . ' front=' . ( is_admin() || wp_is_json_request() ? 'no' : 'yes' );

		if ( ! og201_mode() || 'A' !== og201_strategy() || 'wp_template' !== $template_type || empty( $templates ) ) {
			return $templates;
		}

		$variants = og201_variants();
		$first    = $templates[0];

		if ( isset( $variants[ $first->slug ] ) ) {
			$variant = get_block_template( get_stylesheet() . '//' . $variants[ $first->slug ] );

			if ( $variant ) {
				$templates[0] = $variant;
			}
		}

		return $templates;
	},
	10,
	3
);

// Strategy C: after core has chosen, replace the two globals that the template canvas reads.
add_filter(
	'template_include',
	function ( $template ) {
		global $_wp_current_template_id, $_wp_current_template_content;

		if ( og201_mode() && 'C' === og201_strategy() && $_wp_current_template_id ) {
			$variants = og201_variants();
			$slug     = substr( $_wp_current_template_id, strpos( $_wp_current_template_id, '//' ) + 2 );

			if ( isset( $variants[ $slug ] ) ) {
				$variant = get_block_template( get_stylesheet() . '//' . $variants[ $slug ] );

				if ( $variant ) {
					$_wp_current_template_id      = $variant->id;
					$_wp_current_template_content = $variant->content;
				}
			}
		}

		return $template;
	},
	99
);

// Strategy D, for template parts (switched off with ?og_strategy=A, to show what A alone does): the block "core/template-part" renders from the database or the theme file by slug, without get_block_templates().
add_filter(
	'render_block_data',
	function ( $parsed_block ) {
		if ( og201_mode() && 'A' !== og201_strategy() && 'core/template-part' === $parsed_block['blockName'] && isset( $parsed_block['attrs']['slug'] ) ) {
			$variants = og201_variants();
			$slug     = $parsed_block['attrs']['slug'];

			if ( isset( $variants[ $slug ] ) ) {
				$GLOBALS['og201']['part_swaps'][]    = $slug . ' -> ' . $variants[ $slug ];
				$parsed_block['attrs']['slug']       = $variants[ $slug ];
			}
		}

		return $parsed_block;
	}
);

// Report what happened, in an HTML comment at the end of the page.
add_action(
	'wp_footer',
	function () {
		global $_wp_current_template_id;

		echo "\n<!-- og201 " . wp_json_encode(
			array(
				'strategy'    => og201_strategy(),
				'mode'        => og201_mode(),
				'template_id' => $_wp_current_template_id ?? null,
				'hierarchies' => $GLOBALS['og201']['hierarchies'],
				'block_template_calls' => $GLOBALS['og201']['block_template_calls'],
				'part_swaps'  => $GLOBALS['og201']['part_swaps'],
				'stylesheet'  => get_stylesheet(),
			)
		) . " -->\n";
	},
	9999
);
