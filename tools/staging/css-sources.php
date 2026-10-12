<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Run by tools/staging/css-sources.sh through `wp eval`, on the site: where is the CSS that the templates of a theme need? It takes the
 * classes that the templates and the parts of the theme use, gathers every place that can hold CSS (the additional CSS of each theme, the
 * global styles, the snippets of the plugin Custom CSS & JS, the CSS files of the plugins, of the themes, of Custom CSS & JS and of WordPress) and says,
 * for each class and for each extra term, where it is found. Read-only. The variables $otherguise_theme and $otherguise_find come from
 * css-sources.sh.
 *
 * @package Otherguise
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the classes that the templates and the parts of a theme use, except those of WordPress (wp-, has-, is-, align).
 *
 * @param string $dir Directory of the theme.
 * @return string[]
 */
function otherguise_css_sources_classes( $dir ) {
	$classes = array();

	foreach ( array_merge( (array) glob( $dir . '/templates/*.html' ), (array) glob( $dir . '/parts/*.html' ) ) as $file ) {
		$content = (string) file_get_contents( $file );

		preg_match_all( '/class="([^"]+)"/', $content, $in_html );
		preg_match_all( '/"className":"([^"]+)"/', $content, $in_blocks );

		foreach ( array_merge( $in_html[1], $in_blocks[1] ) as $list ) {
			foreach ( preg_split( '/\s+/', trim( $list ) ) as $class ) {
				if ( '' !== $class && 1 !== preg_match( '/^(wp-|has-|is-|align)/', $class ) ) {
					$classes[ $class ] = true;
				}
			}
		}
	}

	$names = array_keys( $classes );
	sort( $names );

	return $names;
}

/**
 * Returns the places that can hold CSS, with their text, by label.
 *
 * @return array<string, string>
 */
function otherguise_css_sources_places() {
	$places = array();
	$any    = array( 'publish', 'draft', 'private', 'pending', 'future' );

	foreach ( get_posts(
		array(
			'post_type' => 'custom_css',
			'post_status' => $any,
			'posts_per_page' => -1,
		)
	) as $post ) {
		$places[ 'additional CSS of the theme ' . $post->post_name . ' (post ' . $post->ID . ')' ] = $post->post_content;
	}

	foreach ( get_posts(
		array(
			'post_type' => 'wp_global_styles',
			'post_status' => $any,
			'posts_per_page' => -1,
		)
	) as $post ) {
		$data = json_decode( $post->post_content, true );
		$css  = is_array( $data ) && isset( $data['styles']['css'] ) ? (string) $data['styles']['css'] : '';

		if ( '' !== $css ) {
			$places[ 'global styles ' . $post->post_name . ' (post ' . $post->ID . ')' ] = $css;
		}
	}

	foreach ( get_posts(
		array(
			'post_type' => 'custom-css-js',
			'post_status' => $any,
			'posts_per_page' => -1,
		)
	) as $post ) {
		$options = get_post_meta( $post->ID, 'options', true );
		$kind    = is_array( $options ) ? ( $options['language'] ?? '?' ) . ', ' . ( $options['side'] ?? '?' ) . ', ' . ( $options['type'] ?? '?' ) : '?';
		$places[ 'Custom CSS & JS snippet ' . $post->ID . ' "' . $post->post_title . '" (' . $kind . ', ' . $post->post_status . ')' ] = $post->post_content;
	}

	$roots = array( WP_PLUGIN_DIR, get_theme_root(), wp_upload_dir()['basedir'] . '/custom-css-js', ABSPATH . WPINC . '/css' );

	foreach ( $roots as $root ) {
		if ( ! is_dir( $root ) ) {
			continue;
		}

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS ) );

		foreach ( $files as $file ) {
			if ( 'css' === strtolower( $file->getExtension() ) && $file->getSize() < 2000000 && false === strpos( $file->getPathname(), '/node_modules/' ) ) {
				$places[ 'file ' . str_replace( array( WP_CONTENT_DIR . '/', ABSPATH ), '', $file->getPathname() ) ] = (string) file_get_contents( $file->getPathname() );
			}
		}
	}

	return $places;
}

/**
 * Reports.
 *
 * @param string   $theme Slug of the theme whose templates are looked at.
 * @param string[] $find  Extra terms to look for (a font name, for instance).
 * @return void
 */
function otherguise_css_sources_run( $theme, array $find ) {
	$object = wp_get_theme( $theme );

	if ( ! $object->exists() ) {
		WP_CLI::error( 'The theme ' . $theme . ' is not installed.' );
	}

	$classes = otherguise_css_sources_classes( $object->get_stylesheet_directory() );
	$places  = otherguise_css_sources_places();

	WP_CLI::line( 'Theme: ' . $theme . ' (active: ' . get_stylesheet() . '); ' . count( $classes ) . ' classes in its templates and parts; ' . count( $places ) . ' places that can hold CSS looked at.' );
	WP_CLI::line( '' );
	WP_CLI::line( 'Classes of the templates, and where they are styled:' );

	foreach ( $classes as $class ) {
		$where = array();

		foreach ( $places as $label => $css ) {
			if ( 1 === preg_match( '/\.' . preg_quote( $class, '/' ) . '(?![\w-])/', $css ) ) {
				$where[] = $label;
			}
		}

		WP_CLI::line( '  ' . $class . ' => ' . ( array() === $where ? '-- NOT FOUND' : implode( '; ', $where ) ) );
	}

	if ( array() !== $find ) {
		WP_CLI::line( '' );
		WP_CLI::line( 'Extra terms, and where they are found:' );

		foreach ( $find as $term ) {
			$where = array();

			foreach ( $places as $label => $css ) {
				if ( false !== stripos( $css, $term ) ) {
					$where[] = $label;
				}
			}

			WP_CLI::line( '  ' . $term . ' => ' . ( array() === $where ? '-- NOT FOUND' : implode( '; ', $where ) ) );
		}
	}

	WP_CLI::line( '' );
	WP_CLI::line( 'Snippets of the plugin Custom CSS & JS (all of them, with their settings):' );

	$snippets = get_posts(
		array(
			'post_type' => 'custom-css-js',
			'post_status' => 'any',
			'posts_per_page' => -1,
		)
	);

	foreach ( $snippets as $post ) {
		$options = get_post_meta( $post->ID, 'options', true );
		$options = is_array( $options ) ? $options : array();

		WP_CLI::line( '  ' . $post->ID . ' "' . $post->post_title . '": ' . ( $options['language'] ?? '?' ) . ', ' . ( $options['side'] ?? '?' ) . ', ' . ( $options['type'] ?? '?' ) . ', priority ' . ( $options['priority'] ?? '?' ) . ', ' . $post->post_status );
	}

	if ( array() === $snippets ) {
		WP_CLI::line( '  (none)' );
	}

	$families = array();

	foreach ( get_posts(
		array(
			'post_type' => 'wp_font_family',
			'post_status' => 'any',
			'posts_per_page' => -1,
		)
	) as $post ) {
		$families[] = $post->post_title;
	}

	WP_CLI::line( '' );
	WP_CLI::line( 'Fonts of the font library of WordPress: ' . ( array() === $families ? '(none)' : implode( ', ', $families ) ) );
}

otherguise_css_sources_run( $otherguise_theme, $otherguise_find );
